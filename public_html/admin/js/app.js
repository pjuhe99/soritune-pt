/**
 * Admin SPA — Router + API client + shared utilities
 */
const App = {
  currentPage: null,
  pages: {},

  init() {
    window.addEventListener('hashchange', () => this.route());
    this.route();
  },

  route() {
    const hash = location.hash.slice(1) || 'members';
    const [page, ...params] = hash.split('/');

    // Update sidebar active state
    document.querySelectorAll('.sidebar-nav a').forEach(a => {
      a.classList.toggle('active', a.dataset.page === page);
    });

    toggleSidebar(false); // 모바일에서 메뉴 클릭 후 자동 닫힘
    this.currentPage = page;
    const handler = this.pages[page];
    if (handler) {
      handler.render(params);
    } else {
      document.getElementById('pageContent').innerHTML =
        '<div class="empty-state">페이지를 찾을 수 없습니다</div>';
    }
  },

  registerPage(name, handler) {
    this.pages[name] = handler;
  },
};

/**
 * API client
 */
const API = {
  async request(url, options = {}) {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json', ...options.headers },
      ...options,
    });
    const data = await res.json();
    if (!data.ok && res.status === 401) {
      location.reload(); // Session expired
    }
    return data;
  },

  get(url) { return this.request(url); },

  post(url, body) {
    return this.request(url, { method: 'POST', body: JSON.stringify(body) });
  },

  put(url, body) {
    return this.request(url, { method: 'POST', body: JSON.stringify(body) });
  },

  delete(url) {
    return this.request(url, { method: 'POST', body: JSON.stringify({ _method: 'DELETE' }) });
  },

  upload(url, formData) {
    return fetch(url, { method: 'POST', body: formData }).then(r => r.json());
  },
};

/**
 * UI Helpers
 */
const UI = {
  $(id) { return document.getElementById(id); },

  esc(str) {
    const el = document.createElement('span');
    el.textContent = str ?? '';
    return el.innerHTML;
  },

  statusBadge(status) {
    return `<span class="badge badge-${status}">${status}</span>`;
  },

  formatDate(dateStr) {
    if (!dateStr) return '-';
    return dateStr.split(' ')[0]; // YYYY-MM-DD
  },

  formatMoney(amount) {
    return Number(amount || 0).toLocaleString() + '원';
  },

  showModal(html) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `<div class="modal">${html}</div>`;
    overlay.addEventListener('click', e => {
      if (e.target === overlay) overlay.remove();
    });
    document.body.appendChild(overlay);
    return overlay;
  },

  closeModal() {
    document.querySelector('.modal-overlay')?.remove();
  },

  confirm(message) {
    return window.confirm(message);
  },

  toast(message) {
    // Simple toast — could be enhanced later
    alert(message);
  },
};

async function logout() {
  await API.post('/api/auth.php?action=logout');
  location.reload();
}

/**
 * 모바일 사이드바 토글. force=true이면 강제 열기, false 강제 닫기, 생략 시 토글.
 */
function toggleSidebar(force) {
  const sb = document.querySelector('.sidebar');
  const bd = document.querySelector('.sidebar-backdrop');
  if (!sb || !bd) return;
  const wantOpen = force === undefined ? !sb.classList.contains('open') : !!force;
  sb.classList.toggle('open', wantOpen);
  bd.classList.toggle('open', wantOpen);
}
