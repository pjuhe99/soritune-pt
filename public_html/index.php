<?php
/**
 * PT Management System — Entry Point
 * Redirects to admin or coach page based on session.
 */
require_once __DIR__ . '/includes/auth.php';

$user = getCurrentUser();

if ($user) {
    if ($user['role'] === 'admin') {
        header('Location: /admin/');
    } else {
        header('Location: /coach/');
    }
    exit;
}

// Default: show choice page
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SoriTune PT</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">
    <div class="login-logo">SoriTune PT</div>
    <div class="login-subtitle" style="margin-bottom:24px">로그인 유형을 선택하세요</div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <a href="/admin/" class="btn btn-primary" style="text-decoration:none;text-align:center">관리자</a>
      <a href="/coach/" class="btn btn-secondary" style="text-decoration:none;text-align:center">코치</a>
    </div>
  </div>
</div>
</body>
</html>
