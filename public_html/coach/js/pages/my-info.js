/**
 * Coach "내 정보" 페이지
 * - 본인 정보 (이름, 한글이름, 카톡방 링크 + 복사)
 * - 소속 팀 (팀명/팀장)
 * - 팀장에게만: 같은 팀원 명단 표
 */
CoachApp.registerPage('my-info', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">내 정보</h1></div>
      <div id="myInfoContent"><div class="loading">불러오는 중...</div></div>
    `;
    const res = await API.get('/api/coach_self.php?action=get_info');
    if (!res.ok) {
      document.getElementById('myInfoContent').innerHTML =
        `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const { self, team, is_leader, members } = res.data;
    const teamLine = team
      ? `${UI.esc(team.name)} <span style="color:var(--text-secondary)">(팀장: ${UI.esc(team.leader_name)})</span>`
      : `<span style="color:var(--text-secondary)">미배정</span>`;
    const kakaoCell = self.kakao_room_url
      ? `<a href="${UI.esc(self.kakao_room_url)}" target="_blank" rel="noopener">${UI.esc(self.kakao_room_url)}</a>
         <button class="btn btn-small btn-secondary" style="margin-left:8px"
                 onclick="CoachApp.pages['my-info'].copy('${UI.esc(self.kakao_room_url)}')">복사</button>`
      : `<span style="color:var(--text-secondary)">미설정</span>`;

    let html = `
      <div class="card" style="padding:16px;margin-bottom:16px">
        <h2 style="font-size:18px;margin-bottom:12px">내 정보</h2>
        <div style="display:grid;grid-template-columns:120px 1fr;gap:8px 16px;font-size:14px">
          <div style="color:var(--text-secondary)">이름</div>     <div>${UI.esc(self.coach_name)}</div>
          <div style="color:var(--text-secondary)">한글이름</div> <div>${UI.esc(self.korean_name || '-')}</div>
          <div style="color:var(--text-secondary)">소속 팀</div>  <div>${teamLine}</div>
          <div style="color:var(--text-secondary)">카톡방</div>   <div>${kakaoCell}</div>
        </div>
      </div>
    `;

    if (is_leader && Array.isArray(members)) {
      html += `
        <div class="card" style="padding:16px">
          <h2 style="font-size:18px;margin-bottom:12px">우리 팀 코치 (${members.length}명)</h2>
          <div class="data-table-wrapper">
            <table class="data-table">
              <thead>
                <tr><th>이름</th><th>한글이름</th><th>카톡방 링크</th><th></th></tr>
              </thead>
              <tbody>
                ${members.map(m => {
                  const url = m.kakao_room_url || '';
                  return `
                    <tr>
                      <td>${UI.esc(m.coach_name)}</td>
                      <td>${UI.esc(m.korean_name || '-')}</td>
                      <td>${url
                          ? `<a href="${UI.esc(url)}" target="_blank" rel="noopener">${UI.esc(url)}</a>`
                          : '<span style="color:var(--text-secondary)">미설정</span>'}</td>
                      <td>${url
                          ? `<button class="btn btn-small btn-secondary"
                                onclick="CoachApp.pages['my-info'].copy('${UI.esc(url)}')">복사</button>`
                          : ''}</td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          </div>
        </div>
      `;
    }

    document.getElementById('myInfoContent').innerHTML = html;
  },

  async copy(text) {
    try {
      await navigator.clipboard.writeText(text);
      alert('복사되었습니다');
    } catch {
      // fallback
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      alert('복사되었습니다');
    }
  },
});
