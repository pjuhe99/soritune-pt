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

function _actionStart(PDO $db, array $admin): void { jsonError('미구현', 501); }
function _actionUpdateDraft(PDO $db, array $admin): void { jsonError('미구현', 501); }
function _actionConfirm(PDO $db, array $admin): void { jsonError('미구현', 501); }
function _actionCancel(PDO $db, array $admin): void { jsonError('미구현', 501); }
