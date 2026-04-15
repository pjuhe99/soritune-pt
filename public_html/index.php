<?php
$domain = htmlspecialchars($_SERVER['SERVER_NAME'] ?? 'your-site.soritune.com');
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=$domain?> — Powered by ROBOTION</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#050507;--surface:rgba(24,24,27,.85);--border:#27272a;--accent:#6366f1;--accent2:#818cf8;--glow:rgba(99,102,241,.15);--text:#fafafa;--muted:#a1a1aa;--success:#22c55e}
body{min-height:100vh;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;overflow-x:hidden;display:flex;flex-direction:column;align-items:center;justify-content:center;perspective:1200px}

/* Deep space background */
.space-bg{position:fixed;inset:0;background:radial-gradient(ellipse at 30% 20%,rgba(99,102,241,.06),transparent 50%),radial-gradient(ellipse at 70% 80%,rgba(139,92,246,.04),transparent 50%),radial-gradient(ellipse at 50% 50%,rgba(6,6,11,1),#050507);pointer-events:none;z-index:0}

/* Animated grid with perspective */
.grid-bg{position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:0}
.grid-plane{position:absolute;left:-50%;right:-50%;bottom:-20%;height:80%;background-image:linear-gradient(rgba(99,102,241,.07) 1px,transparent 1px),linear-gradient(90deg,rgba(99,102,241,.07) 1px,transparent 1px);background-size:80px 80px;transform:rotateX(65deg);transform-origin:center bottom;mask-image:linear-gradient(to top,rgba(0,0,0,.6) 0%,transparent 70%)}

/* Floating orbs */
.orb{position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;z-index:0;animation:orb-drift 20s ease-in-out infinite alternate}
.orb-1{width:400px;height:400px;background:rgba(99,102,241,.08);top:-10%;left:-5%;animation-delay:0s}
.orb-2{width:300px;height:300px;background:rgba(139,92,246,.06);bottom:-5%;right:-5%;animation-delay:-7s}
.orb-3{width:200px;height:200px;background:rgba(59,130,246,.05);top:50%;left:60%;animation-delay:-14s}
@keyframes orb-drift{0%{transform:translate(0,0) scale(1)}50%{transform:translate(30px,-20px) scale(1.1)}100%{transform:translate(-20px,30px) scale(.95)}}

/* Particles */
.particles{position:fixed;inset:0;pointer-events:none;z-index:0}
.particle{position:absolute;width:2px;height:2px;background:var(--accent2);border-radius:50%;opacity:0;animation:float-up linear infinite}
@keyframes float-up{0%{transform:translateY(100vh) scale(0);opacity:0}10%{opacity:.5}90%{opacity:.2}100%{transform:translateY(-10vh) scale(1);opacity:0}}

/* Main card — 3D transforms */
.card-wrap{position:relative;z-index:1;transform-style:preserve-3d;animation:card-enter 1.2s cubic-bezier(.16,1,.3,1) both}
@keyframes card-enter{from{opacity:0;transform:translateY(60px) rotateX(8deg) scale(.92)}to{opacity:1;transform:translateY(0) rotateX(0) scale(1)}}

.card{width:min(600px,92vw);background:var(--surface);backdrop-filter:blur(40px) saturate(1.2);border:1px solid rgba(99,102,241,.1);border-radius:24px;overflow:hidden;box-shadow:
  0 0 0 1px rgba(255,255,255,.03) inset,
  0 1px 0 0 rgba(255,255,255,.05) inset,
  0 -1px 0 0 rgba(0,0,0,.3) inset,
  0 4px 8px rgba(0,0,0,.3),
  0 12px 24px rgba(0,0,0,.25),
  0 24px 48px rgba(0,0,0,.2),
  0 48px 96px rgba(0,0,0,.15),
  0 0 120px rgba(99,102,241,.05);
  transform-style:preserve-3d;transition:transform .4s cubic-bezier(.03,.98,.52,.99),box-shadow .4s ease}

/* 3D hover tilt */
.card:hover{box-shadow:
  0 0 0 1px rgba(255,255,255,.05) inset,
  0 1px 0 0 rgba(255,255,255,.08) inset,
  0 -1px 0 0 rgba(0,0,0,.3) inset,
  0 8px 16px rgba(0,0,0,.3),
  0 16px 32px rgba(0,0,0,.25),
  0 32px 64px rgba(0,0,0,.2),
  0 0 160px rgba(99,102,241,.08)}

/* Reflection layer under card */
.card-reflection{position:absolute;left:5%;right:5%;bottom:-60%;height:60%;background:linear-gradient(to bottom,rgba(99,102,241,.04),transparent 40%);border-radius:50%;filter:blur(30px);pointer-events:none;transform:rotateX(180deg) scaleY(.3);opacity:.5}

/* Top accent bar */
.accent-bar{height:2px;background:linear-gradient(90deg,transparent,var(--accent),var(--accent2),#a78bfa,var(--accent),transparent);position:relative;overflow:hidden}
.accent-bar::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent 0%,rgba(255,255,255,.6) 50%,transparent 100%);background-size:200% 100%;animation:shimmer 3s ease-in-out infinite}
@keyframes shimmer{0%,100%{background-position:200% 0}50%{background-position:-200% 0}}

/* Header */
.header{padding:44px 40px 0;text-align:center}

/* 3D Logo */
.logo-wrap{display:inline-flex;align-items:center;justify-content:center;margin-bottom:28px;position:relative;transform-style:preserve-3d}
.logo-icon{width:64px;height:64px;background:linear-gradient(145deg,var(--accent),#7c3aed,#a78bfa);border-radius:16px;display:flex;align-items:center;justify-content:center;position:relative;
  box-shadow:0 0 40px rgba(99,102,241,.35),0 8px 16px rgba(0,0,0,.4),0 2px 4px rgba(0,0,0,.3),inset 0 1px 0 rgba(255,255,255,.2);
  animation:pulse-glow 4s ease-in-out infinite;transform:translateZ(20px)}
@keyframes pulse-glow{0%,100%{box-shadow:0 0 40px rgba(99,102,241,.35),0 8px 16px rgba(0,0,0,.4),0 2px 4px rgba(0,0,0,.3),inset 0 1px 0 rgba(255,255,255,.2)}50%{box-shadow:0 0 60px rgba(99,102,241,.5),0 8px 16px rgba(0,0,0,.4),0 2px 4px rgba(0,0,0,.3),inset 0 1px 0 rgba(255,255,255,.2)}}
.logo-icon svg{width:30px;height:30px;color:#fff;filter:drop-shadow(0 2px 4px rgba(0,0,0,.3))}
.logo-depth{position:absolute;inset:0;border-radius:16px;background:linear-gradient(180deg,transparent 60%,rgba(0,0,0,.3));pointer-events:none}

/* Orbiting rings */
.logo-ring{position:absolute;inset:-10px;border:1.5px solid rgba(99,102,241,.15);border-radius:22px;animation:spin-slow 25s linear infinite}
.logo-ring-2{position:absolute;inset:-18px;border:1px solid rgba(139,92,246,.08);border-radius:28px;animation:spin-slow 40s linear infinite reverse}
@keyframes spin-slow{to{transform:rotate(360deg)}}
.logo-ring::before{content:'';position:absolute;top:-3px;left:50%;width:6px;height:6px;background:var(--accent2);border-radius:50%;transform:translateX(-50%);box-shadow:0 0 10px var(--accent2)}
.logo-ring-2::before{content:'';position:absolute;bottom:-2px;right:20%;width:4px;height:4px;background:rgba(139,92,246,.5);border-radius:50%}

.brand{font-size:12px;font-weight:800;letter-spacing:5px;color:var(--accent2);text-transform:uppercase;margin-bottom:8px;display:flex;align-items:center;justify-content:center;gap:10px;text-shadow:0 0 30px rgba(99,102,241,.3)}
.brand::before,.brand::after{content:'';width:24px;height:1px;background:linear-gradient(90deg,transparent,var(--accent2))}
.brand::after{background:linear-gradient(90deg,var(--accent2),transparent)}
h1{font-size:14px;font-weight:400;color:var(--muted);letter-spacing:1.5px;text-transform:uppercase}

/* Status */
.status{padding:30px 40px}
.status-box{background:rgba(34,197,94,.03);border:1px solid rgba(34,197,94,.12);border-radius:14px;padding:18px 22px;display:flex;align-items:center;gap:16px;
  box-shadow:0 0 40px rgba(34,197,94,.03) inset,0 4px 12px rgba(0,0,0,.15)}
.status-dot-wrap{position:relative;width:14px;height:14px;flex-shrink:0}
.status-dot{width:10px;height:10px;background:var(--success);border-radius:50%;position:absolute;top:2px;left:2px;box-shadow:0 0 12px rgba(34,197,94,.5)}
.status-dot::after{content:'';position:absolute;inset:-5px;border:2px solid rgba(34,197,94,.15);border-radius:50%;animation:ping 2.5s cubic-bezier(0,0,.2,1) infinite}
@keyframes ping{75%,100%{transform:scale(2);opacity:0}}
.status-info{flex:1;min-width:0}
.status-label{font-size:12px;font-weight:700;color:var(--success);margin-bottom:3px;letter-spacing:.5px}
.status-domain{font-size:15px;font-family:'SF Mono',Monaco,'Cascadia Code','Fira Code',monospace;color:var(--text);word-break:break-all;font-weight:500}

/* Info grid */
.info{padding:0 40px 12px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.info-item{background:rgba(255,255,255,.015);border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:16px 18px;transition:all .3s cubic-bezier(.03,.98,.52,.99);position:relative;overflow:hidden}
.info-item::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(99,102,241,.05),transparent 60%);opacity:0;transition:opacity .3s}
.info-item:hover{border-color:rgba(99,102,241,.25);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.2),0 0 40px rgba(99,102,241,.04)}
.info-item:hover::before{opacity:1}
.info-label{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:7px;display:flex;align-items:center;gap:6px;position:relative}
.info-label svg{width:12px;height:12px;opacity:.4}
.info-value{font-size:13px;font-family:'SF Mono',Monaco,'Cascadia Code',monospace;color:var(--text);position:relative}

/* Contact row */
.contact{padding:8px 40px 28px}
.contact-box{display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 20px;background:rgba(99,102,241,.04);border:1px solid rgba(99,102,241,.08);border-radius:10px}
.contact-box svg{width:14px;height:14px;color:var(--accent2);opacity:.6}
.contact-box a{color:var(--accent2);font-size:13px;font-family:'SF Mono',Monaco,monospace;text-decoration:none;font-weight:500;letter-spacing:.3px}
.contact-box a:hover{color:#a78bfa;text-shadow:0 0 20px rgba(99,102,241,.3)}

/* Footer */
.footer{padding:0 40px 32px;text-align:center}
.divider{height:1px;background:linear-gradient(90deg,transparent,var(--border),transparent);margin-bottom:22px}
.footer-text{font-size:11px;color:var(--muted);line-height:1.7}
.footer-badge{display:inline-flex;align-items:center;gap:7px;margin-top:14px;padding:7px 16px;background:rgba(99,102,241,.05);border:1px solid rgba(99,102,241,.1);border-radius:24px;font-size:10px;font-weight:700;letter-spacing:1.2px;color:var(--accent2);text-transform:uppercase;
  box-shadow:0 2px 8px rgba(0,0,0,.15),0 0 20px rgba(99,102,241,.03) inset;transition:all .3s}
.footer-badge:hover{background:rgba(99,102,241,.08);border-color:rgba(99,102,241,.2);box-shadow:0 4px 16px rgba(0,0,0,.2),0 0 30px rgba(99,102,241,.06) inset}
.footer-badge svg{width:13px;height:13px}

@media(max-width:480px){
  .header,.status,.info,.contact,.footer{padding-left:24px;padding-right:24px}
  .info-grid{grid-template-columns:1fr}
  .header{padding-top:32px}
}
</style>
</head>
<body>

<div class="space-bg"></div>
<div class="grid-bg"><div class="grid-plane"></div></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>
<div class="particles" id="particles"></div>

<div class="card-wrap" id="cardWrap">
  <div class="card" id="card">
    <div class="accent-bar"></div>

    <div class="header">
      <div class="logo-wrap">
        <div class="logo-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
            <path d="M2 17l10 5 10-5"/>
            <path d="M2 12l10 5 10-5"/>
          </svg>
          <div class="logo-depth"></div>
        </div>
        <div class="logo-ring"></div>
        <div class="logo-ring-2"></div>
      </div>
      <div class="brand">ROBOTION</div>
      <h1>AI Agent Auto-Provisioning System</h1>
    </div>

    <div class="status">
      <div class="status-box">
        <div class="status-dot-wrap"><div class="status-dot"></div></div>
        <div class="status-info">
          <div class="status-label">Deployment Complete</div>
          <div class="status-domain"><?=$domain?></div>
        </div>
      </div>
    </div>

    <div class="info">
      <div class="info-grid">
        <div class="info-item">
          <div class="info-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>
            Server
          </div>
          <div class="info-value">Apache / PHP <?=phpversion()?></div>
        </div>
        <div class="info-item">
          <div class="info-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            SSL / TLS
          </div>
          <div class="info-value" style="color:var(--success)">HTTPS Active</div>
        </div>
        <div class="info-item">
          <div class="info-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
            Database
          </div>
          <div class="info-value">MariaDB Ready</div>
        </div>
        <div class="info-item">
          <div class="info-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            Provisioned
          </div>
          <div class="info-value"><?=date('Y-m-d H:i')?></div>
        </div>
      </div>
    </div>

    <div class="contact">
      <div class="contact-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
        <a href="mailto:a@tion.kr">a@tion.kr</a>
      </div>
    </div>

    <div class="footer">
      <div class="divider"></div>
      <div class="footer-text">
        This site was automatically deployed and configured by<br>
        the <strong>ROBOTION</strong> AI infrastructure engine.
      </div>
      <div class="footer-badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        Powered by ROBOTION &copy; <?=$year?>
      </div>
    </div>
  </div>
  <div class="card-reflection"></div>
</div>

<script>
// Particles
const c=document.getElementById('particles');
for(let i=0;i<35;i++){const p=document.createElement('div');p.className='particle';p.style.left=Math.random()*100+'%';p.style.animationDuration=(8+Math.random()*14)+'s';p.style.animationDelay=(-Math.random()*20)+'s';p.style.width=p.style.height=(1+Math.random()*2.5)+'px';c.appendChild(p)}

// 3D tilt on mouse move
const wrap=document.getElementById('cardWrap'),card=document.getElementById('card');
let ticking=false;
document.addEventListener('mousemove',e=>{
  if(ticking)return;ticking=true;
  requestAnimationFrame(()=>{
    const rect=card.getBoundingClientRect();
    const cx=rect.left+rect.width/2,cy=rect.top+rect.height/2;
    const dx=(e.clientX-cx)/rect.width,dy=(e.clientY-cy)/rect.height;
    const rotY=dx*6,rotX=-dy*4;
    wrap.style.transform=`perspective(1200px) rotateX(${rotX}deg) rotateY(${rotY}deg)`;
    ticking=false;
  });
});
document.addEventListener('mouseleave',()=>{wrap.style.transform='perspective(1200px) rotateX(0) rotateY(0)';wrap.style.transition='transform .6s ease'});
document.addEventListener('mouseenter',()=>{wrap.style.transition='transform .1s ease'});
</script>
</body>
</html>
