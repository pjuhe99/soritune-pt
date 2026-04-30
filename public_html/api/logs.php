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
        $targetType = $_GET['target_type'] ?? '';
        $targetId = (int)($_GET['target_id'] ?? 0);
        $memberId = (int)($_GET['member_id'] ?? 0);

        if ($memberId && $user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND coach_id = ? AND status IN ('진행중', '매칭완료') LIMIT 1");
            $stmt->execute([$memberId, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        if ($memberId) {
            // Get all logs related to a member (member + their orders + their assignments)
            $stmt = $db->prepare("
                SELECT cl.*,
                  CASE cl.actor_type
                    WHEN 'admin' THEN (SELECT name FROM admins WHERE id = cl.actor_id)
                    WHEN 'coach' THEN (SELECT coach_name FROM coaches WHERE id = cl.actor_id)
                    ELSE 'system'
                  END AS actor_name
                FROM change_logs cl
                WHERE (cl.target_type = 'member' AND cl.target_id = ?)
                   OR (cl.target_type = 'order' AND cl.target_id IN (SELECT id FROM orders WHERE member_id = ?))
                   OR (cl.target_type = 'coach_assignment' AND cl.target_id IN (SELECT id FROM orders WHERE member_id = ?))
                   OR (cl.target_type = 'merge' AND cl.target_id = ?)
                ORDER BY cl.created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$memberId, $memberId, $memberId, $memberId]);
        } else {
            $stmt = $db->prepare("
                SELECT cl.* FROM change_logs cl
                WHERE cl.target_type = ? AND cl.target_id = ?
                ORDER BY cl.created_at DESC LIMIT 50
            ");
            $stmt->execute([$targetType, $targetId]);
        }
        jsonSuccess(['logs' => $stmt->fetchAll()]);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
