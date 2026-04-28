<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/matching_engine.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db    = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'current':       _actionCurrent($db);             break;
    case 'runs':          _actionRuns($db);                break;
    case 'preview':       _actionPreview($db);             break;
    case 'base_months':   _actionBaseMonths($db);          break;
    case 'start':         _actionStart($db, $admin);       break;
    case 'update_draft':  _actionUpdateDraft($db, $admin); break;
    case 'confirm':       _actionConfirm($db, $admin);     break;
    case 'cancel':        _actionCancel($db, $admin);      break;
    default:
        jsonError('알 수 없는 action', 400);
}

/**
 * GET ?action=current
 * 현재 status='draft'인 batch가 있으면 그 batch와 drafts 목록 + capacity_snapshot 반환.
 * 없으면 {"current": null}.
 */
function _actionCurrent(PDO $db): void {
    $run = $db->query("
        SELECT id, base_month, status, started_by, started_at,
               total_orders, prev_coach_count, new_pool_count,
               matched_count, unmatched_count, capacity_snapshot
          FROM coach_assignment_runs
         WHERE status = 'draft'
         ORDER BY started_at DESC
         LIMIT 1
    ")->fetch();

    if (!$run) { jsonSuccess(['current' => null]); }

    $stmt = $db->prepare("
        SELECT d.id, d.order_id, d.proposed_coach_id, d.source,
               d.prev_coach_id, d.prev_end_date, d.reason, d.updated_at,
               m.id AS member_id, m.name AS member_name,
               o.product_name, o.start_date, o.end_date,
               c.coach_name AS proposed_coach_name,
               pc.coach_name AS prev_coach_name
          FROM coach_assignment_drafts d
          JOIN orders   o  ON o.id  = d.order_id
          JOIN members  m  ON m.id  = o.member_id
          LEFT JOIN coaches c  ON c.id  = d.proposed_coach_id
          LEFT JOIN coaches pc ON pc.id = d.prev_coach_id
         WHERE d.batch_id = ?
         ORDER BY d.source, m.name
    ");
    $stmt->execute([$run['id']]);
    $drafts = $stmt->fetchAll();

    $run['capacity_snapshot'] = $run['capacity_snapshot']
        ? json_decode($run['capacity_snapshot'], true) : [];

    foreach ($drafts as &$d) {
        $d['proposed_coach_id'] = $d['proposed_coach_id'] !== null ? (int)$d['proposed_coach_id'] : null;
        $d['prev_coach_id']     = $d['prev_coach_id']     !== null ? (int)$d['prev_coach_id']     : null;
    }
    unset($d);

    jsonSuccess(['current' => ['run' => $run, 'drafts' => $drafts]]);
}

/**
 * GET ?action=runs
 * 과거 batch 메타 리스트 (confirmed / cancelled), 최근 20건.
 */
function _actionRuns(PDO $db): void {
    $rows = $db->query("
        SELECT id, base_month, status, started_at, confirmed_at, cancelled_at,
               total_orders, matched_count, unmatched_count
          FROM coach_assignment_runs
         WHERE status IN ('confirmed','cancelled')
         ORDER BY started_at DESC
         LIMIT 20
    ")->fetchAll();
    jsonSuccess(['runs' => $rows]);
}

/**
 * GET ?action=preview
 * 현재 매칭 대상이 될 매칭대기 order 수 (active draft 제외).
 */
function _actionPreview(PDO $db): void {
    $cnt = (int)$db->query("
        SELECT COUNT(*) FROM orders o
         WHERE o.status='매칭대기'
           AND NOT EXISTS (
                 SELECT 1 FROM coach_assignment_drafts d
                          JOIN coach_assignment_runs r ON r.id=d.batch_id
                  WHERE d.order_id=o.id AND r.status='draft'
           )
    ")->fetchColumn();
    jsonSuccess(['unmatched_orders' => $cnt]);
}

/**
 * GET ?action=base_months
 * coach_retention_runs에 있는 base_month 목록 (start 시 드롭다운용). 최근 12개월.
 */
function _actionBaseMonths(PDO $db): void {
    $rows = $db->query("
        SELECT base_month
          FROM coach_retention_runs
         ORDER BY base_month DESC
         LIMIT 12
    ")->fetchAll(PDO::FETCH_COLUMN);
    jsonSuccess(['base_months' => $rows]);
}

/**
 * POST ?action=start
 * body: { base_month: "YYYY-MM" }
 *
 * 1. active draft가 이미 있으면 409 conflict
 * 2. 매칭대기 order 0건이면 400
 * 3. base_month의 coach_retention_scores를 capacity_snapshot으로 잡음
 * 4. coach_assignment_runs INSERT (status='draft')
 * 5. matching_engine.runMatchingForBatch() 호출 (drafts INSERT + runs 통계 업데이트)
 * 6. 결과 반환 (action=current 형태)
 */
function _actionStart(PDO $db, array $admin): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    $input = getJsonInput();
    $baseMonth = $input['base_month'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
        jsonError('base_month 형식이 잘못되었습니다 (YYYY-MM)');
    }

    // 1. active draft 검사
    $existing = $db->query("SELECT id FROM coach_assignment_runs WHERE status='draft' LIMIT 1")->fetchColumn();
    if ($existing) {
        jsonError('이미 진행 중인 draft batch가 있습니다 (#' . $existing . '). 검토를 먼저 끝내거나 폐기해주세요.', 409);
    }

    // 2. 매칭대기 order 검사
    $unmatchedCount = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='매칭대기'")->fetchColumn();
    if ($unmatchedCount === 0) {
        jsonError('매칭대기 상태의 주문이 없습니다.', 400);
    }

    // 3. capacity_snapshot 잡기 (active 코치 only, final_allocation>0)
    $stmt = $db->prepare("
        SELECT s.coach_id, c.coach_name, s.final_allocation
          FROM coach_retention_scores s
          JOIN coaches c ON c.id = s.coach_id
         WHERE s.base_month = ?
           AND c.status     = 'active'
           AND s.final_allocation > 0
         ORDER BY c.coach_name
    ");
    $stmt->execute([$baseMonth]);
    $capacity = $stmt->fetchAll();
    foreach ($capacity as &$row) {
        $row['coach_id']         = (int)$row['coach_id'];
        $row['final_allocation'] = (int)$row['final_allocation'];
    }
    unset($row);

    // capacity 비어있어도 진행 (모두 unmatched가 될 수 있음). 운영 경고는 UI에서.

    // 4. runs INSERT
    $db->beginTransaction();
    try {
        $ins = $db->prepare("
            INSERT INTO coach_assignment_runs
              (base_month, status, started_by, capacity_snapshot)
            VALUES (?, 'draft', ?, ?)
        ");
        $ins->execute([
            $baseMonth,
            (int)$admin['id'],
            json_encode(array_values($capacity), JSON_UNESCAPED_UNICODE),
        ]);
        $batchId = (int)$db->lastInsertId();

        // 5. 매칭 엔진 실행
        $stats = runMatchingForBatch($db, $batchId, $baseMonth, $capacity);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('매칭 실행 실패: ' . $e->getMessage(), 500);
    }

    // 6. current 반환 (편의 — 클라이언트가 즉시 화면 그릴 수 있게)
    $_GET['action'] = 'current';
    _actionCurrent($db);
}
/**
 * POST ?action=update_draft
 * body: { draft_id: N, proposed_coach_id: N|null }
 *
 * 어드민이 행별 드롭다운으로 코치를 바꾸면 호출.
 * - proposed_coach_id != null: source='manual_override', reason='수동 조정 (이전: {old_source})'
 * - proposed_coach_id == null: source='unmatched', reason='수동 비움'
 */
function _actionUpdateDraft(PDO $db, array $admin): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    $input = getJsonInput();
    $draftId = (int)($input['draft_id'] ?? 0);
    $newCoachId = array_key_exists('proposed_coach_id', $input)
        ? ($input['proposed_coach_id'] !== null ? (int)$input['proposed_coach_id'] : null)
        : null;

    if ($draftId <= 0) jsonError('draft_id가 필요합니다');

    $stmt = $db->prepare("
        SELECT d.id, d.source, d.proposed_coach_id, r.status AS run_status
          FROM coach_assignment_drafts d
          JOIN coach_assignment_runs r ON r.id = d.batch_id
         WHERE d.id = ?
    ");
    $stmt->execute([$draftId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('draft를 찾을 수 없습니다', 404);
    if ($row['run_status'] !== 'draft') {
        jsonError('이 batch는 더 이상 편집할 수 없습니다 (status=' . $row['run_status'] . ')', 409);
    }

    if ($newCoachId !== null) {
        // 코치 존재/active 검사
        $coach = $db->prepare("SELECT id, status FROM coaches WHERE id = ?");
        $coach->execute([$newCoachId]);
        $c = $coach->fetch();
        if (!$c) jsonError('코치를 찾을 수 없습니다', 404);
        if ($c['status'] !== 'active') jsonError('inactive 코치에는 매칭할 수 없습니다', 400);

        $oldSource = $row['source'];
        $upd = $db->prepare("
            UPDATE coach_assignment_drafts
               SET proposed_coach_id = ?,
                   source            = 'manual_override',
                   reason            = ?
             WHERE id = ?
        ");
        $upd->execute([
            $newCoachId,
            "수동 조정 (이전: {$oldSource})",
            $draftId,
        ]);
    } else {
        $upd = $db->prepare("
            UPDATE coach_assignment_drafts
               SET proposed_coach_id = NULL,
                   source            = 'unmatched',
                   reason            = '수동 비움'
             WHERE id = ?
        ");
        $upd->execute([$draftId]);
    }

    // 갱신된 row 반환
    $sel = $db->prepare("
        SELECT d.id, d.order_id, d.proposed_coach_id, d.source,
               d.prev_coach_id, d.prev_end_date, d.reason, d.updated_at,
               c.coach_name AS proposed_coach_name,
               pc.coach_name AS prev_coach_name
          FROM coach_assignment_drafts d
          LEFT JOIN coaches c  ON c.id  = d.proposed_coach_id
          LEFT JOIN coaches pc ON pc.id = d.prev_coach_id
         WHERE d.id = ?
    ");
    $sel->execute([$draftId]);
    $latest = $sel->fetch();
    $latest['proposed_coach_id'] = $latest['proposed_coach_id'] !== null ? (int)$latest['proposed_coach_id'] : null;
    $latest['prev_coach_id']     = $latest['prev_coach_id']     !== null ? (int)$latest['prev_coach_id']     : null;

    jsonSuccess(['row' => $latest]);
}
function _actionConfirm(PDO $db, array $admin): void { jsonError('미구현', 501); }
/**
 * POST ?action=cancel
 * body: { batch_id: N }
 *
 * batch를 통째로 폐기. drafts CASCADE 삭제. orders는 매칭대기 그대로.
 * change_logs 기록은 생략 (drafts는 임시 데이터).
 */
function _actionCancel(PDO $db, array $admin): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    $input = getJsonInput();
    $batchId = (int)($input['batch_id'] ?? 0);
    if ($batchId <= 0) jsonError('batch_id가 필요합니다');

    $run = $db->prepare("SELECT id, status FROM coach_assignment_runs WHERE id = ?");
    $run->execute([$batchId]);
    $r = $run->fetch();
    if (!$r) jsonError('batch를 찾을 수 없습니다', 404);
    if ($r['status'] !== 'draft') {
        jsonError('이미 처리된 batch입니다 (status=' . $r['status'] . ')', 409);
    }

    $db->beginTransaction();
    try {
        $upd = $db->prepare("UPDATE coach_assignment_runs SET status='cancelled', cancelled_at=NOW() WHERE id = ?");
        $upd->execute([$batchId]);
        $del = $db->prepare("DELETE FROM coach_assignment_drafts WHERE batch_id = ?");
        $del->execute([$batchId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('취소 실패: ' . $e->getMessage(), 500);
    }

    jsonSuccess(['ok' => true]);
}
