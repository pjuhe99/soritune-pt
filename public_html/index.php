<?php
/**
 * PT Management System — Entry Point
 * 회원이 주 진입자. admin/coach 는 본인 URL(/admin/, /coach/)로 직접 접근.
 */
require_once __DIR__ . '/includes/auth.php';

$user = getCurrentUser();

if ($user) {
    if ($user['role'] === 'admin') {
        header('Location: /admin/');
        exit;
    }
    if ($user['role'] === 'coach') {
        header('Location: /coach/');
        exit;
    }
}

// 비로그인 사용자 + 회원 모두 회원 포털로
header('Location: /me/');
exit;
