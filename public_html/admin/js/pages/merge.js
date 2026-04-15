App.registerPage('merge', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">동일인관리</h1>
      </div>
      <div id="mergeList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadSuspects();
  },

  async loadSuspects() {
    const res = await API.get('/api/merge.php?action=suspects');
    if (!res.ok) return;
    const groups = res.data.groups;

    if (groups.length === 0) {
      document.getElementById('mergeList').innerHTML =
        '<div class="empty-state">동일인 의심 건이 없습니다</div>';
      return;
    }

    document.getElementById('mergeList').innerHTML = groups.map((g, gi) => `
      <div class="card" style="margin-bottom:16px">
        <div style="margin-bottom:12px">
          <span class="badge badge-매칭대기">${g.match_type === 'phone' ? '전화번호' : '이메일'}</span>
          <span style="margin-left:8px;color:var(--text-secondary)">${g.match_value}</span>
        </div>
        <form id="mergeGroup${gi}">
          ${g.members.map(m => `
            <label style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04);cursor:pointer">
              <input type="checkbox" name="member_ids" value="${m.id}" checked>
              <input type="radio" name="primary_id" value="${m.id}">
              <span>${m.name}</span>
              <span style="color:var(--text-secondary);font-size:12px">${m.phone || '-'} | ${m.email || '-'}</span>
              <span style="font-size:11px;color:var(--text-secondary)">ID:${m.id}</span>
            </label>
          `).join('')}
          <div style="margin-top:12px;display:flex;gap:10px;align-items:center">
            <span style="font-size:12px;color:var(--text-secondary)">대표 계정을 선택(라디오) 후</span>
            <button type="button" class="btn btn-small btn-primary" onclick="App.pages.merge.doMerge(${gi})">병합</button>
          </div>
        </form>
      </div>
    `).join('');
  },

  async doMerge(groupIdx) {
    const form = document.getElementById(`mergeGroup${groupIdx}`);
    const checked = [...form.querySelectorAll('input[name="member_ids"]:checked')].map(i => parseInt(i.value));
    const primary = form.querySelector('input[name="primary_id"]:checked');

    if (checked.length < 2) { alert('2명 이상 선택하세요'); return; }
    if (!primary) { alert('대표 계정을 선택하세요'); return; }

    const primaryId = parseInt(primary.value);

    // Preview
    const preview = await API.post('/api/merge.php?action=preview', { member_ids: checked, primary_id: primaryId });
    if (!preview.ok) { alert(preview.message); return; }

    const p = preview.data.preview;
    const absorbedInfo = p.absorbed.map(a => {
      const c = a.counts;
      const total = Object.values(c).reduce((s,v) => s+v, 0);
      return `${a.member.name} (데이터 ${total}건)`;
    }).join(', ');

    if (!UI.confirm(`대표: ${p.primary.name}\n흡수: ${absorbedInfo}\n\n병합하시겠습니까?`)) return;

    const mergedIds = checked.filter(id => id !== primaryId);
    const res = await API.post('/api/merge.php?action=execute', { primary_id: primaryId, merged_ids: mergedIds });
    if (res.ok) {
      alert(res.message);
      await this.loadSuspects();
    } else {
      alert(res.message);
    }
  },
});
