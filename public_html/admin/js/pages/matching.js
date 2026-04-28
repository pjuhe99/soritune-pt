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
    isMounted: () => !!document.querySelector('#pageContent[data-page="matching"]'),
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
      if (!this.state.isMounted()) return;
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

  renderEmptyState(root) { /* Task 8 */ root.innerHTML = '<div>(empty state — Task 8)</div>'; },
  renderDraftReview(root) { /* Task 9~11 */ root.innerHTML = '<div>(draft review — Task 9~11)</div>'; },
});
