CoachApp.registerPage('my-members', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">내 회원</h1></div>
      <div id="myMemberList"><div class="loading">불러오는 중...</div></div>
    `;

    const res = await API.get('/api/members.php?action=list');
    if (!res.ok) return;
    const members = res.data.members;

    if (members.length === 0) {
      document.getElementById('myMemberList').innerHTML = '<div class="empty-state">현재 담당 회원이 없습니다</div>';
      return;
    }

    document.getElementById('myMemberList').innerHTML = `
      <table class="data-table">
        <thead><tr><th>이름</th><th>전화번호</th><th>상태</th><th>PT건수</th><th></th></tr></thead>
        <tbody>
          ${members.map(m => `
            <tr style="cursor:pointer" onclick="location.hash='member-chart/${m.id}'">
              <td>${m.name}</td>
              <td style="color:var(--text-secondary)">${m.phone || '-'}</td>
              <td>${UI.statusBadge(m.display_status)}</td>
              <td>${m.order_count}</td>
              <td><span style="color:var(--text-secondary)">→</span></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  },
});
