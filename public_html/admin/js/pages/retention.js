/**
 * Retention Management Page
 *
 * Structure: 계산 실행 카드 → 스냅샷 탭 → 요약 패널 (sticky) → 결과 테이블 → 매핑 안 된 코치 배너
 */
App.registerPage('retention', {
  state: {
    baseMonth: null,           // 현재 로드된 기준월
    totalNew: 0,
    rows: [],                  // coach_retention_scores 행 배열
    unmapped: { pt_only: [], coach_site_only: [] },
    summary: { total_new: 0, sum_auto: 0, sum_final: 0, unallocated: 0 },
    pendingSaves: new Map(),   // id → timeout handle (debounce)
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

      const res = await API.post('/api/retention.php?action=calculate', { base_month: baseMonth, total_new: totalNew });
      if (!res.ok) { alert(res.message || '계산 실패'); return; }

      this.loadFromResponse(res.data);
      await this.loadSnapshots();
      this.renderBody();
    });
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
    this.state.rows      = data.rows || [];
    this.state.unmapped  = data.unmapped_coaches || { pt_only: [], coach_site_only: [] };
    this.state.summary   = data.summary || { total_new: 0, sum_auto: 0, sum_final: 0, unallocated: 0 };
  },

  async loadSnapshots() {
    const res = await API.get('/api/retention.php?action=snapshots');
    if (!res.ok) return;
    this.snapshots = res.data.snapshots || [];
    // Table 실제 렌더링은 다음 태스크에서
  },

  renderBody() {
    // 이 태스크에서는 간단히 "계산 완료" 표시만
    document.getElementById('retentionBody').innerHTML =
      `<div class="card" style="padding:16px">
        <strong>${this.state.baseMonth}</strong> 계산 완료 — ${this.state.rows.length}명, 총 ${this.state.summary.total_new}명 신규
      </div>`;
  },
});
