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
        $stmt = $db->prepare("SELECT * FROM test_results WHERE member_id = ? ORDER BY tested_at DESC");
        $stmt->execute([$memberId]);
        jsonSuccess(['results' => $stmt->fetchAll()]);

    case 'create':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $input = getJsonInput();
        $memberId = (int)($input['member_id'] ?? 0);
        $testType = $input['test_type'] ?? '';
        $testedAt = $input['tested_at'] ?? '';

        if (!$memberId || !in_array($testType, ['disc','sensory']) || !$testedAt) {
            jsonError('필수 항목을 입력하세요');
        }

        $stmt = $db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at, memo)
            VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $memberId, $testType,
            json_encode($input['result_data'] ?? [], JSON_UNESCAPED_UNICODE),
            $testedAt, $input['memo'] ?? null,
        ]);
        jsonSuccess(['id' => (int)$db->lastInsertId()], '테스트 결과가 저장되었습니다');

    case 'delete':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        $db->prepare("DELETE FROM test_results WHERE id = ?")->execute([$id]);
        jsonSuccess([], '테스트 결과가 삭제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
