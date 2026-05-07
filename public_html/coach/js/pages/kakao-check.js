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
  includeProcessed: false,

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
          <input type="checkbox" ${this.includeProcessed ? 'checked' : ''}
                 onchange="CoachApp.pages['kakao-check'].toggleIncludeProcessed(this.checked)">
          처리 완료도 보기
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

  toggleIncludeProcessed(checked) {
    this.includeProcessed = checked;
    this.loadList();
  },

  async loadList() {
    const container = document.getElementById('kakaoList');
    container.innerHTML = '<div class="loading">불러오는 중...</div>';

    const params = new URLSearchParams({
      action: 'list',
      cohort: this.selectedCohort,
      include_processed: this.includeProcessed ? '1' : '0',
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
            <th style="width:32px" title="카톡방 입장">입장</th>
            <th style="width:32px" title="쿠폰 지급">쿠폰</th>
            <th style="width:60px" title="특이 건">특이</th>
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
    const kakaoOn = parseInt(o.kakao_room_joined, 10) === 1;
    const couponOn = parseInt(o.coupon_issued, 10) === 1;
    const specialOn = parseInt(o.special_case, 10) === 1;
    const dim = kakaoOn || couponOn || specialOn;

    const note = (o.special_case_note || '').trim();
    const noteShort = note.length > 16 ? note.slice(0, 16) + '…' : note;
    const noteHtml = specialOn
      ? `<small style="display:block; color:#888; cursor:pointer; font-size:11px;"
                title="${UI.esc(note)}"
                onclick="CoachApp.pages['kakao-check'].editSpecialNote(${o.order_id})">${UI.esc(noteShort) || '메모 없음'}</small>`
      : '';

    const prevCoachId = o.prev_coach_id != null ? parseInt(o.prev_coach_id, 10) : null;
    const curCoachId = o.coach_id != null ? parseInt(o.coach_id, 10) : null;
    const isReturning = prevCoachId !== null && curCoachId !== null && prevCoachId === curCoachId;
    const badge = isReturning
      ? '<span class="badge-returning">기존</span>'
      : '<span class="badge-new">신규</span>';

    return `
      <tr id="kakao-row-${o.order_id}" style="${dim ? 'opacity:0.55' : ''}">
        <td><input type="checkbox" ${kakaoOn ? 'checked' : ''}
                   onclick="CoachApp.pages['kakao-check'].toggleFlag(${o.order_id}, 'kakao', this.checked)"></td>
        <td><input type="checkbox" ${couponOn ? 'checked' : ''}
                   onclick="CoachApp.pages['kakao-check'].toggleFlag(${o.order_id}, 'coupon', this.checked)"></td>
        <td>
          <input type="checkbox" ${specialOn ? 'checked' : ''}
                 onclick="CoachApp.pages['kakao-check'].toggleFlag(${o.order_id}, 'special', this.checked)">
          ${noteHtml}
        </td>
        <td>${badge} ${UI.esc(o.name)}</td>
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
    // include_processed=false면 fade out 후 행 제거
    if (!this.includeProcessed && joined) {
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

  async toggleFlag(orderId, flag, checked) {
    const row = document.getElementById(`kakao-row-${orderId}`);
    const checkbox = row?.querySelector(`td input[type=checkbox][onclick*="'${flag}'"]`);

    let note = null;
    if (flag === 'special' && checked) {
      note = prompt('특이 사유를 입력하세요 (없으면 비워두세요)', '');
      if (note === null) {
        if (checkbox) checkbox.checked = false;
        return;
      }
    }

    const res = await API.post('/api/kakao_check.php?action=toggle_flag', {
      order_id: orderId, flag, value: checked ? 1 : 0, note,
    });
    if (!res.ok) {
      alert(res.message || '실패');
      if (checkbox) checkbox.checked = !checked;
      return;
    }

    if (!this.includeProcessed && checked) {
      if (row) {
        row.style.transition = 'opacity 0.3s';
        row.style.opacity = '0';
        setTimeout(() => this.loadList(), 320);
      }
    } else {
      this.loadList();
    }
  },

  async editSpecialNote(orderId) {
    const row = document.getElementById(`kakao-row-${orderId}`);
    const small = row?.querySelector('td:nth-child(3) small');
    const currentNote = small?.getAttribute('title') || '';
    const note = prompt('특이 사유를 입력하세요 (없으면 비워두세요)', currentNote);
    if (note === null) return;

    const res = await API.post('/api/kakao_check.php?action=toggle_flag', {
      order_id: orderId, flag: 'special', value: 1, note,
    });
    if (!res.ok) { alert(res.message || '실패'); return; }
    this.loadList();
  },
});
