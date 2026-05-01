<?php
require_once __DIR__ . '/../includes/auth.php';
$user = getCurrentUser();
$isLoggedIn = $user && $user['role'] === 'coach';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SoriTune PT — Coach</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.min.css">
<link rel="stylesheet" href="/assets/css/style.css">
<script>
// DEV 환경 배지 (dev- 서브도메인일 때만 노출)
if (location.hostname.startsWith('dev-')) {
  document.addEventListener('DOMContentLoaded', () => {
    const badge = document.createElement('div');
    badge.id = 'dev-badge';
    badge.textContent = 'DEV';
    badge.style.cssText = 'position:fixed;top:0;left:50%;transform:translateX(-50%);z-index:99999;background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:2px 12px;border-radius:0 0 6px 6px;letter-spacing:1px;pointer-events:none;opacity:0.9;';
    document.body.appendChild(badge);
  });
}
</script>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<div class="login-wrapper">
  <div class="login-card">
    <div class="login-logo">SoriTune PT</div>
    <div class="login-subtitle">코치 로그인</div>
    <div class="login-error" id="loginError"></div>
    <form id="loginForm">
      <div class="form-group">
        <input type="text" class="form-input" id="loginId" placeholder="아이디" autocomplete="username">
      </div>
      <div class="form-group">
        <input type="password" class="form-input" id="loginPw" placeholder="비밀번호" autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px">LOGIN</button>
    </form>
  </div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', async e => {
  e.preventDefault();
  const err = document.getElementById('loginError');
  err.style.display = 'none';
  const res = await fetch('/api/auth.php?action=login', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      login_id: document.getElementById('loginId').value,
      password: document.getElementById('loginPw').value,
      role: 'coach'
    })
  });
  const data = await res.json();
  if (data.ok) { location.reload(); }
  else { err.textContent = data.message; err.style.display = 'block'; }
});
</script>

<?php else: ?>
<div class="app-layout">
  <aside class="sidebar">
    <div class="sidebar-logo">SoriTune PT</div>
    <nav class="sidebar-nav">
      <a href="#my-members" data-page="my-members">내 회원</a>
      <a href="#kakao-check" data-page="kakao-check">카톡방 입장 체크</a>
      <a href="#my-info" data-page="my-info">내 정보</a>
    </nav>
  </aside>
  <main class="main-content">
    <div class="topbar">
      <div></div>
      <div class="topbar-user">
        <span><?= htmlspecialchars($user['name']) ?> (코치)</span>
        <button class="btn btn-small btn-outline" onclick="logout()">LOGOUT</button>
      </div>
    </div>
    <div id="pageContent"></div>
  </main>
</div>

<script src="/coach/js/app.js"></script>
<script src="/coach/js/pages/my-members.js"></script>
<script src="/coach/js/pages/kakao-check.js"></script>
<script src="/coach/js/pages/member-chart.js"></script>
<script src="/coach/js/pages/my-info.js"></script>
<script>CoachApp.init();</script>
<?php endif; ?>

</body>
</html>
