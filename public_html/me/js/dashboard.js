'use strict';

const MeDashboard = {
  async render(root, member) {
    root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <div class="me-greet">안녕하세요, <strong>${MeUI.esc(member.name)}</strong>님</div>
          <button class="me-btn me-btn-ghost" id="meLogoutBtn">로그아웃</button>
        </header>
        <main class="me-cards" id="meCards">
          <div class="me-card-loading">불러오는 중...</div>
        </main>
      </div>
    `;
    document.getElementById('meLogoutBtn').onclick = () => MeApp.logout();
    await this.loadCards();
  },

  async loadCards() {
    const [sensory, disc] = await Promise.all([
      MeAPI.get('/api/member_tests.php?action=latest&test_type=sensory'),
      MeAPI.get('/api/member_tests.php?action=latest&test_type=disc'),
    ]);
    const cards = document.getElementById('meCards');
    cards.innerHTML = `
      ${this.renderSensoryCard(sensory.ok ? sensory.data.result : null)}
      ${this.renderDiscCard(disc.ok ? disc.data.result : null)}
    `;
    this.bindCards();
  },

  renderSensoryCard(latest) {
    if (latest) {
      return `
        <div class="me-card">
          <div class="me-card-title">오감각 테스트</div>
          <div class="me-card-meta">최근 응시: ${MeUI.esc(MeUI.formatDate(latest.tested_at))}</div>
          <div class="me-card-result">${MeUI.esc(latest.result_data?.title || '-')}</div>
          <div class="me-card-actions">
            <button class="me-btn me-btn-primary" data-action="view-sensory">내 결과 보기</button>
            <button class="me-btn me-btn-outline" data-action="retake-sensory">다시 보기</button>
          </div>
        </div>
      `;
    }
    return `
      <div class="me-card">
        <div class="me-card-title">오감각 테스트</div>
        <div class="me-card-meta">미응시</div>
        <div class="me-card-desc">48개 문항으로 나의 학습 감각 유형을 알아봅니다 (5분 소요)</div>
        <div class="me-card-actions">
          <button class="me-btn me-btn-primary" data-action="start-sensory">시험 시작하기</button>
        </div>
      </div>
    `;
  },

  renderDiscCard(latest) {
    if (latest) {
      const data = latest.result_data || {};
      const label = data.primary && data.title ? `${data.primary} ${data.title}` : '-';
      return `
        <div class="me-card">
          <div class="me-card-title">DISC 진단</div>
          <div class="me-card-meta">최근 응시: ${MeUI.esc(MeUI.formatDate(latest.tested_at))}</div>
          <div class="me-card-result">${MeUI.esc(label)}</div>
          <div class="me-card-actions">
            <button class="me-btn me-btn-primary" data-action="view-disc">내 결과 보기</button>
            <button class="me-btn me-btn-outline" data-action="retake-disc">다시 보기</button>
          </div>
        </div>
      `;
    }
    return `
      <div class="me-card">
        <div class="me-card-title">DISC 진단</div>
        <div class="me-card-meta">미응시</div>
        <div class="me-card-desc">10개 문항으로 나의 성향 유형을 알아봅니다 (3분 소요)</div>
        <div class="me-card-actions">
          <button class="me-btn me-btn-primary" data-action="start-disc">시험 시작하기</button>
        </div>
      </div>
    `;
  },

  bindCards() {
    document.querySelectorAll('[data-action]').forEach(btn => {
      btn.onclick = async () => {
        const action = btn.dataset.action;
        if (action === 'start-sensory' || action === 'retake-sensory') {
          MeApp.go('test', { testType: 'sensory' });
        } else if (action === 'view-sensory') {
          const res = await MeAPI.get('/api/member_tests.php?action=latest&test_type=sensory');
          if (res.ok && res.data.result) {
            MeApp.go('result', { testType: 'sensory', resultData: res.data.result.result_data });
          }
        } else if (action === 'start-disc' || action === 'retake-disc') {
          MeApp.go('test', { testType: 'disc' });
        } else if (action === 'view-disc') {
          const res = await MeAPI.get('/api/member_tests.php?action=latest&test_type=disc');
          if (res.ok && res.data.result) {
            MeApp.go('result', { testType: 'disc', resultData: res.data.result.result_data });
          }
        }
      };
    });
  },
};
