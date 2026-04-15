/**
 * Member List Page
 */
App.registerPage('members', {
  async render() {
    const coachRes = await API.get('/api/coaches.php?action=list');
    const coaches = coachRes.ok ? coachRes.data.coaches : [];

    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">회원관리</h1>
        <button class="btn btn-primary" onclick="App.pages.members.showCreateForm()">+ 회원 추가</button>
      </div>
      <div class="filters">
        <input class="search-input" id="memberSearch" placeholder="이름, 전화번호, 이메일 검색" oninput="App.pages.members.search()">
        <select class="filter-pill" id="statusFilter" onchange="App.pages.members.search()">
          <option value="">전체 상태</option>
          <option value="진행중">진행중</option>
          <option value="진행예정">진행예정</option>
          <option value="매칭대기">매칭대기</option>
          <option value="연기">연기</option>
          <option value="중단">중단</option>
          <option value="환불">환불</option>
          <option value="종료">종료</option>
        </select>
        <select class="filter-pill" id="coachFilter" onchange="App.pages.members.search()">
          <option value="">전체 코치</option>
          ${coaches.map(c => `<option value="${c.id}">${c.coach_name}</option>`).join('')}
        </select>
      </div>
      <div id="memberList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.search();
  },

  _searchTimer: null,
  search() {
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this.loadList(), 300);
  },

  async loadList() {
    const search = document.getElementById('memberSearch')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const coach = document.getElementById('coachFilter')?.value || '';

    const params = new URLSearchParams({ action: 'list' });
    if (search) params.set('search', search);
    if (status) params.set('status', status);
    if (coach) params.set('coach_id', coach);

    const res = await API.get(`/api/members.php?${params}`);
    if (!res.ok) return;
    const members = res.data.members;

    if (members.length === 0) {
      document.getElementById('memberList').innerHTML =
        '<div class="empty-state">조건에 맞는 회원이 없습니다</div>';
      return;
    }

    document.getElementById('memberList').innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th>이름</th>
            <th>전화번호</th>
            <th>담당코치</th>
            <th>상태</th>
            <th>PT건수</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${members.map(m => `
            <tr style="cursor:pointer" onclick="location.hash='member-chart/${m.id}'">
              <td>${UI.esc(m.name)}</td>
              <td style="color:var(--text-secondary)">${UI.esc(m.phone) || '-'}</td>
              <td>${UI.esc(m.current_coaches) || '-'}</td>
              <td>${UI.statusBadge(m.display_status)}</td>
              <td>${m.order_count}</td>
              <td><span style="color:var(--text-secondary)">→</span></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  },

  showCreateForm() {
    UI.showModal(`
      <div class="modal-title">회원 추가</div>
      <form id="memberCreateForm">
        <div class="form-group">
          <label class="form-label">이름</label>
          <input class="form-input" name="name" required>
        </div>
        <div class="form-group">
          <label class="form-label">전화번호</label>
          <input class="form-input" name="phone" placeholder="010-0000-0000">
        </div>
        <div class="form-group">
          <label class="form-label">이메일</label>
          <input class="form-input" name="email" type="email">
        </div>
        <div class="form-group">
          <label class="form-label">Soritune ID</label>
          <input class="form-input" name="soritune_id" placeholder="soritunenglish.com ID">
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo"></textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">등록</button>
        </div>
      </form>
    `);

    document.getElementById('memberCreateForm').addEventListener('submit', async e => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target));
      const res = await API.post('/api/members.php?action=create', body);
      if (res.ok) {
        UI.closeModal();
        location.hash = `member-chart/${res.data.id}`;
      } else {
        alert(res.message);
      }
    });
  },
});
