/**
 * 카톡방 입장 체크 (어드민 사이드)
 * 코치 페이지 베이스 + 코치 필터 + 다중 선택 + bulk cohort override.
 */
App.registerPage('kakao-check', {
  STATUSES: ['진행중', '진행예정'],
  cohorts: [],
  coaches: [],
  selectedCohort: null,
  selectedCoachId: '',
  selectedStatuses: new Set(['진행중', '진행예정']),
  selectedProduct: '',
  includeJoined: false,
  selectedOrderIds: new Set(),
  bulkCohort: '',

  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">카톡방 입장 체크</h1></div>
      <div class="filters">
        <select class="filter-pill" id="adminCoachFilter"
                onchange="App.pages['kakao-check'].setCoach(this.value)">
          <option value="">전체 코치</option>
        </select>
      </div>
      <div id="cohortTabs" class="filters"></div>
      <div id="kakaoFilters"></div>
      <div id="kakaoList"><div class="loading">불러오는 중...</div></div>
      <div id="bulkActionBar"></div>
    `;
    await Promise.all([this.loadCoaches(), this.loadCohorts()]);
  },

  async loadCoaches() {
    const res = await API.get('/api/coaches.php?action=list');
    if (!res.ok) return;
    this.coaches = (res.data.coaches || []).filter(c => c.status === 'active');
    const sel = document.getElementById('adminCoachFilter');
    if (sel) {
      sel.innerHTML = '<option value="">전체 코치</option>' +
        this.coaches.map(c => `<option value="${c.id}">${UI.esc(c.coach_name)}</option>`).join('');
    }
  },

  async setCoach(value) {
    this.selectedCoachId = value;
    this.selectedOrderIds.clear();
    await this.loadCohorts();
  },

  async loadCohorts() {
    const params = new URLSearchParams({ action: 'cohorts' });
    if (this.selectedCoachId) params.set('coach_id', this.selectedCoachId);
    const res = await API.get(`/api/kakao_check.php?${params}`);
    if (!res.ok) {
      document.getElementById('kakaoList').innerHTML = `<div class="empty-state">${UI.esc(res.message)}</div>`;
      return;
    }
    this.cohorts = res.data.cohorts || [];
    if (this.cohorts.length === 0) {
      document.getElementById('cohortTabs').innerHTML = '';
      document.getElementById('kakaoFilters').innerHTML = '';
      document.getElementById('kakaoList').innerHTML = '<div class="empty-state">조건에 맞는 데이터가 없습니다</div>';
      this.renderBulkBar();
      return;
    }
    if (!this.cohorts.includes(this.selectedCohort)) {
      this.selectedCohort = this.pickDefaultCohort(this.cohorts);
    }
    this.renderCohortTabs();
    this.renderFilters();
    await this.loadList();
  },

  // *** CORRECTION 1 vs plan: KST default-month formula fix ***
  // The original plan formula had a double-offset bug for KST users (last day of month after 06:00 KST).
  // Use the corrected version (matches Task 8 fix commit 062e068).
  pickDefaultCohort(cohorts) {
    // KST 현재 월 산출: UTC에 +9h 더한 뒤 UTC 메서드로 읽으면 timezone agnostic
    const now = new Date();
    const kst = new Date(now.getTime() + 9 * 60 * 60 * 1000);
    const cur = kst.getUTCFullYear() + '-' + String(kst.getUTCMonth() + 1).padStart(2, '0');
    if (cohorts.includes(cur)) return cur;
    const future = cohorts.filter(c => c > cur).sort();
    if (future.length) return future[0];
    const past = cohorts.filter(c => c < cur).sort().reverse();
    return past[0];
  },

  // *** CORRECTION 2 vs plan: cohort label escape ***
  // Wrap cohort string in UI.esc() in both onclick and button text (defense-in-depth XSS).
  renderCohortTabs() {
    const html = this.cohorts.map(c => `
      <button class="filter-pill ${c === this.selectedCohort ? 'active' : ''}"
              onclick="App.pages['kakao-check'].selectCohort('${UI.esc(c)}')">${UI.esc(c)}</button>
    `).join('');
    document.getElementById('cohortTabs').innerHTML = html;
  },

  selectCohort(cohort) {
    this.selectedCohort = cohort;
    this.selectedProduct = '';
    this.selectedOrderIds.clear();
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
                  onclick="App.pages['kakao-check'].toggleStatus('${s}')">${s}</button>
        `).join('')}
        <select class="filter-pill" id="kakaoProductFilter"
                onchange="App.pages['kakao-check'].setProduct(this.value)">
          <option value="">전체 상품</option>
        </select>
        <label style="margin-left:auto; display:inline-flex; align-items:center; gap:6px;">
          <input type="checkbox" ${this.includeJoined ? 'checked' : ''}
                 onchange="App.pages['kakao-check'].toggleIncludeJoined(this.checked)">
          체크 완료도 보기
        </label>
      </div>
    `;
  },

  toggleStatus(s) {
    if (this.selectedStatuses.has(s)) {
      if (this.selectedStatuses.size === 1) return;
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
    if (this.selectedCoachId) params.set('coach_id', this.selectedCoachId);
    if (this.selectedProduct) params.set('product', this.selectedProduct);

    const res = await API.get(`/api/kakao_check.php?${params}`);
    if (!res.ok) { container.innerHTML = `<div class="empty-state">${UI.esc(res.message)}</div>`; return; }

    const sel = document.getElementById('kakaoProductFilter');
    if (sel) {
      sel.innerHTML = '<option value="">전체 상품</option>' +
        res.data.products.map(p =>
          `<option value="${UI.esc(p)}" ${p === this.selectedProduct ? 'selected' : ''}>${UI.esc(p)}</option>`
        ).join('');
    }

    const orders = (res.data.orders || []).filter(o => this.selectedStatuses.has(o.display_status));
    if (orders.length === 0) {
      container.innerHTML = '<div class="empty-state">조건에 맞는 회원이 없습니다</div>';
      this.renderBulkBar();
      return;
    }

    container.innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:32px"><input type="checkbox" id="kakaoSelectAll"
                onclick="App.pages['kakao-check'].toggleSelectAll(this.checked)"></th>
            <th style="width:32px"></th>
            <th>이름</th>
            <th>전화번호</th>
            <th>이메일</th>
            <th>상품</th>
            <th>코치</th>
            <th>시작일</th>
            <th>상태</th>
            <th>코호트</th>
          </tr>
        </thead>
        <tbody>
          ${orders.map(o => this._row(o)).join('')}
        </tbody>
      </table>
      <div style="margin-top:8px; color:var(--text-secondary); font-size:13px;">${orders.length}명</div>
    `;
    this.renderBulkBar();
  },

  _row(o) {
    const checked = parseInt(o.kakao_room_joined, 10) === 1;
    const selected = this.selectedOrderIds.has(o.order_id);
    const overrideMark = o.cohort_month_override ? ' <span style="color:#888;font-size:11px;">(override)</span>' : '';
    return `
      <tr id="kakao-row-${o.order_id}" style="${checked ? 'opacity:0.55' : ''}">
        <td><input type="checkbox" ${selected ? 'checked' : ''}
                   onclick="App.pages['kakao-check'].toggleSelect(${o.order_id}, this.checked)"></td>
        <td><input type="checkbox" ${checked ? 'checked' : ''}
                   onclick="App.pages['kakao-check'].toggleJoin(${o.order_id}, this.checked)"></td>
        <td>${UI.esc(o.name)}</td>
        <td style="color:var(--text-secondary)">${UI.esc(o.phone) || '-'}</td>
        <td style="color:var(--text-secondary)">${UI.esc(o.email) || '-'}</td>
        <td>${UI.esc(o.product_name)}</td>
        <td>${UI.esc(o.coach_name) || '-'}</td>
        <td>${UI.formatDate(o.start_date)}</td>
        <td>${UI.statusBadge(o.display_status)}</td>
        <td>${UI.esc(o.effective_cohort)}${overrideMark}</td>
      </tr>
    `;
  },

  toggleSelect(orderId, checked) {
    if (checked) this.selectedOrderIds.add(orderId);
    else this.selectedOrderIds.delete(orderId);
    this.renderBulkBar();
  },

  toggleSelectAll(checked) {
    document.querySelectorAll('#kakaoList tbody tr').forEach(tr => {
      const cb = tr.querySelector('td:first-child input[type=checkbox]');
      if (!cb) return;
      cb.checked = checked;
      const id = parseInt(tr.id.replace('kakao-row-', ''), 10);
      if (checked) this.selectedOrderIds.add(id);
      else this.selectedOrderIds.delete(id);
    });
    this.renderBulkBar();
  },

  // *** CORRECTION 3: also escape cohort in bulk bar dropdown options ***
  renderBulkBar() {
    const bar = document.getElementById('bulkActionBar');
    if (!bar) return;
    const n = this.selectedOrderIds.size;
    if (n === 0) { bar.innerHTML = ''; return; }
    const cohortOptions = this.cohorts.map(c =>
      `<option value="${UI.esc(c)}" ${c === this.bulkCohort ? 'selected' : ''}>${UI.esc(c)}</option>`
    ).join('');
    bar.innerHTML = `
      <div style="position:sticky; bottom:0; background:var(--surface); border-top:1px solid var(--border); padding:12px;
                  display:flex; align-items:center; gap:12px; box-shadow:0 -2px 6px rgba(0,0,0,0.4); z-index:10;">
        <strong>${n}건 선택됨</strong>
        <select class="filter-pill" onchange="App.pages['kakao-check'].setBulkCohort(this.value)">
          <option value="">목적지 월 선택…</option>
          ${cohortOptions}
        </select>
        <button class="btn btn-primary btn-small" onclick="App.pages['kakao-check'].applyBulk()">적용</button>
        <button class="btn btn-outline btn-small" onclick="App.pages['kakao-check'].applyRestore()">원래대로(자동)</button>
        <button class="btn btn-outline btn-small" style="margin-left:auto;"
                onclick="App.pages['kakao-check'].clearSelection()">선택 해제</button>
      </div>
    `;
  },

  setBulkCohort(value) {
    this.bulkCohort = value;
  },

  clearSelection() {
    this.selectedOrderIds.clear();
    this.loadList();
  },

  async applyBulk() {
    if (!this.bulkCohort) { alert('목적지 월을 선택하세요'); return; }
    if (!confirm(`${this.selectedOrderIds.size}건을 ${this.bulkCohort} 코호트로 이동합니다`)) return;
    await this._setCohort(this.bulkCohort);
  },

  async applyRestore() {
    if (!confirm(`${this.selectedOrderIds.size}건의 cohort override를 해제 (자동 분류로 복원)합니다`)) return;
    await this._setCohort(null);
  },

  async _setCohort(cohortMonth) {
    const orderIds = Array.from(this.selectedOrderIds);
    const res = await API.post('/api/kakao_check.php?action=set_cohort', {
      order_ids: orderIds,
      cohort_month: cohortMonth,
    });
    if (!res.ok) { alert(res.message || '실패'); return; }
    this.selectedOrderIds.clear();
    this.bulkCohort = '';
    await this.loadCohorts();
  },

  async toggleJoin(orderId, joined) {
    const row = document.getElementById(`kakao-row-${orderId}`);
    const res = await API.post('/api/kakao_check.php?action=toggle_join', { order_id: orderId, joined });
    if (!res.ok) {
      alert(res.message || '실패');
      if (row) {
        // 입장 체크박스 (두 번째 td)만 원복
        const cb = row.querySelector('td:nth-child(2) input[type=checkbox]');
        if (cb) cb.checked = !joined;
      }
      return;
    }
    if (!this.includeJoined && joined) {
      if (row) {
        row.style.transition = 'opacity 0.3s';
        row.style.opacity = '0';
        setTimeout(() => this.loadList(), 320);
      }
    } else {
      if (row) row.style.opacity = joined ? '0.55' : '1';
    }
  },
});
