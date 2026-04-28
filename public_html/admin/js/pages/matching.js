/* PT 매칭 관리 페이지 — '#matching' 라우트
 *
 * 기능:
 *  - active draft 없으면: base_month 드롭다운 + preview 카운트 + "매칭 실행" 버튼
 *  - active draft 있으면: capacity 진행도 카드 + 테이블(드롭다운 인라인 편집) + 확정/폐기
 *
 * API: /api/matching.php?action=...
 * UI helpers: API.get/post, UI.esc/toast, App.registerPage
 */

App.registerPage('matching', {
  state: {
    current: null,          // {run, drafts} 또는 null
    coaches: [],            // 드롭다운용 active 코치 전체
  },

  isMounted() {
    return document.getElementById('pageContent')?.dataset.page === 'matching';
  },

  async render() {
    const root = document.getElementById('pageContent');
    root.dataset.page = 'matching';
    root.innerHTML = '<div class="loading">로딩 중...</div>';

    try {
      const [coachesRes, currentRes] = await Promise.all([
        API.get('/api/coaches.php?action=list'),
        API.get('/api/matching.php?action=current'),
      ]);
      if (!this.isMounted()) return;
      if (!coachesRes.ok || !currentRes.ok) {
        root.innerHTML = `<div class="empty-state">데이터 로드 실패</div>`;
        return;
      }
      this.state.coaches = (coachesRes.data.coaches || []).filter(c => c.status === 'active');
      this.state.current = currentRes.data.current;
      this.renderBody();
    } catch (e) {
      root.innerHTML = `<div class="empty-state">${UI.esc(e.message || '오류')}</div>`;
    }
  },

  renderBody() {
    const root = document.getElementById('pageContent');
    if (!root) return;
    if (!this.state.current) {
      this.renderEmptyState(root);
    } else {
      this.renderDraftReview(root);
    }
  },

  async renderEmptyState(root) {
    const [previewRes, monthsRes] = await Promise.all([
      API.get('/api/matching.php?action=preview'),
      API.get('/api/matching.php?action=base_months'),
    ]);
    if (!this.isMounted()) return;
    const unmatchedCount = (previewRes.ok && previewRes.data.unmatched_orders) || 0;
    const months = (monthsRes.ok && monthsRes.data.base_months) || [];

    const monthOpts = months.map(m => `<option value="${UI.esc(m)}">${UI.esc(m)}</option>`).join('');
    const canStart = unmatchedCount > 0 && months.length > 0;

    root.innerHTML = `
      <div class="page-header"><h1>매칭 관리</h1></div>
      <div class="match-empty-state">
        <div class="match-empty-stat">
          <div class="value">${unmatchedCount}</div>
          <div class="label">매칭대기 주문</div>
        </div>
        <div class="match-empty-form">
          <label>기준월 (final_allocation 사용):</label>
          <select id="match_baseMonth" ${months.length===0 ? 'disabled' : ''}>
            ${months.length===0 ? '<option>(리텐션 스냅샷 없음)</option>' : monthOpts}
          </select>
          <button id="match_startBtn" class="btn btn-primary" ${canStart ? '' : 'disabled'}>
            매칭 실행
          </button>
          ${unmatchedCount===0 ? '<p class="hint">매칭대기 상태의 주문이 없습니다.</p>' : ''}
          ${months.length===0 ? '<p class="hint">기준월로 사용할 리텐션 스냅샷이 없습니다. 먼저 리텐션 관리에서 계산해주세요.</p>' : ''}
        </div>
      </div>
    `;

    if (canStart) {
      document.getElementById('match_startBtn').addEventListener('click', async (e) => {
        e.target.disabled = true;
        e.target.textContent = '실행 중...';
        const baseMonth = document.getElementById('match_baseMonth').value;
        const res = await API.post('/api/matching.php?action=start', { base_month: baseMonth });
        if (!this.isMounted()) return;
        if (!res.ok) {
          UI.toast(res.message || '매칭 실행 실패');
          e.target.disabled = false;
          e.target.textContent = '매칭 실행';
          return;
        }
        // 응답이 current 형태로 옴
        this.state.current = res.data.current;
        this.renderBody();
      });
    }
  },
  renderDraftReview(root) {
    const cur = this.state.current;
    const run = cur.run;
    const drafts = cur.drafts;

    // (capacity 카드는 Task 10에서, 확정/취소 버튼은 Task 11에서. 우선 테이블 위주.)
    root.innerHTML = `
      <div class="page-header"><h1>매칭 관리 · Batch #${run.id}</h1></div>
      <div class="match-summary-bar">
        <span>기준월: <strong>${UI.esc(run.base_month)}</strong></span>
        <span>총 ${run.total_orders}</span>
        <span class="match-source-prev">이전코치 ${run.prev_coach_count}</span>
        <span class="match-source-pool">신규풀 ${run.new_pool_count}</span>
        <span class="match-source-unmatched">미매칭 ${run.unmatched_count}</span>
        <span id="match_actions"></span>
      </div>
      <div id="match_capacityCards"></div>
      <table class="match-table">
        <thead>
          <tr>
            <th>회원</th><th>상품</th><th>start_date</th>
            <th>source</th><th>이전 코치</th><th>제안 코치</th><th>비고</th>
          </tr>
        </thead>
        <tbody id="match_tbody">
          ${drafts.map(d => this._renderDraftRow(d)).join('')}
        </tbody>
      </table>
    `;
    this._bindDraftDropdowns();
  },

  _renderDraftRow(d) {
    const sourceClass = `match-source-${d.source}`;
    const proposedSelect = this._coachSelectHTML(d.id, d.proposed_coach_id);
    return `
      <tr data-draft-id="${d.id}" class="${sourceClass}">
        <td>${UI.esc(d.member_name)}</td>
        <td>${UI.esc(d.product_name)}</td>
        <td>${UI.esc(d.start_date)}</td>
        <td><span class="match-source-badge ${sourceClass}">${UI.esc(d.source)}</span></td>
        <td>${UI.esc(d.prev_coach_name || '—')}</td>
        <td>${proposedSelect}</td>
        <td><span class="match-reason">${UI.esc(d.reason || '')}</span></td>
      </tr>
    `;
  },

  _coachSelectHTML(draftId, currentCoachId) {
    const opts = ['<option value="">— (미매칭) —</option>']
      .concat(this.state.coaches.map(c =>
        `<option value="${c.id}" ${c.id===currentCoachId ? 'selected' : ''}>${UI.esc(c.coach_name)}</option>`
      ));
    return `<select class="match-coach-dropdown" data-draft-id="${draftId}">${opts.join('')}</select>`;
  },

  _bindDraftDropdowns() {
    document.querySelectorAll('.match-coach-dropdown').forEach(sel => {
      sel.addEventListener('change', async (e) => {
        const draftId = parseInt(sel.dataset.draftId, 10);
        const v = sel.value;
        const newCoachId = v === '' ? null : parseInt(v, 10);
        sel.disabled = true;
        const res = await API.post('/api/matching.php?action=update_draft',
          { draft_id: draftId, proposed_coach_id: newCoachId });
        if (!this.isMounted()) return;
        sel.disabled = false;
        if (!res.ok) { UI.toast(res.message || '저장 실패'); return; }
        this._mergeDraftRow(res.data.row);
      });
    });
  },

  _mergeDraftRow(row) {
    const idx = this.state.current.drafts.findIndex(d => d.id === row.id);
    if (idx >= 0) {
      this.state.current.drafts[idx] = { ...this.state.current.drafts[idx], ...row };
    }
    // tbody 해당 row만 다시 그림
    const tr = document.querySelector(`tr[data-draft-id="${row.id}"]`);
    if (tr) tr.outerHTML = this._renderDraftRow(this.state.current.drafts[idx]);
    this._bindDraftDropdowns();  // 새 셀렉트 재바인딩
    // 카드/요약은 Task 10에서 같이 갱신
  },
});
