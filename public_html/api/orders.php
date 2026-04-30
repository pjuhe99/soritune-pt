<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $memberId = (int)($_GET['member_id'] ?? 0);
        if (!$memberId) jsonError('member_id가 필요합니다');

        // Coach access check
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND coach_id = ? AND status IN ('진행중', '매칭완료') LIMIT 1");
            $stmt->execute([$memberId, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        $stmt = $db->prepare("
            SELECT o.*,
              c.coach_name,
              (SELECT COUNT(*) FROM order_sessions os WHERE os.order_id = o.id AND os.completed_at IS NOT NULL) AS used_sessions
            FROM orders o
            LEFT JOIN coaches c ON c.id = o.coach_id
            WHERE o.member_id = ?
            ORDER BY o.start_date DESC
        ");
        $stmt->execute([$memberId]);
        jsonSuccess(['orders' => $stmt->fetchAll()]);

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');

        $stmt = $db->prepare("
            SELECT o.*, c.coach_name,
              (SELECT COUNT(*) FROM order_sessions os WHERE os.order_id = o.id AND os.completed_at IS NOT NULL) AS used_sessions
            FROM orders o
            LEFT JOIN coaches c ON c.id = o.coach_id
            WHERE o.id = ?
        ");
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) jsonError('주문을 찾을 수 없습니다', 404);

        // Coach access check
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND coach_id = ? AND status IN ('진행중', '매칭완료') LIMIT 1");
            $stmt->execute([$order['member_id'], $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        // Load sessions for count type
        if ($order['product_type'] === 'count') {
            $stmt = $db->prepare("SELECT * FROM order_sessions WHERE order_id = ? ORDER BY session_number");
            $stmt->execute([$id]);
            $order['sessions'] = $stmt->fetchAll();
        }

        jsonSuccess(['order' => $order]);

    case 'create':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $input = getJsonInput();
        $memberId = (int)($input['member_id'] ?? 0);
        $productName = trim($input['product_name'] ?? '');
        $productType = $input['product_type'] ?? '';
        $startDate = $input['start_date'] ?? '';
        $endDate = $input['end_date'] ?? '';

        if (!$memberId || !$productName || !$productType || !$startDate || !$endDate) {
            jsonError('필수 항목을 입력하세요');
        }
        if (!in_array($productType, ['period', 'count'])) jsonError('올바른 상품 유형을 선택하세요');

        $totalSessions = $productType === 'count' ? (int)($input['total_sessions'] ?? 0) : null;
        if ($productType === 'count' && $totalSessions < 1) jsonError('횟수형은 총 횟수를 입력하세요');

        $coachId = !empty($input['coach_id']) ? (int)$input['coach_id'] : null;
        $status = $input['status'] ?? '매칭대기';

        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO orders
            (member_id, coach_id, product_name, product_type, start_date, end_date, total_sessions, amount, status, memo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $memberId, $coachId, $productName, $productType,
            $startDate, $endDate, $totalSessions,
            (int)($input['amount'] ?? 0), $status, $input['memo'] ?? null,
        ]);
        $orderId = (int)$db->lastInsertId();

        // Create session rows for count type
        if ($productType === 'count' && $totalSessions > 0) {
            $stmt = $db->prepare("INSERT INTO order_sessions (order_id, session_number) VALUES (?, ?)");
            for ($i = 1; $i <= $totalSessions; $i++) {
                $stmt->execute([$orderId, $i]);
            }
        }

        if ($coachId) {
            logChange($db, 'order', $orderId, 'coach_assigned',
                null, ['coach_id' => $coachId], $user['role'], $user['id']);
        }

        $db->prepare("SELECT id FROM orders WHERE id = ? FOR UPDATE")->execute([$orderId]);
        recomputeOrderStatus($db, $orderId);

        $db->commit();
        jsonSuccess(['id' => $orderId], 'PT 이력이 추가되었습니다');

    case 'update':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $input = getJsonInput();

        // Get current order
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$id]);
        $oldOrder = $stmt->fetch();
        if (!$oldOrder) jsonError('주문을 찾을 수 없습니다', 404);

        // Coach can only update status
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND coach_id = ? AND status IN ('진행중', '매칭완료') LIMIT 1");
            $stmt->execute([$oldOrder['member_id'], $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);

            $allowedFields = ['status'];
            $input = array_intersect_key($input, array_flip($allowedFields));
        }

        $db->beginTransaction();

        $fields = [];
        $params = [];
        foreach (['product_name','product_type','start_date','end_date','total_sessions','amount','status','memo'] as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = $input[$f];
            }
        }

        // Coach change
        $newCoachId = array_key_exists('coach_id', $input) ? ($input['coach_id'] ?: null) : null;
        $coachChanged = array_key_exists('coach_id', $input) && (int)$input['coach_id'] !== (int)$oldOrder['coach_id'];

        if (array_key_exists('coach_id', $input)) {
            $fields[] = "coach_id = ?";
            $params[] = $newCoachId;
        }

        if (!empty($fields)) {
            $params[] = $id;
            $db->prepare("UPDATE orders SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        }

        if ($coachChanged) {
            logChange($db, 'order', $id, 'coach_change',
                ['coach_id' => $oldOrder['coach_id']], ['coach_id' => $newCoachId],
                $user['role'], $user['id']);
        }

        // Log status change
        if (array_key_exists('status', $input) && $input['status'] !== $oldOrder['status']) {
            logChange($db, 'order', $id, 'status_change',
                ['status' => $oldOrder['status']], ['status' => $input['status']],
                $user['role'], $user['id']);
        }

        // 자동 status 재평가 — 사람이 명시적으로 status 를 보낸 경우 스킵
        if (!array_key_exists('status', $input)) {
            $db->prepare("SELECT id FROM orders WHERE id = ? FOR UPDATE")->execute([$id]);
            recomputeOrderStatus($db, $id);
        }

        $db->commit();
        jsonSuccess([], 'PT 이력이 수정되었습니다');

    case 'delete':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
        jsonSuccess([], 'PT 이력이 삭제되었습니다');

    case 'complete_session':
        $sessionId = (int)($_GET['session_id'] ?? 0);
        if (!$sessionId) jsonError('session_id가 필요합니다');

        $stmt = $db->prepare("
            SELECT os.*, o.member_id, o.coach_id
            FROM order_sessions os
            JOIN orders o ON o.id = os.order_id
            WHERE os.id = ?
        ");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        if (!$session) jsonError('세션을 찾을 수 없습니다', 404);

        // Coach access check
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND coach_id = ? AND status IN ('진행중', '매칭완료') LIMIT 1");
            $stmt->execute([$session['member_id'], $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        // Toggle completion
        if ($session['completed_at']) {
            $db->prepare("UPDATE order_sessions SET completed_at = NULL WHERE id = ?")->execute([$sessionId]);
            $newCompleted = false;
            $msg = '회차 완료가 취소되었습니다';
        } else {
            $db->prepare("UPDATE order_sessions SET completed_at = NOW() WHERE id = ?")->execute([$sessionId]);
            $newCompleted = true;
            $msg = '회차가 완료 처리되었습니다';
        }

        // 자동 status 재평가 (자동 종료된 order의 회차 취소 시 진행중 복귀 위해 allowRevertTerminated=true)
        $orderId = (int)$session['order_id'];
        withOrderLock($db, $orderId, fn() => recomputeOrderStatus($db, $orderId, null, true));

        jsonSuccess(['completed' => $newCompleted], $msg);

    case 'active':
        // Get active (진행중) orders for a member — used for PT progress section
        $memberId = (int)($_GET['member_id'] ?? 0);
        if (!$memberId) jsonError('member_id가 필요합니다');

        $stmt = $db->prepare("
            SELECT o.*, c.coach_name,
              (SELECT COUNT(*) FROM order_sessions os WHERE os.order_id = o.id AND os.completed_at IS NOT NULL) AS used_sessions
            FROM orders o
            LEFT JOIN coaches c ON c.id = o.coach_id
            WHERE o.member_id = ? AND o.status IN ('진행중', '매칭완료')
            ORDER BY o.start_date DESC
        ");
        $stmt->execute([$memberId]);
        $orders = $stmt->fetchAll();

        // Load sessions for count type orders
        foreach ($orders as &$order) {
            if ($order['product_type'] === 'count') {
                $stmt = $db->prepare("SELECT * FROM order_sessions WHERE order_id = ? ORDER BY session_number");
                $stmt->execute([$order['id']]);
                $order['sessions'] = $stmt->fetchAll();
            }
        }
        unset($order);

        jsonSuccess(['orders' => $orders]);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
