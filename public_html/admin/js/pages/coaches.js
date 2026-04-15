/**
 * Coach Management Page
 */
App.registerPage('coaches', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">코치관리</h1>
        <button class="btn btn-primary" onclick="App.pages.coaches.showForm()">+ 코치 추가</button>
      </div>
      <div id="coachList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadList();
  },

  async loadList() {
    const res = await API.get('/api/coaches.php?action=list');
    if (!res.ok) return;
    const coaches = res.data.coaches;

    if (coaches.length === 0) {
      document.getElementById('coachList').innerHTML =
        '<div class="empty-state">등록된 코치가 없습니다</div>';
      return;
    }

    document.getElementById('coachList').innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th>이름</th>
            <th>상태</th>
            <th>배정</th>
            <th>담당수</th>
            <th>최대인원</th>
            <th>메모</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${coaches.map(c => `
            <tr>
              <td>${c.coach_name}</td>
              <td>${UI.statusBadge(c.status)}</td>
              <td>${c.available == 1 ? '<span style="color:var(--success)">가능</span>' : '<span style="color:var(--text-secondary)">불가</span>'}</td>
              <td>${c.current_count}</td>
              <td>${c.max_capacity}</td>
              <td style="color:var(--text-secondary);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${c.memo || '-'}</td>
              <td>
                <button class="btn btn-small btn-secondary" onclick="App.pages.coaches.showForm(${c.id})">편집</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  },

  async showForm(coachId = null) {
    let coach = { login_id: '', coach_name: '', status: 'active', available: 1, max_capacity: 0, memo: '' };
    if (coachId) {
      const res = await API.get(`/api/coaches.php?action=get&id=${coachId}`);
      if (res.ok) coach = res.data.coach;
    }

    const isEdit = !!coachId;
    UI.showModal(`
      <div class="modal-title">${isEdit ? '코치 수정' : '코치 추가'}</div>
      <form id="coachForm">
        <div class="form-group">
          <label class="form-label">로그인 ID</label>
          <input class="form-input" name="login_id" value="${coach.login_id}" ${isEdit ? 'readonly style="opacity:0.5"' : ''} required>
        </div>
        <div class="form-group">
          <label class="form-label">${isEdit ? '비밀번호 (변경 시에만 입력)' : '비밀번호'}</label>
          <input class="form-input" type="password" name="password" ${isEdit ? '' : 'required'}>
        </div>
        <div class="form-group">
          <label class="form-label">코치명 (영문)</label>
          <input class="form-input" name="coach_name" value="${coach.coach_name}" required>
        </div>
        <div class="form-group">
          <label class="form-label">상태</label>
          <select class="form-select" name="status">
            <option value="active" ${coach.status === 'active' ? 'selected' : ''}>활동중</option>
            <option value="inactive" ${coach.status === 'inactive' ? 'selected' : ''}>비활성</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">배정 가능</label>
          <select class="form-select" name="available">
            <option value="1" ${coach.available == 1 ? 'selected' : ''}>가능</option>
            <option value="0" ${coach.available == 0 ? 'selected' : ''}>불가</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">최대 담당 인원</label>
          <input class="form-input" type="number" name="max_capacity" value="${coach.max_capacity}" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo">${coach.memo || ''}</textarea>
        </div>
        <div class="modal-actions">
          ${isEdit ? `<button type="button" class="btn btn-danger btn-small" onclick="App.pages.coaches.deleteCoach(${coachId})">삭제</button>` : ''}
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">${isEdit ? '저장' : '등록'}</button>
        </div>
      </form>
    `);

    document.getElementById('coachForm').addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const body = Object.fromEntries(fd);
      body.available = parseInt(body.available);
      body.max_capacity = parseInt(body.max_capacity);
      if (isEdit && !body.password) delete body.password;

      const url = isEdit
        ? `/api/coaches.php?action=update&id=${coachId}`
        : '/api/coaches.php?action=create';
      const res = await API.post(url, body);
      if (res.ok) {
        UI.closeModal();
        await this.loadList();
      } else {
        alert(res.message);
      }
    });
  },

  async deleteCoach(id) {
    if (!UI.confirm('이 코치를 삭제하시겠습니까?')) return;
    const res = await API.post(`/api/coaches.php?action=delete&id=${id}`);
    if (res.ok) {
      UI.closeModal();
      await this.loadList();
    } else {
      alert(res.message);
    }
  },
});
