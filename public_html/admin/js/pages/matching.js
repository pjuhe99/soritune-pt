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
  renderDraftReview(root) { /* Task 9~11 */ root.innerHTML = '<div>(draft review — Task 9~11)</div>'; },
});
