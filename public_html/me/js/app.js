'use strict';

const MeAPI = {
  async request(url, options = {}) {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
      ...options,
    });
    let data = {};
    try { data = await res.json(); } catch (_) { data = { ok: false, message: 'Invalid response' }; }
    return data;
  },
  get(url) { return this.request(url); },
  post(url, body) { return this.request(url, { method: 'POST', body: JSON.stringify(body || {}) }); },
};

const MeUI = {
  esc(s) {
    const el = document.createElement('span');
    el.textContent = s == null ? '' : String(s);
    return el.innerHTML;
  },
  formatDate(d) { return d ? String(d).split(' ')[0] : '-'; },
};

const MeApp = {
  state: { member: null, view: 'login' },
  root: null,

  async init() {
    this.root = document.getElementById('meRoot');
    const res = await MeAPI.get('/api/member_auth.php?action=me');
    if (res.ok) {
      this.state.member = res.data.member;
      this.go('dashboard');
    } else {
      this.go('login');
    }
  },

  go(view, params = {}) {
    this.state.view = view;
    this.state.params = params;
    this.render();
  },

  render() {
    const { view, member, params } = this.state;
    if (view === 'login') {
      this.root.innerHTML = this.renderLogin();
      this.bindLogin();
    } else if (view === 'dashboard') {
      MeDashboard.render(this.root, member);
    } else if (view === 'test') {
      if (params.testType === 'disc') {
        MeDiscRunner.start(this.root, member);
      } else {
        MeTestRunner.start(this.root, params.testType, member);
      }
    } else if (view === 'result') {
      if (params.testType === 'disc') {
        MeDiscResultView.render(this.root, params.resultData);
      } else {
        MeResultView.render(this.root, params.testType, params.resultData);
      }
    }
  },

  renderLogin() {
    return `
      <div class="me-login-wrap">
        <div class="me-login-card">
          <div class="me-login-logo">SoriTune PT</div>
          <div class="me-login-subtitle">회원 페이지</div>
          <form id="meLoginForm">
            <input type="text" id="meLoginInput" class="me-input" placeholder="소리튠 아이디 또는 휴대폰번호" autocomplete="username" autofocus>
            <button type="submit" class="me-btn me-btn-primary">시작하기</button>
            <div id="meLoginError" class="me-login-error"></div>
          </form>
        </div>
      </div>
    `;
  },

  bindLogin() {
    const form = document.getElementById('meLoginForm');
    const errEl = document.getElementById('meLoginError');
    form.addEventListener('submit', async e => {
      e.preventDefault();
      errEl.textContent = '';
      const input = document.getElementById('meLoginInput').value.trim();
      if (!input) {
        errEl.textContent = '소리튠 아이디 또는 휴대폰번호를 입력해주세요';
        return;
      }
      const res = await MeAPI.post('/api/member_auth.php?action=login', { input });
      if (res.ok) {
        this.state.member = res.data.member;
        this.go('dashboard');
      } else {
        errEl.textContent = res.message || '로그인에 실패했습니다';
      }
    });
  },

  async logout() {
    await MeAPI.post('/api/member_auth.php?action=logout');
    this.state.member = null;
    this.go('login');
  },
};
