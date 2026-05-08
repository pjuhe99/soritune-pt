/**
 * Coach Member Chart — restricted version
 */
CoachApp.registerPage('member-chart', {
  memberId: null,
  member: null,

  async render(params) {
    this.memberId = parseInt(params[0]);
    if (!this.memberId) { location.hash = 'my-members'; return; }

    document.getElementById('pageContent').innerHTML = '<div class="loading">불러오는 중...</div>';
    const res = await API.get(`/api/members.php?action=get&id=${this.memberId}`);
    if (!res.ok) {
      document.getElementById('pageContent').innerHTML = `<div class="empty-state">${res.message}</div>`;
      return;
    }
    this.member = res.data.member;
    this.renderChart();
  },

  renderChart() {
    const m = this.member;
    const coaches = m.current_coaches?.map(c => UI.esc(c.coach_name)).join(', ') || '-';

    document.getElementById('pageContent').innerHTML = `
      <div style="margin-bottom:16px">
        <a href="#my-members" style="color:var(--text-secondary);text-decoration:none;font-size:13px">← 내 회원</a>
      </div>

      <div class="card card-elevated" style="margin-bottom:20px">
        <h2 style="font-size:20px;font-weight:700;margin-bottom:16px">${UI.esc(m.name)}</h2>
        <div class="info-grid">
          <div><div class="info-item-label">전화번호</div><div class="info-item-value">${UI.esc(m.phone)||'-'}</div></div>
          <div><div class="info-item-label">이메일</div><div class="info-item-value">${UI.esc(m.email)||'-'}</div></div>
          <div><div class="info-item-label">상태</div><div class="info-item-value">${UI.statusBadge(m.display_status)}</div></div>
          <div><div class="info-item-label">담당 코치</div><div class="info-item-value">${coaches}</div></div>
        </div>
      </div>

      <div id="ptProgressSection"></div>

      <div class="tabs">
        <button class="tab-btn active" onclick="CoachApp.pages['member-chart'].loadOrders()">PT이력</button>
        <button class="tab-btn" onclick="CoachApp.pages['member-chart'].loadNotes()">메모</button>
        <button class="tab-btn" onclick="CoachApp.pages['member-chart'].loadTests()">테스트결과</button>
        <button class="tab-btn" onclick="CoachApp.pages['member-chart'].loadLogs()">변경로그</button>
      </div>
      <div id="tabContent"></div>
    `;

    this.loadPtProgress();
    this.loadOrders();
  },

  async loadPtProgress() {
    const res = await API.get(`/api/orders.php?action=active&member_id=${this.memberId}`);
    if (!res.ok || res.data.orders.length === 0) {
      document.getElementById('ptProgressSection').innerHTML = '';
      return;
    }
    const orders = res.data.orders;
    document.getElementById('ptProgressSection').innerHTML = `
      <div class="card" style="margin-bottom:20px;background:var(--surface-card)">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">진행 중인 PT</h3>
        ${orders.map(o => this.renderProgress(o)).join('')}
      </div>
    `;
  },

  renderProgress(o) {
    if (o.product_type === 'period') {
      const today = new Date(), start = new Date(o.start_date), end = new Date(o.end_date);
      const pct = Math.min(100, Math.round(((today-start)/(end-start))*100));
      const rem = Math.max(0, Math.ceil((end-today)/86400000));
      return `<div class="pt-progress-card">
        <div class="pt-progress-header"><span class="pt-progress-title">${UI.esc(o.product_name)} (기간형)</span><span class="pt-progress-coach">${UI.esc(o.coach_name)||'-'}</span></div>
        <div class="pt-progress-meta">${UI.esc(o.start_date)} ~ ${UI.esc(o.end_date)} | 남은 일수: ${rem}일</div>
        <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>
      </div>`;
    }
    const used = parseInt(o.used_sessions)||0, total = parseInt(o.total_sessions)||1;
    const sessions = o.sessions||[];
    return `<div class="pt-progress-card">
      <div class="pt-progress-header"><span class="pt-progress-title">${UI.esc(o.product_name)} (횟수형)</span><span class="pt-progress-coach">${UI.esc(o.coach_name)||'-'}</span></div>
      <div class="pt-progress-meta">${UI.esc(o.start_date)} ~ ${UI.esc(o.end_date)} | ${used}/${total}회</div>
      <div class="progress-bar"><div class="progress-fill" style="width:${Math.round(used/total*100)}%"></div></div>
      <ul class="session-list">${sessions.map(s => `
        <li class="session-item">
          <button class="session-check ${s.completed_at?'done':''}" onclick="CoachApp.pages['member-chart'].toggleSession(${s.id})">${s.completed_at?'&#10003;':''}</button>
          <span>${s.session_number}회차</span>
          <span class="session-date">${s.completed_at ? UI.formatDate(s.completed_at)+' 완료' : '-'}</span>
        </li>`).join('')}</ul>
    </div>`;
  },

  async toggleSession(sessionId) {
    const res = await API.post(`/api/orders.php?action=complete_session&session_id=${sessionId}`);
    if (res.ok) await this.render([this.memberId]);
    else alert(res.message);
  },

  async loadOrders() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===0));
    const res = await API.get(`/api/orders.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const orders = res.data.orders;
    document.getElementById('tabContent').innerHTML = orders.length === 0
      ? '<div class="empty-state">PT 이력이 없습니다</div>'
      : `<table class="data-table"><thead><tr><th>상품명</th><th>유형</th><th>코치</th><th>기간</th><th>상태</th></tr></thead><tbody>
          ${orders.map(o => `<tr><td>${UI.esc(o.product_name)}</td><td>${o.product_type==='period'?'기간형':'횟수형'}</td><td>${UI.esc(o.coach_name)||'-'}</td>
          <td style="font-size:12px;color:var(--text-secondary)">${UI.esc(o.start_date)}~${UI.esc(o.end_date)}</td><td>${UI.statusBadge(o.status)}</td></tr>`).join('')}
        </tbody></table>`;
  },

  async loadNotes() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===1));
    const res = await API.get(`/api/notes.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const notes = res.data.notes;
    document.getElementById('tabContent').innerHTML = `
      <div style="margin-bottom:16px">
        <form id="noteForm" style="display:flex;gap:10px">
          <input class="form-input" name="content" placeholder="메모를 입력하세요" style="flex:1" required>
          <button type="submit" class="btn btn-primary btn-small">추가</button>
        </form>
      </div>
      ${notes.length === 0 ? '<div class="empty-state">메모가 없습니다</div>' : notes.map(n => `
        <div style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.04)">
          <span class="badge badge-${n.author_type==='admin'?'active':'진행예정'}">${n.author_type==='admin'?'관리자':'코치'}</span>
          <span style="font-size:12px;color:var(--text-secondary);margin-left:8px">${UI.esc(n.author_name)} | ${UI.formatDate(n.created_at)}</span>
          <div style="margin-top:8px">${UI.esc(n.content)}</div>
        </div>
      `).join('')}`;
    document.getElementById('noteForm')?.addEventListener('submit', async e => {
      e.preventDefault();
      const content = new FormData(e.target).get('content');
      const res = await API.post('/api/notes.php?action=create', { member_id: this.memberId, content });
      if (res.ok) this.loadNotes(); else alert(res.message);
    });
  },

  async loadTests() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===2));
    const res = await API.get(`/api/tests.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const results = res.data.results;
    document.getElementById('tabContent').innerHTML = results.length === 0
      ? '<div class="empty-state">테스트 결과가 없습니다</div>'
      : results.map(r => `
        <div class="card" style="margin-bottom:8px;padding:12px;background:var(--surface-card)">
          <span class="badge badge-${r.test_type==='disc'?'진행예정':'매칭대기'}">${r.test_type==='disc'?'DISC':'오감각'}</span>
          <span style="font-size:12px;color:var(--text-secondary);margin-left:8px">${UI.esc(r.tested_at)}</span>
          <div style="margin-top:8px">${this.formatTestResult(r.test_type, r.result_data)}</div>
        </div>
      `).join('');
  },

  formatTestResult(testType, data) {
    let parsed;
    try { parsed = typeof data === 'string' ? JSON.parse(data) : data; } catch { return UI.esc(String(data || '-')); }
    if (!parsed || typeof parsed !== 'object') return UI.esc(String(parsed || '-'));

    const isNewSensory = testType === 'sensory' && parsed.version === 1 && parsed.percents;
    const isNewDisc    = testType === 'disc'    && parsed.version === 1 && parsed.scores && parsed.primary;

    if (isNewSensory) {
      const p = parsed.percents;
      return `
        <div style="font-weight:700;margin-bottom:4px">${UI.esc(parsed.title || '')}</div>
        <div style="font-size:12px;color:var(--text-secondary)">
          청각 ${p.auditory ?? 0}%  ·  시각 ${p.visual ?? 0}%  ·  체각 ${p.kinesthetic ?? 0}%
        </div>
      `;
    }

    if (isNewDisc) {
      const s = parsed.scores;
      return `
        <div style="font-weight:700;margin-bottom:4px">${UI.esc(parsed.title || '')} (${UI.esc(parsed.primary || '')})</div>
        <div style="font-size:12px;color:var(--text-secondary)">
          D ${s.D ?? 0}  ·  I ${s.I ?? 0}  ·  S ${s.S ?? 0}  ·  C ${s.C ?? 0}
        </div>
      `;
    }

    // Legacy fallback
    if (Array.isArray(parsed)) return UI.esc(parsed.join(', '));
    return UI.esc(Object.entries(parsed).map(([k,v]) => `${k}: ${v}`).join(' | '));
  },

  ACTION_LABELS: {
    auto_match_complete: '자동 매칭완료',
    auto_in_progress: '자동 진행중 전환',
    auto_terminate: '기간/회차 만료 자동 종료',
    auto_revert_to_pending: '코치 해제로 매칭대기 복귀',
  },

  formatActor(l) {
    const name = l.actor_name || l.actor_type;
    return name === 'system' ? '시스템 자동' : name;
  },

  formatAction(action) {
    return this.ACTION_LABELS[action] || action;
  },

  async loadLogs() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===3));
    const res = await API.get(`/api/logs.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const logs = res.data.logs;
    document.getElementById('tabContent').innerHTML = logs.length === 0
      ? '<div class="empty-state">변경 이력이 없습니다</div>'
      : `<table class="data-table"><thead><tr><th>일시</th><th>변경</th><th>변경자</th></tr></thead><tbody>
          ${logs.map(l => `<tr><td style="font-size:11px;color:var(--text-secondary)">${UI.esc(l.created_at)}</td>
          <td style="font-size:12px">${UI.esc(this.formatAction(l.action))}</td><td style="font-size:12px">${UI.esc(this.formatActor(l))}</td></tr>`).join('')}
        </tbody></table>`;
  },
});
