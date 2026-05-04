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
    const { recent_dates, members } = res.data;
    if (!members.length) {
      document.getElementById('teamListContent').innerHTML =
        `<div class="empty-state">아직 팀원이 없습니다</div>`;
      return;
    }

    const dateHeaders = recent_dates.map(d => {
      // 04-30 형태로 짧게 (월-일)
      const short = d.slice(5);
      return `<th style="white-space:nowrap;text-align:center">${UI.esc(short)}</th>`;
    }).join('');

    const rows = members.map(m => {
      const star = m.is_self ? ' <span style="color:var(--accent,#FF5E00)">★</span>' : '';
      const noteCol = m.meeting_notes_count > 0 ? m.meeting_notes_count : '-';
      const checkCells = (m.attendance || []).map(a => `
        <td style="text-align:center;cursor:default"
            onclick="event.stopPropagation()">
          <input type="checkbox"
                 data-bulk-toggle
                 data-coach-id="${m.coach_id}"
                 data-date="${UI.esc(a.date)}"
                 ${a.attended ? 'checked' : ''}>
        </td>
      `).join('');
      return `
        <tr style="cursor:pointer" onclick="location.hash='#team/${m.coach_id}'">
          <td>${UI.esc(m.coach_name)}${star}</td>
          <td>${UI.esc(m.korean_name || '-')}</td>
          ${checkCells}
          <td style="text-align:center">${noteCol}</td>
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
                ${dateHeaders}
                <th style="text-align:center">면담</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>
      <div style="margin-top:8px;color:var(--text-secondary);font-size:12px">
        ★ = 본인(팀장) · 행 클릭 → 상세 · 체크박스 = 코치 교육 출석 (직전 4회)
      </div>
    `;
    document.querySelectorAll('[data-bulk-toggle]').forEach(cb => {
      cb.addEventListener('change', e => this.handleBulkToggle(e));
    });
  },

  async handleBulkToggle(ev) {
    const cb = ev.target;
    ev.stopPropagation();
    const coachId = parseInt(cb.dataset.coachId, 10);
    const date    = cb.dataset.date;
    const want    = cb.checked;
    cb.disabled = true;

    const res = await API.post('/api/coach_training_attendance.php?action=toggle', {
      coach_id: coachId,
      training_date: date,
      attended: want ? 1 : 0,
    });
    cb.disabled = false;
    if (!res.ok) {
      alert(res.message || '실패');
      cb.checked = !want; // rollback
    }
  },

  async renderDetail(coachId) {
    // shell
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header" style="display:flex;align-items:center;gap:12px">
        <a href="#team" style="color:var(--text-secondary);text-decoration:none">← 팀원 관리</a>
        <h1 class="page-title" id="teamDetailTitle">불러오는 중...</h1>
      </div>
      <div style="display:flex;gap:8px;margin-bottom:16px">
        <button class="btn btn-primary btn-small" id="tabNotes">면담 기록</button>
        <button class="btn btn-outline btn-small" id="tabAttendance">코치 교육 출석</button>
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
    notesBtn.classList.toggle('btn-primary', tab === 'notes');
    notesBtn.classList.toggle('btn-outline', tab !== 'notes');
    attBtn.classList.toggle('btn-primary', tab === 'attendance');
    attBtn.classList.toggle('btn-outline', tab !== 'attendance');
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

  openNoteModal(note) {
    const today = new Date();
    const kst = new Date(today.getTime() + 9*60*60*1000);
    const todayStr = kst.toISOString().slice(0,10);

    const date  = note ? note.meeting_date : todayStr;
    const body  = note ? note.notes : '';
    const isEdit = !!note;

    const overlay = UI.showModal(`
      <h2 style="font-size:18px;margin-bottom:12px">${isEdit ? '면담 기록 수정' : '새 면담 기록'}</h2>
      <div class="form-group">
        <label class="form-label">면담 일자</label>
        <input type="date" class="form-input" id="nmDate" value="${UI.esc(date)}">
      </div>
      <div class="form-group">
        <label class="form-label">메모</label>
        <textarea class="form-input" id="nmNotes" rows="8"
                  placeholder="면담 내용을 자유롭게 입력하세요">${UI.esc(body)}</textarea>
      </div>
      <div id="nmErr" class="login-error" style="display:none"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button class="btn btn-outline" id="nmCancel">취소</button>
        <button class="btn btn-primary" id="nmSave">${isEdit ? '저장' : '작성'}</button>
      </div>
    `);
    document.getElementById('nmCancel').onclick = () => UI.closeModal();
    document.getElementById('nmSave').onclick = async () => {
      const dateVal = document.getElementById('nmDate').value;
      const notesVal = document.getElementById('nmNotes').value;
      const err = document.getElementById('nmErr');
      err.style.display = 'none';

      if (!dateVal) { err.textContent='일자를 선택하세요'; err.style.display='block'; return; }
      if (!notesVal.trim()) { err.textContent='메모를 입력하세요'; err.style.display='block'; return; }

      let res;
      if (isEdit) {
        res = await API.post(
          `/api/coach_meeting_notes.php?action=update&id=${note.id}`,
          { meeting_date: dateVal, notes: notesVal }
        );
      } else {
        res = await API.post(
          `/api/coach_meeting_notes.php?action=create`,
          { coach_id: this._currentCoachId, meeting_date: dateVal, notes: notesVal }
        );
      }
      if (!res.ok) {
        err.textContent = res.message || '저장 실패';
        err.style.display = 'block';
        return;
      }
      UI.closeModal();
      await this.renderNotesTab();
    };
  },

  async handleNoteAction(ev) {
    ev.stopPropagation();
    const btn = ev.target.closest('button[data-act]');
    if (!btn) return;
    const id = parseInt(btn.dataset.id, 10);
    const act = btn.dataset.act;

    if (act === 'edit') {
      // 카드에서 현재 값 추출
      const card = btn.closest('[data-note-id]');
      const meetingDate = card.dataset.meetingDate;
      const notesText = card.querySelector('div[style*="pre-wrap"]').textContent;
      this.openNoteModal({ id, meeting_date: meetingDate, notes: notesText });
    } else if (act === 'del') {
      if (!UI.confirm('이 면담 기록을 삭제할까요?')) return;
      const res = await API.post(`/api/coach_meeting_notes.php?action=delete&id=${id}`);
      if (!res.ok) { alert(res.message || '삭제 실패'); return; }
      await this.renderNotesTab();
    }
  },

  async renderAttendanceTab() {
    const coachId = this._currentCoachId;
    const body = document.getElementById('teamDetailBody');
    body.innerHTML = `<div class="loading">불러오는 중...</div>`;

    const res = await API.get(`/api/coach_training_attendance.php?action=history&coach_id=${coachId}`);
    if (!res.ok) {
      body.innerHTML = `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const { recent, earlier, attended_count, total_count, attendance_rate } = res.data;
    const pct = Math.round((attendance_rate || 0) * 100);

    body.innerHTML = `
      <div class="card" style="padding:16px;margin-bottom:16px">
        <div style="font-size:14px;color:var(--text-secondary);margin-bottom:6px">직전 4주</div>
        <div style="font-size:24px;font-weight:700">${attended_count}/${total_count} (${pct}%)</div>
      </div>
      <div class="card" style="padding:0;margin-bottom:12px">
        <div style="padding:8px 14px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--border,#4d4d4d)">분모 회차</div>
        <div id="attRecentList">${recent.map(r => this.renderAttendanceRow(r, true)).join('')}</div>
      </div>
      <div class="card" style="padding:0">
        <div style="padding:8px 14px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--border,#4d4d4d)">더 이전 회차</div>
        <div id="attEarlierList">${earlier.map(r => this.renderAttendanceRow(r, false)).join('')}</div>
      </div>
    `;
    body.querySelectorAll('[data-att-toggle]').forEach(cb => {
      cb.addEventListener('change', e => this.handleAttendanceToggle(e));
    });
  },

  renderAttendanceRow(row, isRecent) {
    const checked = row.attended ? 'checked' : '';
    const labelStyle = row.attended ? '' : 'color:var(--text-secondary)';
    return `
      <label style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border,#2a2a2a);cursor:pointer">
        <span style="${labelStyle}">${UI.esc(row.date)} (목)</span>
        <span>
          <input type="checkbox" data-att-toggle data-date="${UI.esc(row.date)}" ${checked}>
          <span style="margin-left:6px;${labelStyle}">${row.attended ? '출석' : '결석'}</span>
        </span>
      </label>
    `;
  },

  async handleAttendanceToggle(ev) {
    const cb = ev.target;
    const date = cb.dataset.date;
    const wantAttended = cb.checked;
    cb.disabled = true;

    const res = await API.post('/api/coach_training_attendance.php?action=toggle', {
      coach_id: this._currentCoachId,
      training_date: date,
      attended: wantAttended ? 1 : 0,
    });
    cb.disabled = false;
    if (!res.ok) {
      alert(res.message || '실패');
      cb.checked = !wantAttended; // rollback
      return;
    }
    // 헤드라인 + 라벨 갱신은 전체 다시 그리기로 단순화
    await this.renderAttendanceTab();
  },
});
