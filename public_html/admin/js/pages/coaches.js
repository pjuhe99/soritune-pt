/**
 * Coach Management Page
 */
App.registerPage('coaches', {
  ROLE_OPTIONS: ['신규 코치','일반 코치','리드 코치','코칭 마스터 코치','소리 마스터 코치'],

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
    this.coaches = coaches;  // 모달에서 팀장 옵션 생성용

    if (coaches.length === 0) {
      document.getElementById('coachList').innerHTML =
        '<div class="empty-state">등록된 코치가 없습니다</div>';
      return;
    }

    const yn = v => v == 1
      ? '<span style="color:var(--success)">○</span>'
      : '<span style="color:var(--text-secondary)">-</span>';
    const evalBadge = e =>
      e === 'pass' ? '<span class="badge badge-active">pass</span>'
      : e === 'fail' ? '<span class="badge badge-inactive">fail</span>'
      : '-';

    document.getElementById('coachList').innerHTML = `
      <div class="data-table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>이름</th>
              <th>한글이름</th>
              <th>생년월일</th>
              <th>입사일</th>
              <th>직급</th>
              <th>평가</th>
              <th>상태</th>
              <th>배정</th>
              <th>담당</th>
              <th>해외</th>
              <th>부업</th>
              <th>소리<br>기본</th>
              <th>소리<br>심화</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${coaches.map(c => `
              <tr>
                <td>${UI.esc(c.coach_name)}</td>
                <td>${UI.esc(c.korean_name || '')}</td>
                <td>${UI.esc(c.birthdate || '-')}</td>
                <td>${UI.esc(c.hired_on || '-')}</td>
                <td>${UI.esc(c.role || '-')}</td>
                <td>${evalBadge(c.evaluation)}</td>
                <td>${UI.statusBadge(c.status)}</td>
                <td>${c.available == 1 ? '<span style="color:var(--success)">가능</span>' : '<span style="color:var(--text-secondary)">불가</span>'}</td>
                <td>${c.current_count}</td>
                <td>${yn(c.overseas)}</td>
                <td>${yn(c.side_job)}</td>
                <td>${yn(c.soriblock_basic)}</td>
                <td>${yn(c.soriblock_advanced)}</td>
                <td>
                  <button class="btn btn-small btn-secondary" onclick="App.pages.coaches.showForm(${c.id})">편집</button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  },

  async showForm(coachId = null) {
    let coach = {
      login_id: '', coach_name: '', korean_name: '', birthdate: '', hired_on: '',
      role: '', evaluation: '', status: 'active', available: 1, max_capacity: 0, memo: '',
      overseas: 0, side_job: 0, soriblock_basic: 0, soriblock_advanced: 0,
      team_leader_id: null, kakao_room_url: '',
    };
    if (coachId) {
      const res = await API.get(`/api/coaches.php?action=get&id=${coachId}`);
      if (res.ok) coach = res.data.coach;
    }

    const isEdit = !!coachId;
    const roleOptions = this.ROLE_OPTIONS.map(r =>
      `<option value="${UI.esc(r)}" ${coach.role === r ? 'selected' : ''}>${UI.esc(r)}</option>`
    ).join('');

    const isLeader = isEdit && coach.team_leader_id != null
                     && Number(coach.team_leader_id) === Number(coach.id);
    // 비활성이지만 현재 이 코치의 leader로 지정된 팀장은 옵션에 남겨야 함 (자동 미배정 방지)
    const leaderOptions = (this.coaches || [])
      .filter(c => Number(c.team_leader_id) === Number(c.id)
                && Number(c.id) !== Number(coach.id)
                && (c.status === 'active' || Number(c.id) === Number(coach.team_leader_id)))
      .map(c => {
        const inactiveTag = c.status !== 'active' ? ' (비활성)' : '';
        const sel = Number(coach.team_leader_id) === Number(c.id) ? 'selected' : '';
        return `<option value="${c.id}" ${sel}>${UI.esc(c.coach_name)}팀${inactiveTag}</option>`;
      })
      .join('');

    const chk = (name, label) => `
      <label style="display:flex;align-items:center;gap:6px;font-size:14px;cursor:pointer">
        <input type="checkbox" name="${name}" value="1" ${coach[name] == 1 ? 'checked' : ''}>
        ${label}
      </label>`;

    UI.showModal(`
      <div class="modal-title">${isEdit ? '코치 수정' : '코치 추가'}</div>
      <form id="coachForm">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label class="form-label">로그인 ID</label>
            <input class="form-input" name="login_id" value="${UI.esc(coach.login_id)}" ${isEdit ? 'readonly style="opacity:0.5"' : ''} required>
          </div>
          <div class="form-group">
            <label class="form-label">${isEdit ? '비밀번호 (변경 시에만)' : '비밀번호'}</label>
            <input class="form-input" type="password" name="password" ${isEdit ? '' : 'required'}>
          </div>
          <div class="form-group">
            <label class="form-label">코치명 (영문)</label>
            <input class="form-input" name="coach_name" value="${UI.esc(coach.coach_name)}" required>
          </div>
          <div class="form-group">
            <label class="form-label">한글 이름</label>
            <input class="form-input" name="korean_name" value="${UI.esc(coach.korean_name || '')}">
          </div>
          <div class="form-group">
            <label class="form-label">생년월일</label>
            <input class="form-input" type="date" name="birthdate" value="${UI.esc(coach.birthdate || '')}">
          </div>
          <div class="form-group">
            <label class="form-label">입사일</label>
            <input class="form-input" type="date" name="hired_on" value="${UI.esc(coach.hired_on || '')}">
          </div>
          <div class="form-group">
            <label class="form-label">직급</label>
            <select class="form-select" name="role">
              <option value="">(미지정)</option>
              ${roleOptions}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">평가</label>
            <select class="form-select" name="evaluation">
              <option value="" ${!coach.evaluation ? 'selected' : ''}>(미지정)</option>
              <option value="pass" ${coach.evaluation === 'pass' ? 'selected' : ''}>pass</option>
              <option value="fail" ${coach.evaluation === 'fail' ? 'selected' : ''}>fail</option>
            </select>
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
            <label class="form-label">팀장 여부</label>
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;padding:8px 0">
              <input type="checkbox" name="is_team_leader" value="1" id="isTeamLeaderChk" ${isLeader ? 'checked' : ''}>
              팀장으로 지정 (본인 이름의 팀이 자동 생성)
            </label>
          </div>
          <div class="form-group">
            <label class="form-label">소속 팀</label>
            <select class="form-select" name="team_leader_id" id="teamLeaderSelect" ${isLeader ? 'disabled' : ''}>
              <option value="">(미배정)</option>
              ${leaderOptions}
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">속성</label>
          <div style="display:flex;flex-wrap:wrap;gap:16px;padding:8px 0">
            ${chk('overseas', '해외')}
            ${chk('side_job', '부업')}
            ${chk('soriblock_basic', '소리블럭 기본')}
            ${chk('soriblock_advanced', '소리블럭 심화')}
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">1:1PT 카톡방 링크</label>
          <input class="form-input" type="url" name="kakao_room_url"
                 value="${UI.esc(coach.kakao_room_url || '')}"
                 placeholder="https://open.kakao.com/o/...">
          <div id="kakaoUrlError" style="display:none;color:var(--text-negative);font-size:12px;margin-top:4px"></div>
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo">${UI.esc(coach.memo || '')}</textarea>
        </div>
        <div class="modal-actions">
          ${isEdit ? `<button type="button" class="btn btn-danger btn-small" onclick="App.pages.coaches.deleteCoach(${coachId})">삭제</button>` : ''}
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">${isEdit ? '저장' : '등록'}</button>
        </div>
      </form>
    `);

    // 팀장 체크박스 ↔ 소속 팀 드롭다운 상호작용
    const leaderChk = document.getElementById('isTeamLeaderChk');
    const leaderSel = document.getElementById('teamLeaderSelect');
    if (leaderChk && leaderSel) {
      leaderChk.addEventListener('change', () => {
        leaderSel.disabled = leaderChk.checked;
        if (leaderChk.checked) leaderSel.value = '';
      });
    }

    document.getElementById('coachForm').addEventListener('submit', async e => {
      e.preventDefault();
      const form = e.target;
      const fd = new FormData(form);
      const body = Object.fromEntries(fd);
      body.available = parseInt(body.available);
      body.max_capacity = parseInt(body.max_capacity);
      ['overseas','side_job','soriblock_basic','soriblock_advanced'].forEach(k => {
        body[k] = form.elements[k].checked ? 1 : 0;
      });
      // is_team_leader/team_leader_id는 form.elements 직접 읽기 (FormData는 disabled select 누락)
      body.is_team_leader = form.elements.is_team_leader.checked ? 1 : 0;
      body.team_leader_id = body.is_team_leader
        ? null
        : (form.elements.team_leader_id.value || null);
      body.kakao_room_url = (body.kakao_room_url || '').trim() || null;

      // 클라이언트 검증 (서버와 동일 정규식)
      const errEl = document.getElementById('kakaoUrlError');
      errEl.style.display = 'none';
      if (body.kakao_room_url) {
        const re = /^https:\/\/open\.kakao\.com\/(o|me)\/[A-Za-z0-9_]+$/;
        if (!re.test(body.kakao_room_url)) {
          errEl.textContent = '카톡방 링크 형식이 올바르지 않습니다 (https://open.kakao.com/o/... 또는 /me/...)';
          errEl.style.display = 'block';
          return;
        }
      }
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
