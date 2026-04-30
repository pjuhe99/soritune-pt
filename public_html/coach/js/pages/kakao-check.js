/**
 * 카톡방 입장 체크 (코치 사이드)
 * 본인 회원의 카톡방 입장을 월 단위로 체크.
 */
CoachApp.registerPage('kakao-check', {
  STATUSES: ['진행중', '진행예정'],
  cohorts: [],
  selectedCohort: null,
  selectedStatuses: new Set(['진행중', '진행예정']),
  selectedProduct: '',
  includeJoined: false,

  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">카톡방 입장 체크</h1></div>
      <div id="cohortTabs" class="filters"></div>
      <div id="kakaoFilters"></div>
      <div id="kakaoList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadCohorts();
  },

  async loadCohorts() {
    const res = await API.get('/api/kakao_check.php?action=cohorts');
    if (!res.ok) {
      document.getElementById('kakaoList').innerHTML = `<div class="empty-state">${UI.esc(res.message)}</div>`;
      return;
    }
    this.cohorts = res.data.cohorts || [];
    if (this.cohorts.length === 0) {
      document.getElementById('cohortTabs').innerHTML = '';
      document.getElementById('kakaoFilters').innerHTML = '';
      document.getElementById('kakaoList').innerHTML = '<div class="empty-state">현재 카톡방 입장 체크 대상이 없습니다</div>';
      return;
    }
    this.selectedCohort = this.pickDefaultCohort(this.cohorts);
    this.renderCohortTabs();
    this.renderFilters();
    await this.loadList();
  },

  pickDefaultCohort(cohorts) {
    // KST 현재 월 산출: UTC에 +9h 더한 뒤 UTC 메서드로 읽으면 timezone agnostic
    const now = new Date();
    const kst = new Date(now.getTime() + 9 * 60 * 60 * 1000);
    const cur = kst.getUTCFullYear() + '-' + String(kst.getUTCMonth() + 1).padStart(2, '0');
    if (cohorts.includes(cur)) return cur;
    const future = cohorts.filter(c => c > cur).sort();
    if (future.length) return future[0];
    const past = cohorts.filter(c => c < cur).sort().reverse();
    return past[0]; // cohorts.length > 0이 보장됨
  },

  renderCohortTabs() {
    const html = this.cohorts.map(c => `
      <button class="filter-pill ${c === this.selectedCohort ? 'active' : ''}"
              onclick="CoachApp.pages['kakao-check'].selectCohort('${UI.esc(c)}')">${UI.esc(c)}</button>
    `).join('');
    document.getElementById('cohortTabs').innerHTML = html;
  },

  selectCohort(cohort) {
    this.selectedCohort = cohort;
    this.selectedProduct = '';
    this.renderCohortTabs();
    this.renderFilters();
    this.loadList();
  },

  renderFilters() {
    document.getElementById('kakaoFilters').innerHTML = `
      <div class="filters">
        ${this.STATUSES.map(s => `
          <button class="filter-pill ${this.selectedStatuses.has(s) ? 'active' : ''}"
                  data-status="${s}"
                  onclick="CoachApp.pages['kakao-check'].toggleStatus('${s}')">${s}</button>
        `).join('')}
        <select class="filter-pill" id="kakaoProductFilter"
                onchange="CoachApp.pages['kakao-check'].setProduct(this.value)">
          <option value="">전체 상품</option>
        </select>
        <label style="margin-left:auto; display:inline-flex; align-items:center; gap:6px;">
          <input type="checkbox" ${this.includeJoined ? 'checked' : ''}
                 onchange="CoachApp.pages['kakao-check'].toggleIncludeJoined(this.checked)">
          체크 완료도 보기
        </label>
      </div>
    `;
  },

  toggleStatus(s) {
    if (this.selectedStatuses.has(s)) {
      if (this.selectedStatuses.size === 1) return; // 최소 1개 가드
      this.selectedStatuses.delete(s);
    } else {
      this.selectedStatuses.add(s);
    }
    this.renderFilters();
    this.loadList();
  },

  setProduct(value) {
    this.selectedProduct = value;
    this.loadList();
  },

  toggleIncludeJoined(checked) {
    this.includeJoined = checked;
    this.loadList();
  },

  async loadList() {
    const container = document.getElementById('kakaoList');
    container.innerHTML = '<div class="loading">불러오는 중...</div>';

    const params = new URLSearchParams({
      action: 'list',
      cohort: this.selectedCohort,
      include_joined: this.includeJoined ? '1' : '0',
    });
    if (this.selectedProduct) params.set('product', this.selectedProduct);

    const res = await API.get(`/api/kakao_check.php?${params}`);
    if (!res.ok) { container.innerHTML = `<div class="empty-state">${UI.esc(res.message)}</div>`; return; }

    // products 드롭다운 갱신 (월 변경시 다른 셋)
    const sel = document.getElementById('kakaoProductFilter');
    if (sel) {
      sel.innerHTML = '<option value="">전체 상품</option>' +
        res.data.products.map(p =>
          `<option value="${UI.esc(p)}" ${p === this.selectedProduct ? 'selected' : ''}>${UI.esc(p)}</option>`
        ).join('');
    }

    // status 클라이언트 사이드 필터
    const orders = (res.data.orders || []).filter(o => this.selectedStatuses.has(o.display_status));
    if (orders.length === 0) {
      container.innerHTML = '<div class="empty-state">조건에 맞는 회원이 없습니다</div>';
      return;
    }

    container.innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:32px"></th>
            <th>이름</th>
            <th>전화번호</th>
            <th>이메일</th>
            <th>상품</th>
            <th>시작일</th>
            <th>상태</th>
          </tr>
        </thead>
        <tbody>
          ${orders.map(o => this._row(o)).join('')}
        </tbody>
      </table>
      <div style="margin-top:8px; color:var(--text-secondary); font-size:13px;">
        ${orders.length}명
      </div>
    `;
  },

  _row(o) {
    const checked = parseInt(o.kakao_room_joined, 10) === 1;
    return `
      <tr id="kakao-row-${o.order_id}" style="${checked ? 'opacity:0.55' : ''}">
        <td><input type="checkbox" ${checked ? 'checked' : ''}
                   onclick="CoachApp.pages['kakao-check'].toggleJoin(${o.order_id}, this.checked)"></td>
        <td>${UI.esc(o.name)}</td>
        <td style="color:var(--text-secondary)">${UI.esc(o.phone) || '-'}</td>
        <td style="color:var(--text-secondary)">${UI.esc(o.email) || '-'}</td>
        <td>${UI.esc(o.product_name)}</td>
        <td>${UI.formatDate(o.start_date)}</td>
        <td>${UI.statusBadge(o.display_status)}</td>
      </tr>
    `;
  },

  async toggleJoin(orderId, joined) {
    const row = document.getElementById(`kakao-row-${orderId}`);
    const res = await API.post('/api/kakao_check.php?action=toggle_join', { order_id: orderId, joined });
    if (!res.ok) {
      alert(res.message || '실패');
      // 체크박스 상태 원복
      if (row) row.querySelector('input[type=checkbox]').checked = !joined;
      return;
    }
    // include_joined=false면 fade out 후 행 제거
    if (!this.includeJoined && joined) {
      if (row) {
        row.style.transition = 'opacity 0.3s';
        row.style.opacity = '0';
        setTimeout(() => this.loadList(), 320);
      }
    } else {
      // 그 외엔 단순히 행 스타일 갱신
      if (row) row.style.opacity = joined ? '0.55' : '1';
    }
  },
});
