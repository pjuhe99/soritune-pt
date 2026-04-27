/**
 * Retention Management Page
 *
 * Structure: 계산 실행 카드 → 스냅샷 탭 → 요약 패널 (sticky) → 결과 테이블 → 매핑 안 된 코치 배너
 */
// Note: user-supplied strings in rows/unmapped must be escaped with UI.esc() when rendered (see Task 11).
App.registerPage('retention', {
  state: {
    baseMonth: null,           // 현재 로드된 기준월
    totalNew: 0,
    rows: [],                  // coach_retention_scores 행 배열
    unmapped: { pt_only: [], coach_site_only: [] },
    summary: { total_new: 0, sum_auto: 0, sum_final: 0, unallocated: 0 },
    // used in Task 12 for save debouncing
    pendingSaves: new Map(),   // id → timeout handle (debounce)
    inflightSaves: new Map(),  // id → Promise (serializes saves per row to avoid stale token races)
  },

  isMounted() {
    return !!document.getElementById('retentionApp');
  },

  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">리텐션관리</h1>
      </div>
      <div id="retentionApp">
        ${this.renderCalcCard()}
        <div id="retentionBody"><div class="loading">로딩 중...</div></div>
      </div>
    `;
    this.bindCalcForm();
    await this.loadSnapshots();
  },

  renderCalcCard() {
    const defaultMonth = new Date().toISOString().slice(0, 7);
    return `
      <div class="card" style="padding:16px;margin-bottom:16px">
        <form id="retentionCalcForm" class="ret-calc-form">
          <div class="form-group">
            <label class="form-label">기준월</label>
            <input type="month" id="ret_baseMonth" class="form-input" value="${defaultMonth}" required>
          </div>
          <div class="form-group">
            <label class="form-label">전체 신규 인원</label>
            <input type="number" id="ret_totalNew" class="form-input" value="0" min="0" max="10000" required>
          </div>
          <div class="form-group" style="align-self:flex-end">
            <button type="submit" class="btn btn-primary">계산 실행</button>
          </div>
        </form>
        <div id="ret_shiftHint" class="ret-shift-hint"></div>
      </div>
    `;
  },

  bindCalcForm() {
    const updateHint = () => {
      const v = document.getElementById('ret_baseMonth').value;
      document.getElementById('ret_shiftHint').innerHTML = this.shiftHintText(v);
    };
    document.getElementById('ret_baseMonth').addEventListener('change', updateHint);
    updateHint();

    document.getElementById('retentionCalcForm').addEventListener('submit', async e => {
      e.preventDefault();
      const baseMonth = document.getElementById('ret_baseMonth').value;
      const totalNew  = parseInt(document.getElementById('ret_totalNew').value, 10) || 0;
      if (!/^\d{4}-\d{2}$/.test(baseMonth)) { alert('기준월을 올바르게 선택하세요'); return; }

      const body = document.getElementById('retentionBody');
      body.innerHTML = '<div class="loading">계산 중...</div>';

      const submitBtn = e.target.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;

      try {
        const res = await API.post('/api/retention.php?action=calculate', { base_month: baseMonth, total_new: totalNew });
        if (!this.isMounted()) return;
        if (!res.ok) {
          const bodyEl = document.getElementById('retentionBody');
          if (bodyEl) {
            bodyEl.innerHTML = `<div class="card" style="padding:16px;color:var(--color-error, #c0392b)">계산 실패: ${UI.esc(res.message || '알 수 없는 오류')}</div>`;
          }
          return;
        }

        this.loadFromResponse(res.data);
        await this.loadSnapshots();
        if (!this.isMounted()) return;
        this.renderBody();
      } finally {
        if (submitBtn && this.isMounted()) submitBtn.disabled = false;
      }
    });

    if (!this._beforeunloadBound) {
      window.addEventListener('beforeunload', () => {
        // 브라우저 종료 시 best-effort (async 보장 안 됨. 네비게이션 내 이탈은 SPA가 처리)
        for (const [, h] of this.state.pendingSaves.entries()) clearTimeout(h);
      });
      this._beforeunloadBound = true;
    }
  },

  shiftHintText(baseMonthStr) {
    // baseMonthStr: "YYYY-MM"
    if (!/^\d{4}-\d{2}$/.test(baseMonthStr)) return '';
    const [y, m] = baseMonthStr.split('-').map(Number);
    const shift = (months) => {
      const d = new Date(y, m - 1 - months, 1);
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    };
    const m1 = shift(1), m2 = shift(2), m3 = shift(3);
    const m1p = shift(2), m2p = shift(3), m3p = shift(4);
    return `측정 구간: <strong>${m3p}→${m3}</strong>, <strong>${m2p}→${m2}</strong>, <strong>${m1p}→${m1}</strong> (배정월 <strong>${m1}</strong>까지 반영)`;
  },

  loadFromResponse(data) {
    this.state.baseMonth = data.base_month;
    this.state.totalNew  = data.total_new ?? data.summary?.total_new ?? 0;
    this.state.rows      = data.rows ?? [];
    this.state.unmapped  = data.unmapped_coaches ?? { pt_only: [], coach_site_only: [] };
    this.state.summary   = data.summary ?? { total_new: 0, sum_auto: 0, sum_final: 0, unallocated: 0 };
  },

  async loadSnapshots() {
    const res = await API.get('/api/retention.php?action=snapshots');
    if (!res.ok) return;
    if (!this.isMounted()) return;
    this.snapshots = res.data.snapshots || [];
    if (this.state.baseMonth) {
      this.renderBody();
    } else if (this.snapshots.length > 0) {
      await this.loadSnapshot(this.snapshots[0].base_month);
    } else {
      const body = document.getElementById('retentionBody');
      if (body) {
        body.innerHTML =
          '<div class="empty-state">아직 계산된 스냅샷이 없습니다. 기준월과 전체 신규 인원을 입력하고 계산하세요.</div>';
      }
    }
  },

  renderBody() {
    const html = `
      ${this.renderSnapshotTabs()}
      ${this.renderUnmappedBanner()}
      ${this.renderSummary()}
      ${this.renderTable()}
    `;
    document.getElementById('retentionBody').innerHTML = html;
    this.bindSnapshotTabs();
    this.bindRowDetails();
    this.bindFinalInputs();
    this.bindSummaryActions();
  },

  renderSnapshotTabs() {
    const snaps = this.snapshots || [];
    if (snaps.length === 0) return '';
    const buttons = snaps.map(s => {
      const active = s.base_month === this.state.baseMonth ? 'btn-primary' : 'btn-outline';
      return `<button class="btn btn-small ${active} ret-snap-tab" data-month="${s.base_month}">${s.base_month}</button>`;
    }).join(' ');
    return `
      <div class="card" style="padding:12px;margin-bottom:12px">
        <div class="ret-snap-row">
          <div class="ret-snap-label">저장된 스냅샷:</div>
          <div class="ret-snap-tabs">${buttons}</div>
        </div>
      </div>
    `;
  },

  renderUnmappedBanner() {
    const u = this.state.unmapped || {};
    const ptOnly = (u.pt_only || []).length;
    const coachOnly = (u.coach_site_only || []);
    if (coachOnly.length === 0 && ptOnly === 0) return '';
    const names = coachOnly.join(', ');
    return `
      <div class="ret-unmapped-banner">
        ⚠️ coach 사이트와 이름(영문)이 일치하지 않아 리텐션에서 제외된 코치:
        <strong>${UI.esc(names) || '-'}</strong>
        ${ptOnly > 0 ? ` / PT에만 존재하는 코치 ${ptOnly}명은 리텐션 0%로 표시됩니다.` : ''}
      </div>
    `;
  },

  renderSummary() {
    const s = this.state.summary;
    const unalloc = s.unallocated ?? 0;
    const colorClass = unalloc > 0 ? 'ret-unalloc-pos' : (unalloc < 0 ? 'ret-unalloc-neg' : 'ret-unalloc-zero');
    return `
      <div class="ret-summary-sticky">
        <div class="ret-summary-card">
          <div class="label">전체 신규</div>
          <div class="value">${s.total_new}명</div>
        </div>
        <div class="ret-summary-card">
          <div class="label">자동 배정 합</div>
          <div class="value">${s.sum_auto}명</div>
        </div>
        <div class="ret-summary-card">
          <div class="label">현재 합</div>
          <div class="value">${s.sum_final}명</div>
        </div>
        <div class="ret-summary-card ${colorClass}">
          <div class="label">잔여</div>
          <div class="value">${unalloc}명</div>
        </div>
        <div class="ret-summary-actions">
          <button class="btn btn-small btn-outline" id="ret_resetBtn">자동값으로 리셋</button>
          <button class="btn btn-small btn-danger" id="ret_deleteBtn">스냅샷 삭제</button>
        </div>
      </div>
    `;
  },

  renderTable() {
    if (this.state.rows.length === 0) {
      return `<div class="empty-state">아직 계산된 결과가 없습니다. 기준월과 전체 신규 인원을 입력하고 계산하세요.</div>`;
    }
    const rows = this.state.rows.map(r => this.renderTableRow(r)).join('');
    return `
      <div class="data-table-wrapper">
        <table class="data-table ret-table">
          <thead>
            <tr>
              <th>등수</th>
              <th>코치</th>
              <th>등급</th>
              <th class="text-right">총점</th>
              <th class="text-right">3M 신규</th>
              <th class="text-right">3M 기존</th>
              <th class="text-right">담당</th>
              <th class="text-right">희망</th>
              <th class="text-right">자동</th>
              <th class="text-right">최종</th>
              <th></th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    `;
  },

  renderTableRow(r) {
    const gradeBadge = this.gradeBadge(r.grade);
    const coachName = r.coach_id
      ? UI.esc(r.coach_name_snapshot)
      : `<span class="ret-deleted">${UI.esc(r.coach_name_snapshot)} (삭제됨)</span>`;
    return `
      <tr data-row-id="${r.id}">
        <td class="text-center">${r.rank_num ?? '-'}</td>
        <td><strong>${coachName}</strong></td>
        <td>${gradeBadge}</td>
        <td class="text-right">${(+r.total_score).toFixed(1)}</td>
        <td class="text-right">${(r.new_retention_3m * 100).toFixed(1)}%</td>
        <td class="text-right">${(r.existing_retention_3m * 100).toFixed(1)}%</td>
        <td class="text-right">${r.assigned_members}</td>
        <td class="text-right">${r.requested_count}</td>
        <td class="text-right">${r.auto_allocation}</td>
        <td class="text-right">
          <input type="number" class="ret-final-input" value="${r.final_allocation}" min="0" max="9999" data-id="${r.id}" data-updated="${r.updated_at}">
          <span class="ret-save-status" data-id="${r.id}"></span>
        </td>
        <td>
          <button class="btn btn-small btn-outline ret-detail-toggle" data-id="${r.id}">▶</button>
        </td>
      </tr>
      <tr class="ret-detail-row" data-for="${r.id}" style="display:none">
        <td colspan="11">${this.renderDetail(r)}</td>
      </tr>
    `;
  },

  renderDetail(r) {
    const detail = r.monthly_detail || [];
    if (detail.length === 0) return '<div style="padding:8px;color:var(--text-secondary)">상세 데이터 없음</div>';
    const rows = detail.map(d => `
      <tr>
        <td>${d.month}</td>
        <td>${d.prev_month}</td>
        <td class="text-right">${d.new_total}</td>
        <td class="text-right">${d.new_repurchase}</td>
        <td class="text-right">${(d.new_retention_rate * 100).toFixed(1)}%</td>
        <td class="text-right">${d.exist_total}</td>
        <td class="text-right">${d.exist_repurchase}</td>
        <td class="text-right">${(d.exist_retention_rate * 100).toFixed(1)}%</td>
      </tr>`).join('');
    return `
      <table class="ret-detail-table">
        <thead>
          <tr>
            <th>측정월</th><th>모수 월</th>
            <th>신규 총</th><th>신규 유지</th><th>신규 리텐션</th>
            <th>기존 총</th><th>기존 유지</th><th>기존 리텐션</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  },

  gradeBadge(grade) {
    if (!grade) return '<span class="badge">-</span>';
    const map = { 'A+': 'badge-ap', 'A': 'badge-a', 'B': 'badge-b', 'C': 'badge-c', 'D': 'badge-d' };
    const cls = map[grade] || 'badge';
    return `<span class="badge ${cls}">${grade}</span>`;
  },

  bindSnapshotTabs() {
    document.querySelectorAll('.ret-snap-tab').forEach(btn => {
      btn.addEventListener('click', async () => {
        const month = btn.dataset.month;
        await this.loadSnapshot(month);
      });
    });
  },

  bindSummaryActions() {
    const resetBtn = document.getElementById('ret_resetBtn');
    if (resetBtn) {
      resetBtn.addEventListener('click', async () => {
        if (!this.state.baseMonth) return;
        if (!confirm(`${this.state.baseMonth}의 모든 최종 배정을 자동값으로 되돌릴까요?`)) return;
        await this.flushPendingSaves();
        const res = await API.post('/api/retention.php?action=reset_allocation', { base_month: this.state.baseMonth });
        if (!res.ok) { alert(res.message || '리셋 실패'); return; }
        await this.loadSnapshot(this.state.baseMonth);
        UI.toast(`${res.data.updated_rows}개 행이 자동값으로 복원되었습니다.`);
      });
    }
    const delBtn = document.getElementById('ret_deleteBtn');
    if (delBtn) {
      delBtn.addEventListener('click', async () => {
        if (!this.state.baseMonth) return;
        if (!confirm(`${this.state.baseMonth} 스냅샷을 완전히 삭제할까요? (되돌릴 수 없습니다)`)) return;
        await this.flushPendingSaves();
        const res = await API.post('/api/retention.php?action=delete_snapshot', { base_month: this.state.baseMonth });
        if (!res.ok) { alert(res.message || '삭제 실패'); return; }
        UI.toast(`삭제 완료: ${res.data.deleted_scores}행 + 스냅샷 메타 ${res.data.deleted_runs}건`);
        this.state.baseMonth = null;
        this.state.rows = [];
        await this.loadSnapshots();
      });
    }
  },

  bindRowDetails() {
    document.querySelectorAll('.ret-detail-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const detail = document.querySelector(`.ret-detail-row[data-for="${id}"]`);
        if (!detail) return;
        const open = detail.style.display !== 'none';
        detail.style.display = open ? 'none' : '';
        btn.textContent = open ? '▶' : '▼';
      });
    });
  },

  bindFinalInputs() {
    document.querySelectorAll('.ret-final-input').forEach(input => {
      input.addEventListener('input', () => {
        this.recomputeSummary();
        this.scheduleSave(input);
      });
    });
  },

  recomputeSummary() {
    // Local sum from current inputs (before server confirms)
    let sumFinal = 0;
    document.querySelectorAll('.ret-final-input').forEach(i => {
      sumFinal += parseInt(i.value, 10) || 0;
    });
    this.state.summary.sum_final = sumFinal;
    this.state.summary.unallocated = this.state.summary.total_new - sumFinal;

    // Update DOM summary (the "현재 합" and "잔여" cards)
    const cards = document.querySelectorAll('.ret-summary-card');
    if (cards.length >= 4) {
      cards[2].querySelector('.value').textContent = `${sumFinal}명`;
      const unalloc = this.state.summary.unallocated;
      const fourth = cards[3];
      fourth.querySelector('.value').textContent = `${unalloc}명`;
      fourth.classList.remove('ret-unalloc-pos','ret-unalloc-neg','ret-unalloc-zero');
      fourth.classList.add(unalloc > 0 ? 'ret-unalloc-pos' : (unalloc < 0 ? 'ret-unalloc-neg' : 'ret-unalloc-zero'));
    }
  },

  scheduleSave(input) {
    const id = parseInt(input.dataset.id, 10);
    const status = document.querySelector(`.ret-save-status[data-id="${id}"]`);
    if (status) status.textContent = '…';

    const prev = this.state.pendingSaves.get(id);
    if (prev) clearTimeout(prev);
    const handle = setTimeout(() => this.saveAllocation(input), 600);
    this.state.pendingSaves.set(id, handle);
  },

  async saveAllocation(input) {
    const id = parseInt(input.dataset.id, 10);
    this.state.pendingSaves.delete(id);

    // Serialize per-row saves: wait for any in-flight save on this row to settle
    // so this save reads a fresh data-updated token after mergeRow runs.
    const prior = this.state.inflightSaves.get(id);
    if (prior) {
      try { await prior; } catch (_) { /* prior may have failed; we still try */ }
    }

    const promise = this._doSaveAllocation(input, id);
    this.state.inflightSaves.set(id, promise);
    try {
      await promise;
    } finally {
      // Only clear if this is still the latest in-flight (a chained save may have replaced us)
      if (this.state.inflightSaves.get(id) === promise) {
        this.state.inflightSaves.delete(id);
      }
    }
  },

  async _doSaveAllocation(input, id) {
    const status = document.querySelector(`.ret-save-status[data-id="${id}"]`);
    const value = parseInt(input.value, 10) || 0;
    const expected = input.dataset.updated;

    const res = await API.post('/api/retention.php?action=update_allocation', {
      id, final_allocation: value, expected_updated_at: expected,
    });
    if (!this.isMounted()) return;

    if (!res.ok) {
      if (status) status.innerHTML = '<span class="ret-save-err">!</span>';
      UI.toast('저장 실패: ' + (res.message || '알 수 없는 오류'));
      return;
    }
    const data = res.data;
    if (data.ok === false && data.code === 'conflict') {
      // Refresh row to server value
      this.mergeRow(data.row);
      UI.toast('다른 작업으로 갱신되었습니다. 최신값으로 로드했습니다.');
      if (status) status.innerHTML = '<span class="ret-save-err">×</span>';
      return;
    }
    // success
    this.mergeRow(data.row);
    if (data.summary) {
      this.state.summary = data.summary;
      // If user is mid-typing in any final-input, recompute locally instead of painting server summary
      // (server values are correct as of the save, but the user has further edits in flight).
      const focusedInput = document.activeElement;
      if (focusedInput && focusedInput.classList && focusedInput.classList.contains('ret-final-input')) {
        this.recomputeSummary();
      } else {
        this.refreshSummaryCards();
      }
    }
    if (status) status.innerHTML = '<span class="ret-save-ok">✓</span>';
  },

  mergeRow(row) {
    const idx = this.state.rows.findIndex(r => r.id === row.id);
    if (idx >= 0) this.state.rows[idx] = { ...this.state.rows[idx], ...row };
    // Update only the input value + data-updated attribute; don't rerender whole table.
    // Don't clobber the user's in-progress typing — only patch value when input is unfocused.
    const input = document.querySelector(`.ret-final-input[data-id="${row.id}"]`);
    if (input) {
      if (document.activeElement !== input) {
        input.value = row.final_allocation;
      }
      input.dataset.updated = row.updated_at;
    }
  },

  refreshSummaryCards() {
    const s = this.state.summary;
    const cards = document.querySelectorAll('.ret-summary-card');
    if (cards.length < 4) return;
    cards[0].querySelector('.value').textContent = `${s.total_new}명`;
    cards[1].querySelector('.value').textContent = `${s.sum_auto}명`;
    cards[2].querySelector('.value').textContent = `${s.sum_final}명`;
    cards[3].querySelector('.value').textContent = `${s.unallocated}명`;
    cards[3].classList.remove('ret-unalloc-pos','ret-unalloc-neg','ret-unalloc-zero');
    const u = s.unallocated;
    cards[3].classList.add(u > 0 ? 'ret-unalloc-pos' : (u < 0 ? 'ret-unalloc-neg' : 'ret-unalloc-zero'));
  },

  async flushPendingSaves() {
    for (const [id, handle] of this.state.pendingSaves.entries()) {
      clearTimeout(handle);
      const input = document.querySelector(`.ret-final-input[data-id="${id}"]`);
      if (input) await this.saveAllocation(input);
    }
  },

  async loadSnapshot(baseMonth) {
    const body = document.getElementById('retentionBody');
    if (!body) return;
    body.innerHTML = '<div class="loading">로딩 중...</div>';
    const res = await API.get(`/api/retention.php?action=view&base_month=${encodeURIComponent(baseMonth)}`);
    if (!this.isMounted()) return;
    const bodyEl = document.getElementById('retentionBody');
    if (!bodyEl) return;
    if (!res.ok) { bodyEl.innerHTML = `<div class="empty-state">${UI.esc(res.message || '스냅샷을 불러올 수 없습니다')}</div>`; return; }
    this.loadFromResponse(res.data);
    this.renderBody();
    const baseMonthInput = document.getElementById('ret_baseMonth');
    const totalNewInput  = document.getElementById('ret_totalNew');
    const shiftHintEl    = document.getElementById('ret_shiftHint');
    if (baseMonthInput) baseMonthInput.value = baseMonth;
    if (totalNewInput)  totalNewInput.value  = this.state.totalNew;
    if (shiftHintEl)    shiftHintEl.innerHTML = this.shiftHintText(baseMonth);
  },
});
