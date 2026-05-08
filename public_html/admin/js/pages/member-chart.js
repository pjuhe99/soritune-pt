/**
 * Member Chart (Detail) Page
 */
App.registerPage('member-chart', {
  memberId: null,
  member: null,

  async render(params) {
    this.memberId = parseInt(params[0]);
    if (!this.memberId) { location.hash = 'members'; return; }

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
    const sorituneId = UI.esc(m.soritune_id) || '-';

    document.getElementById('pageContent').innerHTML = `
      <div style="margin-bottom:16px">
        <a href="#members" style="color:var(--text-secondary);text-decoration:none;font-size:13px">← 회원목록</a>
      </div>

      <div class="card card-elevated" style="margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
          <div>
            <h2 style="font-size:20px;font-weight:700;margin-bottom:16px">${UI.esc(m.name)}</h2>
            <div class="info-grid">
              <div>
                <div class="info-item-label">전화번호</div>
                <div class="info-item-value">${UI.esc(m.phone) || '-'}</div>
              </div>
              <div>
                <div class="info-item-label">이메일</div>
                <div class="info-item-value">${UI.esc(m.email) || '-'}</div>
              </div>
              <div>
                <div class="info-item-label">Soritune ID</div>
                <div class="info-item-value">${sorituneId}</div>
              </div>
              <div>
                <div class="info-item-label">대표 상태</div>
                <div class="info-item-value">${UI.statusBadge(m.display_status)}</div>
              </div>
              <div>
                <div class="info-item-label">담당 코치</div>
                <div class="info-item-value">${coaches}</div>
              </div>
            </div>
          </div>
          <button class="btn btn-small btn-secondary" onclick="App.pages['member-chart'].showEditForm()">정보수정</button>
        </div>
      </div>

      <div id="ptProgressSection"></div>

      <div class="tabs" id="chartTabs">
        <button class="tab-btn active" data-tab="orders" onclick="App.pages['member-chart'].switchTab('orders')">PT이력</button>
        <button class="tab-btn" data-tab="coach-history" onclick="App.pages['member-chart'].switchTab('coach-history')">코치이력</button>
        <button class="tab-btn" data-tab="tests" onclick="App.pages['member-chart'].switchTab('tests')">테스트결과</button>
        <button class="tab-btn" data-tab="notes" onclick="App.pages['member-chart'].switchTab('notes')">메모</button>
        <button class="tab-btn" data-tab="logs" onclick="App.pages['member-chart'].switchTab('logs')">변경로그</button>
        <button class="tab-btn" data-tab="merge-info" onclick="App.pages['member-chart'].switchTab('merge-info')">병합정보</button>
      </div>
      <div id="tabContent"><div class="empty-state">PT이력 탭 — Task 5에서 구현</div></div>
    `;

    // Load PT progress section
    this.loadPtProgress();
    this.switchTab('orders');
  },

  switchTab(tabName) {
    document.querySelectorAll('#chartTabs .tab-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tab === tabName);
    });
    const loaders = {
      'orders': () => this.loadOrders(),
      'coach-history': () => this.loadCoachHistory(),
      'tests': () => this.loadTests(),
      'notes': () => this.loadNotes(),
      'logs': () => this.loadLogs(),
      'merge-info': () => this.loadMergeInfo(),
    };
    if (loaders[tabName]) loaders[tabName]();
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
        ${orders.map(o => this.renderPtProgressCard(o)).join('')}
      </div>
    `;
  },

  renderPtProgressCard(order) {
    const today = new Date();
    const start = new Date(order.start_date);
    const end = new Date(order.end_date);

    if (order.product_type === 'period') {
      const totalDays = Math.max(1, (end - start) / 86400000);
      const elapsed = Math.max(0, (today - start) / 86400000);
      const pct = Math.min(100, Math.round((elapsed / totalDays) * 100));
      const remaining = Math.max(0, Math.ceil((end - today) / 86400000));

      return `
        <div class="pt-progress-card">
          <div class="pt-progress-header">
            <span class="pt-progress-title">${UI.esc(order.product_name)} (기간형)</span>
            <span class="pt-progress-coach">${UI.esc(order.coach_name) || '-'}</span>
          </div>
          <div class="pt-progress-meta">${UI.esc(order.start_date)} ~ ${UI.esc(order.end_date)} | 남은 일수: ${remaining}일</div>
          <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>
          <div style="font-size:11px;color:var(--text-secondary);text-align:right">${pct}%</div>
        </div>
      `;
    }

    // Count type
    const used = parseInt(order.used_sessions) || 0;
    const total = parseInt(order.total_sessions) || 1;
    const pct = Math.round((used / total) * 100);
    const sessions = order.sessions || [];

    return `
      <div class="pt-progress-card">
        <div class="pt-progress-header">
          <span class="pt-progress-title">${UI.esc(order.product_name)} (횟수형)</span>
          <span class="pt-progress-coach">${UI.esc(order.coach_name) || '-'}</span>
        </div>
        <div class="pt-progress-meta">${UI.esc(order.start_date)} ~ ${UI.esc(order.end_date)} | ${used} / ${total}회</div>
        <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>
        <ul class="session-list">
          ${sessions.map(s => `
            <li class="session-item">
              <button class="session-check ${s.completed_at ? 'done' : ''}"
                onclick="App.pages['member-chart'].toggleSession(${s.id}, event)">
                ${s.completed_at ? '&#10003;' : ''}
              </button>
              <span>${s.session_number}회차</span>
              <span class="session-date">${s.completed_at ? UI.formatDate(s.completed_at) + ' 완료' : '-'}</span>
            </li>
          `).join('')}
        </ul>
      </div>
    `;
  },

  async toggleSession(sessionId, event) {
    const res = await API.post(`/api/orders.php?action=complete_session&session_id=${sessionId}`);
    if (res.ok) {
      await this.render([this.memberId]); // Reload full chart
    } else {
      alert(res.message);
    }
  },

  async loadOrders() {
    const res = await API.get(`/api/orders.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const orders = res.data.orders;

    const isAdmin = true; // Admin page always admin context

    document.getElementById('tabContent').innerHTML = `
      ${isAdmin ? `<div style="margin-bottom:12px;text-align:right">
        <button class="btn btn-small btn-primary" onclick="App.pages['member-chart'].showOrderForm()">+ PT이력 추가</button>
      </div>` : ''}
      ${orders.length === 0 ? '<div class="empty-state">PT 이력이 없습니다</div>' : `
        <table class="data-table">
          <thead><tr>
            <th>상품명</th><th>유형</th><th>코치</th><th>기간</th><th>진행</th><th>금액</th><th>상태</th><th></th>
          </tr></thead>
          <tbody>
            ${orders.map(o => `
              <tr>
                <td>${UI.esc(o.product_name)}</td>
                <td>${o.product_type === 'period' ? '기간형' : '횟수형'}</td>
                <td>${UI.esc(o.coach_name) || '-'}</td>
                <td style="font-size:12px;color:var(--text-secondary)">${UI.esc(o.start_date)} ~ ${UI.esc(o.end_date)}</td>
                <td>${o.product_type === 'count' ? `${o.used_sessions}/${o.total_sessions}` : '-'}</td>
                <td>${UI.formatMoney(o.amount)}</td>
                <td>${UI.statusBadge(o.status)}</td>
                <td>${isAdmin ? `<button class="btn btn-small btn-secondary" onclick="App.pages['member-chart'].showOrderForm(${o.id})">편집</button>` : ''}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `}
    `;
  },

  async showOrderForm(orderId = null) {
    let order = { product_name:'', product_type:'period', start_date:'', end_date:'', total_sessions:'', amount:0, status:'매칭대기', coach_id:'', memo:'' };
    if (orderId) {
      const res = await API.get(`/api/orders.php?action=get&id=${orderId}`);
      if (res.ok) order = res.data.order;
    }

    const coachRes = await API.get('/api/coaches.php?action=list');
    const coaches = coachRes.ok ? coachRes.data.coaches.filter(c => c.status === 'active') : [];
    const isEdit = !!orderId;

    UI.showModal(`
      <div class="modal-title">${isEdit ? 'PT이력 수정' : 'PT이력 추가'}</div>
      <form id="orderForm">
        <div class="form-group">
          <label class="form-label">상품명</label>
          <input class="form-input" name="product_name" value="${UI.esc(order.product_name)}" required>
        </div>
        <div class="form-group">
          <label class="form-label">상품 유형</label>
          <select class="form-select" name="product_type" onchange="document.getElementById('sessionFields').style.display=this.value==='count'?'block':'none'">
            <option value="period" ${order.product_type==='period'?'selected':''}>기간형</option>
            <option value="count" ${order.product_type==='count'?'selected':''}>횟수형</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">담당 코치</label>
          <select class="form-select" name="coach_id">
            <option value="">미배정</option>
            ${coaches.map(c => `<option value="${c.id}" ${order.coach_id==c.id?'selected':''}>${c.coach_name}</option>`).join('')}
          </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label class="form-label">시작일</label>
            <input class="form-input" type="date" name="start_date" value="${order.start_date}" required>
          </div>
          <div class="form-group">
            <label class="form-label">종료일</label>
            <input class="form-input" type="date" name="end_date" value="${order.end_date}" required>
          </div>
        </div>
        <div id="sessionFields" style="display:${order.product_type==='count'?'block':'none'}">
          <div class="form-group">
            <label class="form-label">총 횟수</label>
            <input class="form-input" type="number" name="total_sessions" value="${order.total_sessions||''}" min="1">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">금액</label>
          <input class="form-input" type="number" name="amount" value="${order.amount||0}" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">상태</label>
          <select class="form-select" name="status">
            ${['매칭대기','매칭완료','진행중','연기','중단','환불','종료'].map(s =>
              `<option value="${s}" ${order.status===s?'selected':''}>${s}</option>`
            ).join('')}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo">${UI.esc(order.memo)}</textarea>
        </div>
        <div class="modal-actions">
          ${isEdit ? `<button type="button" class="btn btn-danger btn-small" onclick="App.pages['member-chart'].deleteOrder(${orderId})">삭제</button>` : ''}
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">${isEdit ? '저장' : '추가'}</button>
        </div>
      </form>
    `);

    document.getElementById('orderForm').addEventListener('submit', async e => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target));
      body.member_id = this.memberId;
      body.amount = parseInt(body.amount) || 0;
      body.total_sessions = parseInt(body.total_sessions) || null;
      body.coach_id = parseInt(body.coach_id) || null;

      const url = isEdit
        ? `/api/orders.php?action=update&id=${orderId}`
        : '/api/orders.php?action=create';
      const res = await API.post(url, body);
      if (res.ok) {
        UI.closeModal();
        await this.render([this.memberId]);
      } else {
        alert(res.message);
      }
    });
  },

  async deleteOrder(id) {
    if (!UI.confirm('이 PT이력을 삭제하시겠습니까?')) return;
    const res = await API.post(`/api/orders.php?action=delete&id=${id}`);
    if (res.ok) {
      UI.closeModal();
      await this.render([this.memberId]);
    } else {
      alert(res.message);
    }
  },

  async loadCoachHistory() {
    const res = await API.get(`/api/orders.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const orders = res.data.orders || [];

    const stmt = await API.get(`/api/members.php?action=get&id=${this.memberId}`);
    const assignments = stmt.ok ? stmt.data.member.current_coaches : [];

    // Derive change events from orders (sorted ASC by start_date, id)
    const ordersAsc = [...orders]
      .filter(o => o.coach_id)
      .sort((a, b) => (a.start_date || '').localeCompare(b.start_date || '') || (a.id - b.id));

    const events = [];
    let prev = null;
    for (const o of ordersAsc) {
      if (prev === null) {
        events.push({ date: o.start_date, type: 'assigned', newCoach: o.coach_name, product: o.product_name });
      } else if (prev.coach_id !== o.coach_id) {
        events.push({ date: o.start_date, type: 'changed', oldCoach: prev.coach_name, newCoach: o.coach_name, product: o.product_name });
      }
      prev = o;
    }
    events.reverse(); // newest first

    document.getElementById('tabContent').innerHTML = `
      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">현재 담당 코치</h3>
      ${assignments.length === 0
        ? '<div style="color:var(--text-secondary);margin-bottom:20px">담당 코치 없음</div>'
        : `<div style="margin-bottom:20px">${assignments.map(a =>
            `<span class="badge badge-active" style="margin-right:8px">${UI.esc(a.coach_name)}</span>`
          ).join('')}</div>`
      }
      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">변경 이력</h3>
      ${events.length === 0 ? '<div class="empty-state">코치 변경 이력이 없습니다</div>' : `
        <table class="data-table">
          <thead><tr><th>일자</th><th>변경 내용</th><th>관련 상품</th></tr></thead>
          <tbody>
            ${events.map(e => `
              <tr>
                <td style="font-size:12px;color:var(--text-secondary)">${UI.esc(e.date)}</td>
                <td>${e.type === 'assigned'
                  ? `<span class="badge badge-active">${UI.esc(e.newCoach)}</span> 최초 배정`
                  : `<span class="badge badge-inactive">${UI.esc(e.oldCoach)}</span> → <span class="badge badge-active">${UI.esc(e.newCoach)}</span>`
                }</td>
                <td style="font-size:12px;color:var(--text-secondary)">${UI.esc(e.product) || '-'}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `}
    `;
  },

  async loadTests() {
    const res = await API.get(`/api/tests.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const results = res.data.results;

    const discResults = results.filter(r => r.test_type === 'disc');
    const sensoryResults = results.filter(r => r.test_type === 'sensory');

    document.getElementById('tabContent').innerHTML = `
      <div style="margin-bottom:12px;text-align:right">
        <button class="btn btn-small btn-primary" onclick="App.pages['member-chart'].showTestForm()">+ 결과 추가</button>
      </div>
      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">DISC 진단</h3>
      ${discResults.length === 0 ? '<div style="color:var(--text-secondary);margin-bottom:20px">결과 없음</div>' :
        discResults.map(r => `
          <div class="card" style="margin-bottom:8px;padding:12px;background:var(--surface-card)">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div>
                <span style="font-size:12px;color:var(--text-secondary)">${UI.esc(r.tested_at)}</span>
                <div style="margin-top:4px">${this.formatTestResult(r.test_type, r.result_data)}</div>
                ${r.memo ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:4px">${UI.esc(r.memo)}</div>` : ''}
              </div>
              <button class="btn btn-small btn-outline" onclick="App.pages['member-chart'].deleteTest(${r.id})">삭제</button>
            </div>
          </div>
        `).join('')
      }
      <h3 style="font-size:14px;font-weight:700;margin:20px 0 12px">오감각 테스트</h3>
      ${sensoryResults.length === 0 ? '<div style="color:var(--text-secondary)">결과 없음</div>' :
        sensoryResults.map(r => `
          <div class="card" style="margin-bottom:8px;padding:12px;background:var(--surface-card)">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div>
                <span style="font-size:12px;color:var(--text-secondary)">${UI.esc(r.tested_at)}</span>
                <div style="margin-top:4px">${this.formatTestResult(r.test_type, r.result_data)}</div>
                ${r.memo ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:4px">${UI.esc(r.memo)}</div>` : ''}
              </div>
              <button class="btn btn-small btn-outline" onclick="App.pages['member-chart'].deleteTest(${r.id})">삭제</button>
            </div>
          </div>
        `).join('')
      }
    `;
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

  async showTestForm() {
    UI.showModal(`
      <div class="modal-title">테스트 결과 추가</div>
      <form id="testForm">
        <div class="form-group">
          <label class="form-label">테스트 유형</label>
          <select class="form-select" name="test_type" required>
            <option value="disc">DISC 진단</option>
            <option value="sensory">오감각 테스트</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">테스트 일자</label>
          <input class="form-input" type="date" name="tested_at" required>
        </div>
        <div class="form-group">
          <label class="form-label">결과 (JSON 또는 텍스트)</label>
          <textarea class="form-textarea" name="result_data" placeholder='예: {"D":35,"I":25,"S":20,"C":20}'></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo"></textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">저장</button>
        </div>
      </form>
    `);

    document.getElementById('testForm').addEventListener('submit', async e => {
      e.preventDefault();
      const fd = Object.fromEntries(new FormData(e.target));
      fd.member_id = this.memberId;
      try { fd.result_data = JSON.parse(fd.result_data); } catch { fd.result_data = fd.result_data; }
      const res = await API.post('/api/tests.php?action=create', fd);
      if (res.ok) { UI.closeModal(); this.switchTab('tests'); } else { alert(res.message); }
    });
  },

  async deleteTest(id) {
    if (!UI.confirm('이 테스트 결과를 삭제하시겠습니까?')) return;
    const res = await API.post(`/api/tests.php?action=delete&id=${id}`);
    if (res.ok) this.switchTab('tests'); else alert(res.message);
  },

  async loadNotes() {
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
          <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
              <span class="badge badge-${n.author_type === 'admin' ? 'active' : '진행예정'}" style="margin-right:8px">${n.author_type === 'admin' ? '관리자' : '코치'}</span>
              <span style="font-size:12px;color:var(--text-secondary)">${UI.esc(n.author_name)} | ${UI.formatDate(n.created_at)}</span>
            </div>
            <button class="btn btn-small btn-outline" onclick="App.pages['member-chart'].deleteNote(${n.id})">삭제</button>
          </div>
          <div style="margin-top:8px;font-size:14px">${UI.esc(n.content)}</div>
        </div>
      `).join('')}
    `;

    document.getElementById('noteForm').addEventListener('submit', async e => {
      e.preventDefault();
      const content = new FormData(e.target).get('content');
      const res = await API.post('/api/notes.php?action=create', { member_id: this.memberId, content });
      if (res.ok) this.switchTab('notes'); else alert(res.message);
    });
  },

  async deleteNote(id) {
    if (!UI.confirm('이 메모를 삭제하시겠습니까?')) return;
    const res = await API.post(`/api/notes.php?action=delete&id=${id}`);
    if (res.ok) this.switchTab('notes'); else alert(res.message);
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
    const res = await API.get(`/api/logs.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const logs = res.data.logs;

    document.getElementById('tabContent').innerHTML = logs.length === 0
      ? '<div class="empty-state">변경 이력이 없습니다</div>'
      : `<table class="data-table">
          <thead><tr><th>일시</th><th>대상</th><th>변경</th><th>이전</th><th>이후</th><th>변경자</th></tr></thead>
          <tbody>
            ${logs.map(l => `
              <tr>
                <td style="font-size:11px;color:var(--text-secondary);white-space:nowrap">${UI.esc(l.created_at)}</td>
                <td style="font-size:12px">${UI.esc(l.target_type)}</td>
                <td style="font-size:12px">${UI.esc(this.formatAction(l.action))}</td>
                <td style="font-size:11px;color:var(--text-secondary);max-width:150px;overflow:hidden;text-overflow:ellipsis">${UI.esc(l.old_value) || '-'}</td>
                <td style="font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis">${UI.esc(l.new_value) || '-'}</td>
                <td style="font-size:12px">${UI.esc(this.formatActor(l))}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>`;
  },

  async loadMergeInfo() {
    const [historyRes, memberRes] = await Promise.all([
      API.get(`/api/merge.php?action=history&member_id=${this.memberId}`),
      API.get(`/api/members.php?action=get&id=${this.memberId}`),
    ]);

    const history = historyRes.ok ? historyRes.data.history : [];
    const accounts = memberRes.ok ? memberRes.data.member.accounts : [];

    document.getElementById('tabContent').innerHTML = `
      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">연결 계정</h3>
      ${accounts.length === 0 ? '<div style="color:var(--text-secondary);margin-bottom:20px">연결 계정 없음</div>' : `
        <table class="data-table" style="margin-bottom:24px">
          <thead><tr><th>출처</th><th>ID</th><th>이름</th><th>전화</th><th>이메일</th><th>대표</th></tr></thead>
          <tbody>
            ${accounts.map(a => `
              <tr>
                <td>${UI.esc(a.source)}</td>
                <td style="color:var(--text-secondary)">${UI.esc(a.source_id) || '-'}</td>
                <td>${UI.esc(a.name) || '-'}</td>
                <td style="color:var(--text-secondary)">${UI.esc(a.phone) || '-'}</td>
                <td style="color:var(--text-secondary)">${UI.esc(a.email) || '-'}</td>
                <td>${a.is_primary ? '<span style="color:var(--accent)">대표</span>' : ''}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `}

      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">병합 이력</h3>
      ${history.length === 0 ? '<div class="empty-state">병합 이력이 없습니다</div>' : `
        <table class="data-table">
          <thead><tr><th>일시</th><th>유형</th><th>대상</th><th>관리자</th><th>상태</th><th></th></tr></thead>
          <tbody>
            ${history.map(h => {
              const absorbed = JSON.parse(h.absorbed_member_data || '{}');
              const isMerged = !h.unmerged_at;
              return `
                <tr>
                  <td style="font-size:12px;color:var(--text-secondary)">${UI.formatDate(h.merged_at)}</td>
                  <td>${h.primary_member_id == this.memberId ? '흡수' : '흡수됨'}</td>
                  <td>${UI.esc(absorbed.name) || '?'} (ID:${h.merged_member_id})</td>
                  <td style="font-size:12px">${UI.esc(h.admin_name)}</td>
                  <td>${isMerged ? UI.statusBadge('진행중') : '<span style="color:var(--text-secondary)">해제됨</span>'}</td>
                  <td>${isMerged && h.primary_member_id == this.memberId
                    ? `<button class="btn btn-small btn-outline" onclick="App.pages['member-chart'].undoMerge(${h.id})">해제</button>`
                    : ''}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      `}
    `;
  },

  async undoMerge(mergeLogId) {
    // First check for warnings
    const check = await API.post(`/api/merge.php?action=undo&id=${mergeLogId}`, {});
    if (check.ok && check.data.warning) {
      if (!UI.confirm(check.data.message + '\n\n계속하시겠습니까?')) return;
      // Force undo
      const res = await API.post(`/api/merge.php?action=undo&id=${mergeLogId}&force=1`, {});
      if (res.ok) { alert(res.message); await this.render([this.memberId]); }
      else alert(res.message);
    } else if (check.ok) {
      alert(check.message);
      await this.render([this.memberId]);
    } else {
      alert(check.message);
    }
  },

  async showEditForm() {
    const m = this.member;
    UI.showModal(`
      <div class="modal-title">회원 정보 수정</div>
      <form id="memberEditForm">
        <div class="form-group">
          <label class="form-label">Soritune ID <span style="color:var(--accent)">*</span></label>
          <input class="form-input" name="soritune_id" value="${UI.esc(m.soritune_id)}" required>
        </div>
        <div class="form-group">
          <label class="form-label">이름 <span style="color:var(--accent)">*</span></label>
          <input class="form-input" name="name" value="${UI.esc(m.name)}" required>
        </div>
        <div class="form-group">
          <label class="form-label">전화번호</label>
          <input class="form-input" name="phone" value="${UI.esc(m.phone)}">
        </div>
        <div class="form-group">
          <label class="form-label">이메일</label>
          <input class="form-input" name="email" value="${UI.esc(m.email)}" type="email">
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo">${UI.esc(m.memo)}</textarea>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-danger btn-small" onclick="App.pages['member-chart'].deleteMember()">회원 삭제</button>
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">저장</button>
        </div>
      </form>
    `);

    document.getElementById('memberEditForm').addEventListener('submit', async e => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(e.target));
      const res = await API.post(`/api/members.php?action=update&id=${this.memberId}`, body);
      if (res.ok) {
        UI.closeModal();
        await this.render([this.memberId]);
      } else {
        alert(res.message);
      }
    });
  },

  async deleteMember() {
    if (!UI.confirm('이 회원을 삭제하시겠습니까? 모든 이력이 삭제됩니다.')) return;
    const res = await API.post(`/api/members.php?action=delete&id=${this.memberId}`);
    if (res.ok) {
      UI.closeModal();
      location.hash = 'members';
    } else {
      alert(res.message);
    }
  },
});
