/**
 * Admin "코치 교육 출석" 가로 비교 페이지 (read-only).
 * 라우트: #training-attendance
 *
 * 활성 팀 전체의 직전 4회 출석 매트릭스를 팀별 그룹핑하여 표시.
 * 토글/수정 UI 없음 — 운영 검토 용도.
 */
App.registerPage('training-attendance', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">코치 교육 출석 (운영)</h1></div>
      <div id="adminTrainingContent"><div class="loading">불러오는 중...</div></div>
    `;
    const res = await API.get('/api/coach_training_attendance.php?action=admin_overview');
    if (!res.ok) {
      document.getElementById('adminTrainingContent').innerHTML =
        `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const { recent_dates, teams } = res.data;

    if (!teams.length) {
      document.getElementById('adminTrainingContent').innerHTML =
        `<div class="empty-state">활성 팀 없음</div>`;
      return;
    }

    const dateHeaders = recent_dates.map(d =>
      `<th style="text-align:center;white-space:nowrap">${UI.esc(d.slice(5))}</th>`
    ).join('');

    const teamCards = teams.map(t => {
      if (!t.members.length) {
        return `
          <div class="card" style="padding:16px;margin-bottom:16px">
            <h2 style="font-size:16px;margin-bottom:8px">${UI.esc(t.leader_name)}팀</h2>
            <div class="empty-state" style="padding:8px">팀원 없음</div>
          </div>
        `;
      }
      const rows = t.members.map(m => {
        const cells = m.attendance.map(a => {
          const ch = a.attended ? '✓' : '·';
          const color = a.attended ? 'var(--success,#1ed760)' : 'var(--text-secondary)';
          return `<td style="text-align:center;color:${color};font-weight:700">${ch}</td>`;
        }).join('');
        const pct = Math.round((m.attendance_rate || 0) * 100);
        const star = (m.coach_id === t.leader_id) ? ' <span style="color:var(--accent,#FF5E00)">★</span>' : '';
        return `
          <tr>
            <td>${UI.esc(m.coach_name)}${star}</td>
            <td>${UI.esc(m.korean_name || '-')}</td>
            ${cells}
            <td style="text-align:center">${m.attended_count}/${m.total_count} (${pct}%)</td>
          </tr>
        `;
      }).join('');
      return `
        <div class="card" style="padding:0;margin-bottom:16px">
          <div style="padding:12px 16px;border-bottom:1px solid var(--border,#4d4d4d);font-size:16px;font-weight:700">
            ${UI.esc(t.leader_name)}팀 (${t.members.length}명)
          </div>
          <div class="data-table-wrapper">
            <table class="data-table">
              <thead>
                <tr>
                  <th>이름</th>
                  <th>한글이름</th>
                  ${dateHeaders}
                  <th style="text-align:center;min-width:100px">출석율</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
      `;
    }).join('');

    document.getElementById('adminTrainingContent').innerHTML = `
      ${teamCards}
      <div style="color:var(--text-secondary);font-size:12px">
        ★ = 팀장 · ✓ 출석 / · 결석 · 직전 4회 (목요일) · 읽기전용
      </div>
    `;
  },
});
