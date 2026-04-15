<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        $body = json_decode(file_get_contents('php://input'), true);
        $login_id = trim($body['login_id'] ?? '');
        $password = $body['password'] ?? '';
        $role = $body['role'] ?? '';

        if (!$login_id || !$password || !in_array($role, ['admin', 'coach'], true)) {
            jsonError('필수 항목이 누락되었습니다', 400);
        }

        $db = getDB();
        if ($role === 'admin') {
            $stmt = $db->prepare('SELECT id, login_id, name, password_hash, status FROM admins WHERE login_id = ?');
            $stmt->execute([$login_id]);
            $user = $stmt->fetch();
            $display_name = $user['name'] ?? '';
        } else {
            $stmt = $db->prepare('SELECT id, login_id, coach_name AS display_name, password_hash, status FROM coaches WHERE login_id = ?');
            $stmt->execute([$login_id]);
            $user = $stmt->fetch();
            $display_name = $user['display_name'] ?? '';
        }

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonError('아이디 또는 비밀번호가 올바르지 않습니다', 401);
        }

        if ($user['status'] !== 'active') {
            jsonError('비활성화된 계정입니다', 403);
        }

        startAuthSession();
        $_SESSION['pt_user'] = [
            'id'       => $user['id'],
            'login_id' => $user['login_id'],
            'name'     => $display_name,
            'role'     => $role,
        ];

        jsonSuccess(['role' => $role, 'name' => $display_name], '로그인 성공');
        break;

    case 'logout':
        startAuthSession();
        $_SESSION = [];
        session_destroy();
        jsonSuccess([], '로그아웃 되었습니다');
        break;

    case 'me':
        $user = getCurrentUser();
        if (!$user) {
            jsonError('로그인이 필요합니다', 401);
        }
        jsonSuccess($user);
        break;

    default:
        jsonError('알 수 없는 액션입니다', 400);
}
