/**
 * 코치 SPA "팀원 관리" 페이지
 * 라우트:
 *   #team           — 우리 팀원 명단 (팀장 본인 포함)
 *   #team/<coachId> — 팀원 상세 (Task 11)
 *
 * 권한: 팀장 전용. PHP 가드(coach/index.php)로 메뉴 자체가 비-팀장에게 출력되지 않으며,
 * 서버 API(coach_self.php?action=team_overview)도 비-팀장은 403.
 */
CoachApp.registerPage('team', {
  async render(params) {
    if (params && params.length && params[0]) {
      return this.renderDetail(parseInt(params[0], 10));
    }
    return this.renderList();
  },

  async renderList() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">팀원 관리</h1></div>
      <div id="teamListContent"><div class="loading">불러오는 중...</div></div>
    `;
    const res = await API.get('/api/coach_self.php?action=team_overview');
    if (!res.ok) {
      document.getElementById('teamListContent').innerHTML =
        `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const { members } = res.data;
    if (!members.length) {
      document.getElementById('teamListContent').innerHTML =
        `<div class="empty-state">아직 팀원이 없습니다</div>`;
      return;
    }

    const rows = members.map(m => {
      const pct = Math.round((m.attendance_rate || 0) * 100);
      const bar = this.attendanceBar(m.attended_count, m.total_count);
      const noteCol = m.meeting_notes_count > 0 ? m.meeting_notes_count : '-';
      const star = m.is_self ? ' <span style="color:var(--accent,#FF5E00)">★</span>' : '';
      return `
        <tr style="cursor:pointer" onclick="location.hash='#team/${m.coach_id}'">
          <td>${UI.esc(m.coach_name)}${star}</td>
          <td>${UI.esc(m.korean_name || '-')}</td>
          <td>${bar} ${m.attended_count}/${m.total_count} ${pct}%</td>
          <td>${noteCol}</td>
        </tr>
      `;
    }).join('');

    document.getElementById('teamListContent').innerHTML = `
      <div class="card" style="padding:0">
        <div class="data-table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>이름</th>
                <th>한글이름</th>
                <th style="min-width:160px">직전 4주 출석</th>
                <th>면담</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>
      <div style="margin-top:8px;color:var(--text-secondary);font-size:12px">
        ★ = 본인(팀장) · 행 클릭 → 상세
      </div>
    `;
  },

  /**
   * 4-슬롯 출석 막대. 4=success, 3=lime, 2=amber, 0~1=red.
   */
  attendanceBar(attended, total) {
    const ratio = total > 0 ? attended / total : 0;
    let color;
    if (ratio >= 1) color = '#1ed760';
    else if (ratio >= 0.75) color = '#a3e635';
    else if (ratio >= 0.5) color = '#ffa42b';
    else if (ratio > 0) color = '#f3727f';
    else color = '#4d4d4d';

    const cells = [];
    for (let i = 0; i < total; i++) {
      const filled = i < attended;
      cells.push(`<span style="display:inline-block;width:12px;height:8px;margin-right:2px;background:${filled ? color : '#3a3a3a'};border-radius:2px"></span>`);
    }
    return `<span style="display:inline-block;vertical-align:middle">${cells.join('')}</span>`;
  },

  async renderDetail(coachId) {
    // shell
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header" style="display:flex;align-items:center;gap:12px">
        <a href="#team" style="color:var(--text-secondary);text-decoration:none">← 팀원 관리</a>
        <h1 class="page-title" id="teamDetailTitle">불러오는 중...</h1>
      </div>
      <div style="display:flex;gap:8px;margin-bottom:16px">
        <button class="btn btn-small" id="tabNotes">면담 기록</button>
        <button class="btn btn-small btn-outline" id="tabAttendance">코치 교육 출석</button>
      </div>
      <div id="teamDetailBody"><div class="loading">불러오는 중...</div></div>
    `;
    this._currentCoachId = coachId;
    this._activeTab = 'notes';

    // 코치 메타 (팀 overview 재사용)
    const ov = await API.get('/api/coach_self.php?action=team_overview');
    if (!ov.ok) {
      document.getElementById('teamDetailBody').innerHTML =
        `<div class="empty-state">${UI.esc(ov.message || '오류')}</div>`;
      return;
    }
    const m = ov.data.members.find(x => (x.coach_id|0) === (coachId|0));
    if (!m) {
      document.getElementById('teamDetailBody').innerHTML =
        `<div class="empty-state">팀원이 아닙니다</div>`;
      return;
    }
    document.getElementById('teamDetailTitle').textContent =
      `${m.coach_name}${m.korean_name ? ` (${m.korean_name})` : ''}`;

    document.getElementById('tabNotes').onclick      = () => this.switchTab('notes');
    document.getElementById('tabAttendance').onclick = () => this.switchTab('attendance');

    await this.renderNotesTab();
  },

  switchTab(tab) {
    this._activeTab = tab;
    const notesBtn = document.getElementById('tabNotes');
    const attBtn   = document.getElementById('tabAttendance');
    notesBtn.classList.toggle('btn-outline', tab !== 'notes');
    attBtn.classList.toggle('btn-outline',   tab !== 'attendance');
    if (tab === 'notes') this.renderNotesTab();
    else this.renderAttendanceTab();
  },

  async renderNotesTab() {
    const coachId = this._currentCoachId;
    const body = document.getElementById('teamDetailBody');
    body.innerHTML = `<div class="loading">불러오는 중...</div>`;

    const res = await API.get(`/api/coach_meeting_notes.php?action=list&coach_id=${coachId}`);
    if (!res.ok) {
      body.innerHTML = `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const notes = res.data.notes;
    const cards = notes.length === 0
      ? `<div class="empty-state">면담 기록 없음</div>`
      : notes.map(n => this.renderNoteCard(n)).join('');

    body.innerHTML = `
      <div style="margin-bottom:12px">
        <button class="btn btn-primary" id="newNoteBtn">+ 새 면담 기록</button>
      </div>
      <div id="notesList">${cards}</div>
    `;
    document.getElementById('newNoteBtn').onclick = () => this.openNoteModal(null);
  },

  renderNoteCard(n) {
    const editBtns = n.can_edit
      ? `<button class="btn btn-small" data-act="edit" data-id="${n.id}">수정</button>
         <button class="btn btn-small btn-outline" data-act="del" data-id="${n.id}">삭제</button>`
      : '';
    const author = n.can_edit
      ? ''
      : `<span style="color:var(--text-secondary);margin-left:8px">by ${UI.esc(n.created_by_name)}</span>`;
    return `
      <div class="card" style="padding:14px;margin-bottom:10px"
           data-note-id="${n.id}" data-meeting-date="${UI.esc(n.meeting_date)}">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div><strong>${UI.esc(n.meeting_date)}</strong>${author}</div>
          <div style="display:flex;gap:6px" onclick="CoachApp.pages.team.handleNoteAction(event)">
            ${editBtns}
          </div>
        </div>
        <div style="white-space:pre-wrap;font-size:14px">${UI.esc(n.notes)}</div>
      </div>
    `;
  },

  // Task 12에서 채움
  openNoteModal(note) { alert('TODO Task 12'); },
  handleNoteAction(ev) { ev.stopPropagation(); /* TODO Task 12 */ },

  // Task 13에서 채움
  async renderAttendanceTab() {
    document.getElementById('teamDetailBody').innerHTML =
      `<div class="empty-state">출석 탭 (구현 예정)</div>`;
  },
});
