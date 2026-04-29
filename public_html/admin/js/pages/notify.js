/**
 * 알림톡 페이지 — Google Sheet + PT members.phone lookup으로 알림톡 발송.
 */
App.registerPage('notify', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">알림톡</h1>
      </div>
      <div id="notifyList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadList();
  },

  async loadList() {
    const res = await API.get('/api/notify.php?action=list_scenarios');
    if (!res.ok) {
      document.getElementById('notifyList').innerHTML = `<div class="notify-error">${UI.esc(res.message || '시나리오 로드 실패')}</div>`;
      return;
    }
    const scenarios = res.data.scenarios || [];
    if (scenarios.length === 0) {
      document.getElementById('notifyList').innerHTML = `
        <div class="empty-state">
          <p>등록된 시나리오가 없습니다.</p>
          <p><code>public_html/includes/notify/scenarios/</code>에 PHP 파일을 추가하세요.</p>
        </div>
      `;
      return;
    }
    document.getElementById('notifyList').innerHTML = scenarios.map(s => this._renderCard(s)).join('');
  },

  _renderCard(s) {
    return `
      <div class="notify-card" id="notify-card-${UI.esc(s.key)}">
        <div class="notify-card-head">
          <h3>${UI.esc(s.name)} <span class="notify-key">${UI.esc(s.key)}</span></h3>
          <label class="notify-toggle">
            <input type="checkbox" ${s.is_active ? 'checked' : ''} onchange="App.pages.notify.toggleActive('${UI.esc(s.key)}', this.checked)">
            <span>활성</span>
          </label>
        </div>
        <p class="notify-desc">${UI.esc(s.description)}</p>
        <div class="notify-meta">
          <span>스케줄: <code>${UI.esc(s.schedule)}</code></span>
          <span>cooldown: ${s.cooldown_hours ?? '—'}h</span>
          <span>max attempts: ${s.max_attempts ?? '—'}</span>
          <span>마지막 실행: ${UI.esc(s.last_run_at || '—')} (${UI.esc(s.last_run_status || '—')})</span>
          <span>다음 예정: ${UI.esc(s.next_run_at || '—')}</span>
          ${s.is_running ? '<span class="notify-running">진행 중</span>' : ''}
        </div>
        <div class="notify-actions">
          <button class="btn btn-small" onclick="App.pages.notify.preview('${UI.esc(s.key)}')">미리보기</button>
          <button class="btn btn-small btn-outline" onclick="App.pages.notify.loadBatches('${UI.esc(s.key)}')">배치이력</button>
        </div>
        <div class="notify-result" id="notify-result-${UI.esc(s.key)}"></div>
      </div>
    `;
  },

  async toggleActive(key, on) {
    const res = await API.post('/api/notify.php?action=toggle', { key, is_active: on });
    if (!res.ok) {
      alert('토글 실패: ' + (res.message || ''));
      // Revert checkbox
      const cb = document.querySelector(`#notify-card-${key} input[type="checkbox"]`);
      if (cb) cb.checked = !on;
    }
  },

  async preview(key) {
    const result = document.getElementById(`notify-result-${key}`);
    result.innerHTML = '<div class="loading">미리보기 생성 중...</div>';
    const res = await API.post('/api/notify.php?action=preview', { key });
    if (!res.ok) {
      result.innerHTML = `<div class="notify-error">${UI.esc(res.message || '미리보기 실패')}</div>`;
      return;
    }
    result.innerHTML = this._renderPreview(res.data, key);
  },

  _renderPreview(d, key) {
    const cands = d.candidates || [];
    const skips = d.skips || [];
    const candRows = cands.map(c => `
      <tr><td>${UI.esc(c.row_key)}</td><td>${UI.esc(c.name)}</td><td>${UI.esc(c.phone)}</td><td class="notify-ok">발송 대상</td></tr>
    `).join('');
    const skipRows = skips.map(s => `
      <tr class="${s.reason === 'phone_invalid' ? 'notify-unknown' : 'notify-cooldown'}">
        <td>${UI.esc(s.row_key)}</td><td>${UI.esc(s.name)}</td><td>${UI.esc(s.phone)}</td><td>${UI.esc(s.reason)}</td>
      </tr>
    `).join('');
    const unknownCount = skips.filter(s => s.reason === 'phone_invalid').length;
    const renderedFirst = d.rendered_first ? JSON.stringify(d.rendered_first, null, 2) : '(미리보기 없음 — 발송 대상 0건)';
    return `
      <div class="notify-preview">
        <div class="notify-stats">
          <span>발송 대상: <strong>${cands.length}</strong></span>
          <span class="notify-unknown-stat">제외(unknown phone): <strong>${unknownCount}</strong></span>
          <span class="notify-cooldown-stat">cooldown/max skip: <strong>${skips.length - unknownCount}</strong></span>
          <span class="notify-env">${UI.esc(d.environment || '')}</span>
        </div>
        <table class="notify-table">
          <thead><tr><th>row_key</th><th>이름</th><th>phone</th><th>상태/사유</th></tr></thead>
          <tbody>${candRows}${skipRows}</tbody>
        </table>
        ${unknownCount > 0 ? '<div class="notify-warn">빨간색 행은 PT DB에 phone이 없어 발송되지 않습니다. 시트의 아이디 또는 members 테이블을 수정 후 미리보기를 다시 생성하세요.</div>' : ''}
        <div class="notify-template-preview">
          <strong>템플릿 ID:</strong> <code>${UI.esc(d.template_id)}</code>
          <pre>${UI.esc(renderedFirst)}</pre>
        </div>
        <div class="notify-dispatch">
          <label><input type="checkbox" id="notify-dryrun-${key}" ${d.dry_run ? 'checked' : ''}> dry_run (Solapi 호출 안 함)</label>
          <label>test_phone_override (모든 메시지가 이 번호로 강제):
            <input type="text" id="notify-testphone-${key}" placeholder="01012345678">
          </label>
          <button class="btn btn-primary" onclick="App.pages.notify.dispatch('${UI.esc(d.preview_id)}', '${UI.esc(key)}')">발송 시작</button>
        </div>
      </div>
    `;
  },

  async dispatch(previewId, key) {
    const dryRun = document.getElementById(`notify-dryrun-${key}`).checked;
    const testPhone = document.getElementById(`notify-testphone-${key}`).value.trim();
    if (!confirm(`발송하시겠습니까?\ndry_run=${dryRun}, test_phone=${testPhone || '없음'}`)) return;
    const body = { preview_id: previewId, dry_run: dryRun ? 1 : 0 };
    if (testPhone) body.test_phone_override = testPhone;
    const result = document.getElementById(`notify-result-${key}`);
    result.innerHTML = '<div class="loading">발송 중...</div>';
    const res = await API.post('/api/notify.php?action=send_now', body);
    if (!res.ok) {
      result.innerHTML = `<div class="notify-error">${UI.esc(res.message || '발송 실패')}</div>`;
      return;
    }
    result.innerHTML = `<div class="notify-success">배치 ${res.data.batch_id} 시작됨. 결과를 확인하려면 "배치이력"을 클릭하세요.</div>`;
    setTimeout(() => this.loadList(), 1500);
  },

  async loadBatches(key) {
    const result = document.getElementById(`notify-result-${key}`);
    result.innerHTML = '<div class="loading">배치 이력 로딩...</div>';
    const res = await API.get(`/api/notify.php?action=list_batches&key=${encodeURIComponent(key)}`);
    if (!res.ok) {
      result.innerHTML = `<div class="notify-error">${UI.esc(res.message || '배치 이력 로드 실패')}</div>`;
      return;
    }
    const batches = res.data.batches || [];
    if (batches.length === 0) {
      result.innerHTML = '<div class="notify-empty">배치 이력 없음.</div>';
      return;
    }
    result.innerHTML = `
      <table class="notify-table">
        <thead><tr><th>ID</th><th>시작</th><th>종료</th><th>상태</th><th>대상/발송/실패/skip</th><th>dry</th><th>trigger</th></tr></thead>
        <tbody>${batches.map(b => `
          <tr>
            <td><a href="#" onclick="App.pages.notify.loadBatchDetail(${b.id}, '${UI.esc(key)}'); return false;">${b.id}</a></td>
            <td>${UI.esc(b.started_at)}</td>
            <td>${UI.esc(b.finished_at || '—')}</td>
            <td>${UI.esc(b.status)}</td>
            <td>${b.target_count}/${b.sent_count}/${b.failed_count}/${b.skipped_count}</td>
            <td>${b.dry_run ? 'Y' : 'N'}</td>
            <td>${UI.esc(b.trigger_type)}</td>
          </tr>
        `).join('')}</tbody>
      </table>
    `;
  },

  async loadBatchDetail(batchId, key) {
    const result = document.getElementById(`notify-result-${key}`);
    result.innerHTML = '<div class="loading">배치 상세 로딩...</div>';
    const res = await API.get(`/api/notify.php?action=batch_detail&batch_id=${batchId}`);
    if (!res.ok) {
      result.innerHTML = `<div class="notify-error">${UI.esc(res.message || '배치 상세 로드 실패')}</div>`;
      return;
    }
    const msgs = res.data.messages || [];
    result.innerHTML = `
      <h4>배치 ${batchId} — 메시지 ${msgs.length}건</h4>
      <table class="notify-table">
        <thead><tr><th>phone</th><th>name</th><th>status</th><th>reason</th><th>solapi_id</th><th>sent_at</th></tr></thead>
        <tbody>${msgs.map(m => `
          <tr class="notify-msg-${UI.esc(m.status)}">
            <td>${UI.esc(m.phone)}</td>
            <td>${UI.esc(m.name || '')}</td>
            <td>${UI.esc(m.status)}</td>
            <td>${UI.esc(m.skip_reason || m.fail_reason || '')}</td>
            <td>${UI.esc(m.solapi_message_id || '')}</td>
            <td>${UI.esc(m.sent_at || '')}</td>
          </tr>
        `).join('')}</tbody>
      </table>
      <button class="btn btn-small btn-outline" onclick="App.pages.notify.loadBatches('${UI.esc(key)}')">← 배치 이력으로</button>
    `;
  },
});
