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

    // TODO in subsequent tasks:
    //   calculate, update_allocation, reset_allocation, delete_snapshot

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
