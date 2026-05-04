/**
 * 코치 SPA "코치 교육 출석" 페이지
 * 라우트: #training-attendance
 *
 * 회차 드롭다운(직전 4회) + 우리 팀원 한 줄씩 + 체크박스 1개로 일괄 출석체크.
 * 권한: 팀장 전용. PHP 가드 + API 가드 동일.
 *
 * 데이터: coach_self.php?action=team_overview 재사용 (members[].attendance + recent_dates).
 * 토글: coach_training_attendance.php?action=toggle (기존 API).
 */
CoachApp.registerPage('training-attendance', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">코치 교육 출석</h1></div>
      <div id="trainingAttContent"><div class="loading">불러오는 중...</div></div>
    `;
    const res = await API.get('/api/coach_self.php?action=team_overview');
    if (!res.ok) {
      document.getElementById('trainingAttContent').innerHTML =
        `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    this._data = res.data; // { recent_dates, members }
    this._selectedDate = res.data.recent_dates[0]; // 가장 최근 default
    this.renderBody();
  },

  renderBody() {
    const { recent_dates, members } = this._data;
    const sel = this._selectedDate;
    if (!members.length) {
      document.getElementById('trainingAttContent').innerHTML =
        `<div class="empty-state">아직 팀원이 없습니다</div>`;
      return;
    }

    // 회차 옵션
    const opts = recent_dates.map(d => {
      const sel2 = d === sel ? 'selected' : '';
      return `<option value="${UI.esc(d)}" ${sel2}>${UI.esc(d)} (목)</option>`;
    }).join('');

    // 출석/결석 합산 (선택 회차 기준)
    let attended = 0;
    members.forEach(m => {
      const r = (m.attendance || []).find(a => a.date === sel);
      if (r && r.attended) attended++;
    });

    // 행
    const rows = members.map(m => {
      const r = (m.attendance || []).find(a => a.date === sel);
      const checked = r && r.attended ? 'checked' : '';
      const star = m.is_self ? ' <span style="color:var(--accent,#FF5E00)">★</span>' : '';
      return `
        <tr>
          <td>${UI.esc(m.coach_name)}${star}</td>
          <td>${UI.esc(m.korean_name || '-')}</td>
          <td style="text-align:center">
            <input type="checkbox" data-att-toggle data-coach-id="${m.coach_id}" ${checked}>
          </td>
        </tr>
      `;
    }).join('');

    document.getElementById('trainingAttContent').innerHTML = `
      <div class="card" style="padding:16px;margin-bottom:16px">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <label class="form-label" style="margin:0">회차</label>
          <select class="form-input" id="trainingDateSel" style="width:auto;min-width:180px">
            ${opts}
          </select>
          <div style="margin-left:auto;color:var(--text-secondary);font-size:13px">
            출석 ${attended} / ${members.length}명
          </div>
        </div>
      </div>
      <div class="card" style="padding:0">
        <div class="data-table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>이름</th>
                <th>한글이름</th>
                <th style="text-align:center;min-width:100px">출석</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>
      <div style="margin-top:8px;color:var(--text-secondary);font-size:12px">
        ★ = 본인(팀장) · 체크박스 클릭 즉시 저장
      </div>
    `;
    document.getElementById('trainingDateSel').addEventListener('change', e => {
      this._selectedDate = e.target.value;
      this.renderBody();
    });
    document.querySelectorAll('[data-att-toggle]').forEach(cb => {
      cb.addEventListener('change', e => this.handleToggle(e));
    });
  },

  async handleToggle(ev) {
    const cb = ev.target;
    const coachId = parseInt(cb.dataset.coachId, 10);
    const date    = this._selectedDate;
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
      cb.checked = !want;
      return;
    }
    // 캐시 갱신: 해당 멤버의 attendance[date].attended 변경
    const member = (this._data.members || []).find(m => (m.coach_id|0) === coachId);
    if (member) {
      const entry = (member.attendance || []).find(a => a.date === date);
      if (entry) entry.attended = want ? 1 : 0;
      // 출석율 카운트 재계산 (직전 4회 기준)
      const cnt = (member.attendance || []).reduce((s, a) => s + (a.attended ? 1 : 0), 0);
      member.attended_count = cnt;
      member.attendance_rate = member.total_count > 0 ? cnt / member.total_count : 0;
    }
    // 헤드라인 갱신 위해 body 다시 그림
    this.renderBody();
  },
});
