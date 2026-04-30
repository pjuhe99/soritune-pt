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

        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND coach_id = ? AND status IN ('진행중', '매칭완료') LIMIT 1");
            $stmt->execute([$memberId, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        $stmt = $db->prepare("
            SELECT mn.*,
              CASE mn.author_type
                WHEN 'admin' THEN (SELECT name FROM admins WHERE id = mn.author_id)
                WHEN 'coach' THEN (SELECT coach_name FROM coaches WHERE id = mn.author_id)
              END AS author_name
            FROM member_notes mn
            WHERE mn.member_id = ?
            ORDER BY mn.created_at DESC
        ");
        $stmt->execute([$memberId]);
        jsonSuccess(['notes' => $stmt->fetchAll()]);

    case 'create':
        $input = getJsonInput();
        $memberId = (int)($input['member_id'] ?? 0);
        $content = trim($input['content'] ?? '');
        if (!$memberId || !$content) jsonError('내용을 입력하세요');

        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND coach_id = ? AND status IN ('진행중', '매칭완료') LIMIT 1");
            $stmt->execute([$memberId, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        $stmt = $db->prepare("INSERT INTO member_notes (member_id, author_type, author_id, content) VALUES (?, ?, ?, ?)");
        $stmt->execute([$memberId, $user['role'], $user['id'], $content]);
        jsonSuccess(['id' => (int)$db->lastInsertId()], '메모가 추가되었습니다');

    case 'delete':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        $db->prepare("DELETE FROM member_notes WHERE id = ?")->execute([$id]);
        jsonSuccess([], '메모가 삭제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
