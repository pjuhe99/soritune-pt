<?php
function startAuthSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('PT_SESSION');
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function getCurrentUser(): ?array {
    startAuthSession();
    if (empty($_SESSION['pt_user'])) return null;
    return $_SESSION['pt_user'];
}

function requireAdmin(): array {
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => '관리자 로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}

function requireCoach(): array {
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'coach') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => '코치 로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}

function requireAnyAuth(): array {
    $user = getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}

function requireMember(): array {
    $user = getCurrentUser();
    if (!$user || ($user['role'] ?? null) !== 'member') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => '회원 로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}
