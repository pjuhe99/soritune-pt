/**
 * 회원 차트 - 코칭 영역 컴포넌트 (admin + coach 공유 공통 모듈)
 *
 * 호출자: 부모 페이지가 member_id 와 mountEl 을 넘김.
 *   CoachingChart.mount(member_id, mountEl)
 *
 * 동작:
 *   1. /api/member_chart.php?member_id=N 호출
 *   2. 메트릭 카드 3개 (진도율 / 개선율 / 남은 회차)
 *   3. 회원의 각 order 별로 카드 + 일별 코칭 로그 테이블 + bulk edit 바
 *   4. 회차 row 편집 버튼 → modal 인라인 편집 (progress / issue / solution / improved / completed 토글)
 *   5. 체크박스 선택 → bulk edit (일괄 완료처리 / 삭제)
 *
 * PT 패턴:
 *   - UI.showModal(htmlString)  /  UI.closeModal()  /  UI.esc(s)
 *   - API.get / API.post 는 admin·coach 양쪽 공통
 *   - API.put / API.delete 는 admin 만 존재 → POST fallback 으로 양쪽 호환
 *   - 백엔드(`coaching_log.php`)는 $_GET['action'] 으로 분기하므로 HTTP method 무관
 */
window.CoachingChart = {

  data: null,
  member_id: null,
  mountEl: null,

  async mount(member_id, mountEl) {
    this.member_id = member_id;
    this.mountEl = mountEl;
    mountEl.innerHTML = '<div class="loading">코칭 데이터 불러오는 중...</div>';
    const res = await API.get(`/api/member_chart.php?member_id=${member_id}`);
    if (!res.ok) {
      mountEl.innerHTML = `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    this.data = res.data;
    this.render();
  },

  render() {
    const m = this.data.member_metrics || { progress_rate: 0, improvement_rate: 0, done: 0, total: 0, improved: 0, solution_total: 0 };
    const orders = this.data.orders || [];

    const metricsHtml = `
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-label">피드백 진도율</div>
          <div class="metric-value">${this._pct(m.progress_rate)}</div>
          <div class="metric-sub">${m.done}/${m.total}</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">개선율</div>
          <div class="metric-value">${this._pct(m.improvement_rate)}</div>
          <div class="metric-sub">${m.improved}/${m.solution_total}</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">남은 회차</div>
          <div class="metric-value">${Math.max(0, (m.total || 0) - (m.done || 0))}</div>
        </div>
      </div>
    `;

    const ordersHtml = orders.map(o => this._renderOrder(o)).join('');

    this.mountEl.innerHTML = `
      <div class="coaching-chart">
        ${metricsHtml}
        ${ordersHtml || '<div class="empty-state">주문이 없습니다</div>'}
      </div>
    `;
  },

  _renderOrder(o) {
    const sessions = o.sessions || [];
    const cal = o.calendar;
    const rows = sessions.map(s => this._renderSessionRow(s)).join('');
    const emptyMsg = cal ? '로그 없음. + 신규 회차 추가' : '로그 없음. 캘린더 매칭 안됨';
    return `
      <div class="order-card" style="margin-top:20px">
        <h3>
          ${UI.esc(o.product_name)}
          <span class="muted">(${UI.esc(o.cohort_month || '-')})</span>
          <span class="status-badge">${UI.esc(o.status || '')}</span>
        </h3>
        <div class="bulk-bar" id="bulk-${o.id}" style="display:none">
          <span class="selected-count"></span>
          <button class="btn btn-sm btn-primary" onclick="CoachingChart.bulkComplete(${o.id})">일괄 완료처리</button>
          <button class="btn btn-sm btn-danger" onclick="CoachingChart.bulkDelete(${o.id})">삭제</button>
        </div>
        <table class="data-table session-table">
          <thead>
            <tr>
              <th><input type="checkbox" onchange="CoachingChart.toggleAll(${o.id}, this.checked)"></th>
              <th>회차</th>
              <th>예정일</th>
              <th>완료일</th>
              <th>진도</th>
              <th>문제</th>
              <th>솔루션</th>
              <th>개선</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${rows || `<tr><td colspan="9" class="empty">${emptyMsg}</td></tr>`}
          </tbody>
        </table>
        <button class="btn btn-secondary" onclick="CoachingChart.addRow(${o.id})">+ 회차 추가</button>
      </div>
    `;
  },

  _renderSessionRow(s) {
    const completedDate = (s.completed_at || '').substr(0, 10);
    return `
      <tr class="session-row" data-id="${s.id}">
        <td>
          <input type="checkbox" class="row-check"
                 data-order="${s.order_id}"
                 onchange="CoachingChart.updateBulkBar(${s.order_id})">
        </td>
        <td>${s.session_number}</td>
        <td>${UI.esc(s.scheduled_date || '-')}</td>
        <td>${UI.esc(completedDate || '-')}</td>
        <td class="cell-text">${UI.esc(s.progress || '')}</td>
        <td class="cell-text">${UI.esc(s.issue || '')}</td>
        <td class="cell-text">${UI.esc(s.solution || '')}</td>
        <td>${parseInt(s.improved) ? '✓' : ''}</td>
        <td><button class="btn btn-sm btn-secondary" onclick="CoachingChart.editRow(${s.id})">편집</button></td>
      </tr>
    `;
  },

  async addRow(order_id) {
    const o = this.data.orders.find(x => x.id == order_id);
    if (!o) return;
    const next = (o.sessions && o.sessions.length)
      ? Math.max(...o.sessions.map(s => parseInt(s.session_number) || 0)) + 1
      : 1;
    this._openEditModal(null, order_id, next);
  },

  editRow(session_id) {
    let session = null, order_id = null;
    for (const o of this.data.orders) {
      const s = (o.sessions || []).find(x => x.id == session_id);
      if (s) { session = s; order_id = o.id; break; }
    }
    if (!session) return;
    this._openEditModal(session, order_id, session.session_number);
  },

  _openEditModal(session, order_id, session_number) {
    const isNew = !session;
    UI.showModal(`
      <div class="modal-title">회차 ${session_number}</div>
      <div class="form-row">
        <label>완료 처리</label>
        <input type="checkbox" id="ses-done" ${session && session.completed_at ? 'checked' : ''}>
      </div>
      <div class="form-row">
        <label>진도</label>
        <textarea id="ses-progress" class="form-textarea" rows="2">${UI.esc(session && session.progress ? session.progress : '')}</textarea>
      </div>
      <div class="form-row">
        <label>문제</label>
        <textarea id="ses-issue" class="form-textarea" rows="2">${UI.esc(session && session.issue ? session.issue : '')}</textarea>
      </div>
      <div class="form-row">
        <label>솔루션</label>
        <textarea id="ses-solution" class="form-textarea" rows="2">${UI.esc(session && session.solution ? session.solution : '')}</textarea>
      </div>
      <div class="form-row">
        <label>개선됨</label>
        <input type="checkbox" id="ses-improved" ${session && parseInt(session.improved || 0) ? 'checked' : ''}>
      </div>
      <div class="modal-actions">
        ${isNew ? '' : `<button class="btn btn-danger btn-sm" onclick="CoachingChart.deleteRow(${session.id})">삭제</button>`}
        <button class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
        <button class="btn btn-primary" onclick="CoachingChart.saveRow(${session ? session.id : 'null'}, ${order_id}, ${session_number})">저장</button>
      </div>
    `);
  },

  async saveRow(session_id, order_id, session_number) {
    const progressEl = document.getElementById('ses-progress');
    const issueEl = document.getElementById('ses-issue');
    const solutionEl = document.getElementById('ses-solution');
    const improvedEl = document.getElementById('ses-improved');
    const doneEl = document.getElementById('ses-done');
    if (!progressEl) return; // modal 이 이미 닫혔거나 DOM 없음

    const data = {
      session_number,
      progress: progressEl.value,
      issue: issueEl.value,
      solution: solutionEl.value,
      improved: improvedEl.checked ? 1 : 0,
      completed_at: doneEl.checked
        ? (new Date()).toISOString().slice(0, 19).replace('T', ' ')
        : null,
    };

    let res;
    if (session_id) {
      res = await (API.put
        ? API.put(`/api/coaching_log.php?action=update&id=${session_id}`, data)
        : API.post(`/api/coaching_log.php?action=update&id=${session_id}`, data));
    } else {
      res = await API.post('/api/coaching_log.php?action=create', { order_id, ...data });
    }
    if (!res.ok) { alert(res.message || '저장 실패'); return; }
    UI.closeModal();
    await this.mount(this.member_id, this.mountEl);
  },

  async deleteRow(session_id) {
    if (!confirm('이 회차 로그를 삭제하시겠습니까?')) return;
    const url = `/api/coaching_log.php?action=delete&id=${session_id}`;
    const res = await (API.delete ? API.delete(url) : API.post(url));
    if (!res.ok) { alert(res.message || '삭제 실패'); return; }
    UI.closeModal();
    await this.mount(this.member_id, this.mountEl);
  },

  toggleAll(order_id, checked) {
    document.querySelectorAll(`.row-check[data-order="${order_id}"]`).forEach(cb => {
      cb.checked = checked;
    });
    this.updateBulkBar(order_id);
  },

  updateBulkBar(order_id) {
    const checked = document.querySelectorAll(`.row-check[data-order="${order_id}"]:checked`);
    const bar = document.getElementById(`bulk-${order_id}`);
    if (!bar) return;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';
    const countEl = bar.querySelector('.selected-count');
    if (countEl) countEl.textContent = `${checked.length}개 선택`;
  },

  async bulkComplete(order_id) {
    const ids = Array.from(document.querySelectorAll(`.row-check[data-order="${order_id}"]:checked`))
      .map(cb => parseInt(cb.closest('tr').dataset.id, 10));
    if (!ids.length) return;
    const res = await API.post('/api/coaching_log.php?action=bulk_update', {
      ids,
      data: { completed_at: (new Date()).toISOString().slice(0, 19).replace('T', ' ') },
    });
    if (!res.ok) { alert(res.message || '일괄 완료 처리 실패'); return; }
    await this.mount(this.member_id, this.mountEl);
  },

  async bulkDelete(order_id) {
    const ids = Array.from(document.querySelectorAll(`.row-check[data-order="${order_id}"]:checked`))
      .map(cb => parseInt(cb.closest('tr').dataset.id, 10));
    if (!ids.length || !confirm(`${ids.length}개 회차 로그를 삭제하시겠습니까?`)) return;
    for (const id of ids) {
      const url = `/api/coaching_log.php?action=delete&id=${id}`;
      await (API.delete ? API.delete(url) : API.post(url));
    }
    await this.mount(this.member_id, this.mountEl);
  },

  _pct(v) {
    const n = parseFloat(v);
    if (!isFinite(n)) return '0.0%';
    return (n * 100).toFixed(1) + '%';
  },
};
