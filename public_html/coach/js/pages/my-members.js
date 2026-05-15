CoachApp.registerPage('my-members', {
  STATUSES: ['진행중', '진행예정', '매칭대기', '연기', '중단', '환불', '종료'],
  selected: new Set(['진행중']),
  selectedProduct: '',

  async render() {
    const pillsHtml = this.STATUSES.map(s => `
      <button class="filter-pill ${this.selected.has(s) ? 'active' : ''}"
              data-status="${s}"
              onclick="CoachApp.pages['my-members'].toggle('${s}')">${s}</button>
    `).join('');

    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">내 회원</h1></div>
      <div class="filters" id="statusFilters">${pillsHtml}</div>
      <div class="filters">
        <select class="filter-pill" id="productFilter" onchange="CoachApp.pages['my-members'].setProduct(this.value)">
          <option value="">전체 상품</option>
        </select>
      </div>
      <div id="myMemberList"><div class="loading">불러오는 중...</div></div>
    `;

    await this.loadProducts();
    await this.loadList();
  },

  async loadProducts() {
    const res = await API.get('/api/members.php?action=active_products');
    if (!res.ok) return;
    const sel = document.getElementById('productFilter');
    if (!sel) return;
    const current = this.selectedProduct;
    sel.innerHTML = '<option value="">전체 상품</option>' +
      res.data.products.map(p => `<option value="${UI.esc(p)}" ${p === current ? 'selected' : ''}>${UI.esc(p)}</option>`).join('');
  },

  toggle(status) {
    if (this.selected.has(status)) this.selected.delete(status);
    else this.selected.add(status);

    document.querySelectorAll('#statusFilters .filter-pill').forEach(el => {
      el.classList.toggle('active', this.selected.has(el.dataset.status));
    });
    this.loadList();
  },

  setProduct(value) {
    this.selectedProduct = value;
    this.loadList();
  },

  async loadList() {
    const container = document.getElementById('myMemberList');
    container.innerHTML = '<div class="loading">불러오는 중...</div>';

    const params = new URLSearchParams({ action: 'list' });
    if (this.selected.size > 0) {
      params.set('status', Array.from(this.selected).join(','));
    }
    if (this.selectedProduct) {
      params.set('product', this.selectedProduct);
    }

    const res = await API.get(`/api/members.php?${params}`);
    if (!res.ok) { container.innerHTML = `<div class="empty-state">${res.message}</div>`; return; }
    const members = res.data.members;

    if (members.length === 0) {
      container.innerHTML = '<div class="empty-state">조건에 맞는 회원이 없습니다</div>';
      return;
    }

    container.innerHTML = `
      <table class="data-table">
        <thead><tr><th>이름</th><th>전화번호</th><th>진행 중 상품</th><th>상태</th><th>PT건수</th><th>진도율</th><th>개선율</th><th></th></tr></thead>
        <tbody>
          ${members.map(m => `
            <tr style="cursor:pointer" onclick="location.hash='member-chart/${m.id}'">
              <td>${UI.esc(m.name)}</td>
              <td style="color:var(--text-secondary)">${UI.esc(m.phone) || '-'}</td>
              <td style="color:var(--text-secondary)">${UI.esc(m.current_products) || '-'}</td>
              <td>${UI.statusBadge(m.display_status)}</td>
              <td>${m.order_count}</td>
              <td>${m.metrics && m.metrics.total > 0 ? (m.metrics.progress_rate * 100).toFixed(0) + '%' : '-'}</td>
              <td>${m.metrics && m.metrics.solution_total > 0 ? (m.metrics.improvement_rate * 100).toFixed(0) + '%' : '-'}</td>
              <td><span style="color:var(--text-secondary)">→</span></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  },
});
