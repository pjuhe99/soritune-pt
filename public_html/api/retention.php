<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coach_mapping.php';
require_once __DIR__ . '/../includes/retention_calc.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db    = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'snapshots':
        // base_month 리스트 + total_coaches(집계) + total_new + calculated_at
        $stmt = $db->query("
            SELECT r.base_month,
                   r.total_new,
                   r.calculated_at AS last_calculated_at,
                   COALESCE(s.total_coaches, 0) AS total_coaches
              FROM coach_retention_runs r
              LEFT JOIN (
                SELECT base_month, COUNT(*) AS total_coaches
                  FROM coach_retention_scores
                 GROUP BY base_month
              ) s ON s.base_month = r.base_month
             ORDER BY r.base_month DESC
        ");
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['total_new']     = (int)$r['total_new'];
            $r['total_coaches'] = (int)$r['total_coaches'];
        }
        unset($r);
        jsonSuccess(['snapshots' => $rows]);

    case 'view':
        $baseMonth = $_GET['base_month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
            jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
        }

        $runStmt = $db->prepare("
            SELECT base_month, total_new, unmapped_coaches, calculated_at
              FROM coach_retention_runs
             WHERE base_month = ?
        ");
        $runStmt->execute([$baseMonth]);
        $run = $runStmt->fetch();
        if (!$run) {
            jsonError('해당 기준월 스냅샷이 없습니다', 404);
        }

        $rows = _fetchSnapshotRows($db, $baseMonth);
        $unmapped = $run['unmapped_coaches']
            ? json_decode($run['unmapped_coaches'], true)
            : ['pt_only' => [], 'coach_site_only' => []];

        $sumAuto  = array_sum(array_column($rows, 'auto_allocation'));
        $sumFinal = array_sum(array_column($rows, 'final_allocation'));

        jsonSuccess([
            'base_month'       => $baseMonth,
            'total_new'        => (int)$run['total_new'],
            'rows'             => $rows,
            'unmapped_coaches' => $unmapped,
            'summary'          => [
                'total_new'   => (int)$run['total_new'],
                'sum_auto'    => (int)$sumAuto,
                'sum_final'   => (int)$sumFinal,
                'unallocated' => (int)$run['total_new'] - (int)$sumFinal,
            ],
        ]);

    case 'calculate':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('POST only', 405);
        }
        $input = getJsonInput();
        $baseMonth = $input['base_month'] ?? '';
        $totalNew  = (int)($input['total_new'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
            jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
        }
        if ($totalNew < 0 || $totalNew > 10000) {
            jsonError('전체 신규 인원은 0 ~ 10000 사이여야 합니다');
        }

        try {
            $result = calculateRetention($db, $baseMonth, $totalNew, (int)$admin['id']);
        } catch (PDOException $e) {
            jsonError('coach 사이트 DB 접근 실패: ' . $e->getMessage(), 500);
        } catch (Throwable $e) {
            jsonError('계산 오류: ' . $e->getMessage(), 500);
        }

        jsonSuccess(array_merge(['base_month' => $baseMonth], $result));

    case 'update_allocation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('POST only', 405);
        }
        $input = getJsonInput();
        $id                  = (int)($input['id'] ?? 0);
        $finalAllocation     = (int)($input['final_allocation'] ?? -1);
        $expectedUpdatedAt   = $input['expected_updated_at'] ?? '';

        if ($id <= 0) jsonError('id가 필요합니다');
        if ($finalAllocation < 0 || $finalAllocation > 9999) {
            jsonError('final_allocation은 0 ~ 9999 사이여야 합니다');
        }
        if ($expectedUpdatedAt === '') {
            jsonError('expected_updated_at이 필요합니다');
        }

        // Load current row for old_value + compare
        $current = $db->prepare("
            SELECT id, base_month, auto_allocation, final_allocation, updated_at
              FROM coach_retention_scores
             WHERE id = ?
        ");
        $current->execute([$id]);
        $row = $current->fetch();
        if (!$row) jsonError('행을 찾을 수 없습니다', 404);

        // Optimistic lock check (§9.5)
        if ($row['updated_at'] !== $expectedUpdatedAt) {
            // Conflict: return current server state
            $latest = _fetchRowById($db, $id);
            jsonSuccess([
                'ok'   => false,
                'code' => 'conflict',
                'row'  => $latest,
            ]);
        }

        $db->beginTransaction();
        try {
            $upd = $db->prepare("
                UPDATE coach_retention_scores
                   SET final_allocation = ?,
                       adjusted_by = ?,
                       adjusted_at = NOW()
                 WHERE id = ? AND updated_at = ?
            ");
            $upd->execute([
                $finalAllocation, (int)$admin['id'], $id, $expectedUpdatedAt
            ]);
            $affected = $upd->rowCount();

            if ($affected === 0) {
                $db->rollBack();
                $latest = _fetchRowById($db, $id);
                jsonSuccess([
                    'ok'   => false,
                    'code' => 'conflict',
                    'row'  => $latest,
                ]);
            }

            logChange(
                $db, 'retention_allocation', $id, 'final_allocation_update',
                ['final_allocation' => (int)$row['final_allocation']],
                ['final_allocation' => $finalAllocation],
                'admin', (int)$admin['id']
            );

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            jsonError('저장 실패: ' . $e->getMessage(), 500);
        }

        $latest = _fetchRowById($db, $id);

        // summary recomputed on server for authoritative value
        $sumStmt = $db->prepare("
            SELECT SUM(auto_allocation) AS sa, SUM(final_allocation) AS sf
              FROM coach_retention_scores WHERE base_month = ?
        ");
        $sumStmt->execute([$row['base_month']]);
        $sums = $sumStmt->fetch();

        $runStmt = $db->prepare("
            SELECT total_new FROM coach_retention_runs WHERE base_month = ?
        ");
        $runStmt->execute([$row['base_month']]);
        $totalNew = (int)($runStmt->fetchColumn() ?: 0);

        jsonSuccess([
            'ok'  => true,
            'row' => $latest,
            'summary' => [
                'total_new'   => $totalNew,
                'sum_auto'    => (int)$sums['sa'],
                'sum_final'   => (int)$sums['sf'],
                'unallocated' => $totalNew - (int)$sums['sf'],
            ],
        ]);

    case 'reset_allocation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('POST only', 405);
        }
        $input = getJsonInput();
        $baseMonth = $input['base_month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
            jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE coach_retention_scores
                   SET final_allocation = auto_allocation,
                       adjusted_by = ?,
                       adjusted_at = NOW()
                 WHERE base_month = ?
                   AND final_allocation <> auto_allocation
            ");
            $stmt->execute([(int)$admin['id'], $baseMonth]);
            $affected = $stmt->rowCount();

            logChange(
                $db, 'retention_allocation', 0, 'reset_all',
                null, ['base_month' => $baseMonth, 'reset_rows' => $affected],
                'admin', (int)$admin['id']
            );
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            jsonError('리셋 실패: ' . $e->getMessage(), 500);
        }

        jsonSuccess(['ok' => true, 'updated_rows' => $affected]);

    case 'delete_snapshot':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('POST only', 405);
        }
        $input = getJsonInput();
        $baseMonth = $input['base_month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
            jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
        }

        $db->beginTransaction();
        try {
            $d1 = $db->prepare("DELETE FROM coach_retention_scores WHERE base_month = ?");
            $d1->execute([$baseMonth]);
            $deletedScores = $d1->rowCount();

            $d2 = $db->prepare("DELETE FROM coach_retention_runs WHERE base_month = ?");
            $d2->execute([$baseMonth]);
            $deletedRuns = $d2->rowCount();

            logChange(
                $db, 'retention_allocation', 0, 'snapshot_deleted',
                null, ['base_month' => $baseMonth,
                       'deleted_scores' => $deletedScores,
                       'deleted_runs' => $deletedRuns],
                'admin', (int)$admin['id']
            );

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            jsonError('삭제 실패: ' . $e->getMessage(), 500);
        }

        jsonSuccess([
            'ok' => true,
            'deleted_scores' => $deletedScores,
            'deleted_runs'   => $deletedRuns,
        ]);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}

function _fetchRowById(PDO $db, int $id): ?array
{
    $s = $db->prepare("
        SELECT id, coach_id, coach_name_snapshot, base_month, grade, rank_num,
               total_score, new_retention_3m, existing_retention_3m,
               assigned_members, requested_count, auto_allocation, final_allocation,
               adjusted_by, adjusted_at, monthly_detail, updated_at
          FROM coach_retention_scores
         WHERE id = ?
    ");
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) return null;
    $r['monthly_detail'] = $r['monthly_detail'] ? json_decode($r['monthly_detail'], true) : [];
    foreach (['id','rank_num','assigned_members','requested_count','auto_allocation','final_allocation'] as $k) {
        $r[$k] = (int)$r[$k];
    }
    if ($r['coach_id'] !== null) $r['coach_id'] = (int)$r['coach_id'];
    return $r;
}
