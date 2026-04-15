/**
 * Coach SPA — reuses API/UI helpers pattern from admin
 */
const CoachApp = {
  currentPage: null,
  pages: {},
  init() {
    window.addEventListener('hashchange', () => this.route());
    this.route();
  },
  route() {
    const hash = location.hash.slice(1) || 'my-members';
    const [page, ...params] = hash.split('/');
    document.querySelectorAll('.sidebar-nav a').forEach(a => {
      a.classList.toggle('active', a.dataset.page === page);
    });
    this.currentPage = page;
    const handler = this.pages[page];
    if (handler) handler.render(params);
    else document.getElementById('pageContent').innerHTML = '<div class="empty-state">페이지를 찾을 수 없습니다</div>';
  },
  registerPage(name, handler) { this.pages[name] = handler; },
};

// Reuse API and UI helpers (same as admin)
const API = {
  async request(url, options = {}) {
    const res = await fetch(url, { headers: { 'Content-Type': 'application/json', ...options.headers }, ...options });
    const data = await res.json();
    if (!data.ok && res.status === 401) location.reload();
    return data;
  },
  get(url) { return this.request(url); },
  post(url, body) { return this.request(url, { method: 'POST', body: JSON.stringify(body) }); },
};

const UI = {
  esc(str) {
    const el = document.createElement('span');
    el.textContent = str ?? '';
    return el.innerHTML;
  },
  statusBadge(s) { return `<span class="badge badge-${s}">${s}</span>`; },
  formatDate(d) { return d ? d.split(' ')[0] : '-'; },
  formatMoney(a) { return Number(a||0).toLocaleString() + '원'; },
  showModal(html) {
    const o = document.createElement('div'); o.className = 'modal-overlay';
    o.innerHTML = `<div class="modal">${html}</div>`;
    o.addEventListener('click', e => { if (e.target === o) o.remove(); });
    document.body.appendChild(o); return o;
  },
  closeModal() { document.querySelector('.modal-overlay')?.remove(); },
  confirm(m) { return window.confirm(m); },
};

async function logout() {
  await API.post('/api/auth.php?action=logout');
  location.reload();
}
