<?php
require_once __DIR__ . '/../includes/auth.php';
$user = getCurrentUser();
$isLoggedIn = $user && ($user['role'] ?? null) === 'member';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SoriTune PT — 회원</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.min.css">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/member.css">
<script>
if (location.hostname.startsWith('dev-')) {
  document.addEventListener('DOMContentLoaded', () => {
    const badge = document.createElement('div');
    badge.id = 'dev-badge';
    badge.textContent = 'DEV';
    badge.style.cssText = 'position:fixed;top:0;left:50%;transform:translateX(-50%);z-index:99999;background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:2px 12px;border-radius:0 0 6px 6px;letter-spacing:1px;pointer-events:none;opacity:0.9;';
    document.body.appendChild(badge);
  });
}
window.__BOOT__ = { isLoggedIn: <?= $isLoggedIn ? 'true' : 'false' ?> };
</script>
</head>
<body class="me-body">
<div id="meRoot"></div>
<script src="/me/js/app.js"></script>
<script src="/me/js/dashboard.js"></script>
<script src="/me/js/test-runner.js"></script>
<script src="/me/js/sensory.js"></script>
<script src="/me/js/result-view.js"></script>
<script>MeApp.init();</script>
</body>
</html>
