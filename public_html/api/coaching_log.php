<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coaching_log.php';
require_once __DIR__ . '/../includes/member_chart_access.php';

$user = getCurrentUser();
if (!$user) jsonError('로그인이 필요합니다', 401);

$action = $_GET['action'] ?? '';

/** order_id 기반 권한 가드. require_member_chart_access 호출하여 404 또는 통과 */
function _guard_by_order(int $order_id): array {
    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT member_id FROM orders WHERE id=?");
    $stmt->execute([$order_id]);
    $member_id = (int)$stmt->fetchColumn();
    if (!$member_id) jsonError('주문을 찾을 수 없습니다', 404);
    return require_member_chart_access($member_id);
}
function _guard_by_session(int $sid): array {
    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT order_id FROM order_sessions WHERE id=?");
    $stmt->execute([$sid]);
    $oid = (int)$stmt->fetchColumn();
    if (!$oid) jsonError('세션을 찾을 수 없습니다', 404);
    return _guard_by_order($oid);
}

try {
    switch ($action) {
        case 'list': {
            $oid = (int)($_GET['order_id'] ?? 0);
            _guard_by_order($oid);
            jsonSuccess(['sessions' => CoachingLog::list_for_order($oid)]);
            break;
        }
        case 'create': {
            $in = getJsonInput();
            $oid = (int)($in['order_id'] ?? 0);
            $u = _guard_by_order($oid);
            $sid = CoachingLog::create_for_order($oid, $in, (int)$u['id']);
            jsonSuccess(['id' => $sid]);
            break;
        }
        case 'update': {
            $sid = (int)($_GET['id'] ?? 0);
            $u = _guard_by_session($sid);
            CoachingLog::update($sid, getJsonInput(), (int)$u['id']);
            jsonSuccess([]);
            break;
        }
        case 'delete': {
            $sid = (int)($_GET['id'] ?? 0);
            $u = _guard_by_session($sid);
            CoachingLog::delete($sid, (int)$u['id']);
            jsonSuccess([]);
            break;
        }
        case 'bulk_update': {
            $in = getJsonInput();
            $ids = array_map('intval', $in['ids'] ?? []);
            if (empty($ids)) jsonError('ids 없음', 400);
            foreach ($ids as $sid) _guard_by_session($sid);
            $n = CoachingLog::bulk_update($ids, $in['data'] ?? [], (int)$user['id']);
            jsonSuccess(['updated' => $n]);
            break;
        }
        default:
            jsonError('unknown action', 400);
    }
} catch (Throwable $e) {
    error_log('coaching_log API: ' . $e->getMessage());
    jsonError('서버 오류: ' . $e->getMessage(), 500);
}
