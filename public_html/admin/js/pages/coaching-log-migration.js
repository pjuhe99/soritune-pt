App.registerPage('coaching-log-migration', {
  batch_id: null,
  preview: null,

  // Color per match_status. Helper returns one of: matched, member_not_found,
  // order_not_found, calendar_missing, date_invalid. run_import flips matched → imported.
  // skipped / error are defensive defaults in case future helper changes emit them.
  _colorFor(status) {
    return ({
      matched:           '#22c55e',
      imported:          '#3b82f6',
      member_not_found:  '#ef4444',
      order_not_found:   '#f59e0b',
      calendar_missing:  '#f97316',
      date_invalid:      '#94a3b8',
      skipped:           '#94a3b8',
      error:             '#ef4444',
    })[status] || '#94a3b8';
  },

  _chip(label, value) {
    const c = this._colorFor(label);
    return `<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:${c};color:#fff;font-size:12px;margin-right:6px">${UI.esc(label)}: ${value}</span>`;
  },

  render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">코칭 로그 마이그레이션 (시트 → DB)</h1></div>
      <div class="card">
        <p class="form-help" style="margin-bottom:12px">
          CSV 컬럼: soritune_id, cohort_month, product_name, session_number,
          scheduled_date, completed_at, progress, issue, solution, improved
          <br>(선택) sheet_progress_rate, sheet_improvement_rate
        </p>
        <input type="file" id="mig-file" accept=".csv,.tsv,.txt" style="margin-right:8px">
        <button class="btn btn-primary btn-small" onclick="App.pages['coaching-log-migration'].upload()">업로드 (dry-run)</button>
      </div>
      <div id="mig-preview-area"></div>
    `;
  },

  async upload() {
    const fileEl = document.getElementById('mig-file');
    const file = fileEl.files[0];
    if (!file) { alert('파일을 선택하세요'); return; }
    const area = document.getElementById('mig-preview-area');
    area.innerHTML = '<div class="loading">업로드 중...</div>';

    const fd = new FormData();
    fd.append('file', file);
    const res = await API.upload('/api/coaching_log_migration.php?action=upload', fd);
    if (!res.ok) { area.innerHTML = ''; alert(res.message || '업로드 실패'); return; }

    this.batch_id = res.data.batch_id;
    fileEl.value = '';
    await this.loadPreview();
  },

  async loadPreview() {
    const area = document.getElementById('mig-preview-area');
    area.innerHTML = '<div class="loading">미리보기 로드 중...</div>';
    const res = await API.get(`/api/coaching_log_migration.php?action=preview&batch_id=${encodeURIComponent(this.batch_id)}`);
    if (!res.ok) { area.innerHTML = ''; alert(res.message || '미리보기 실패'); return; }
    this.preview = res.data;
    this.renderPreview();
  },

  renderPreview() {
    const s = this.preview.summary || {};
    const total = Object.values(s).reduce((a, b) => a + parseInt(b, 10), 0);
    const matched = parseInt(s.matched || 0, 10);

    const summaryHtml = Object.entries(s)
      .map(([k, v]) => this._chip(k, v))
      .join('');

    const rowsHtml = this.preview.rows.map(r => {
      const tint = this._colorFor(r.match_status);
      const bg = `background:${tint}1A`; // hex + ~10% alpha
      return `
        <tr style="${bg}">
          <td>${UI.esc(r.source_row)}</td>
          <td>${UI.esc(r.soritune_id || '')}</td>
          <td>${UI.esc(r.cohort_month || '')}</td>
          <td>${UI.esc(r.product_name || '')}</td>
          <td>${UI.esc(r.session_number)}</td>
          <td>${UI.esc(r.match_status)}</td>
          <td>${UI.esc(r.error_detail || '')}</td>
        </tr>`;
    }).join('');

    const disabled = matched > 0 ? '' : 'disabled';
    document.getElementById('mig-preview-area').innerHTML = `
      <div class="card" style="margin-top:16px">
        <h3 style="font-size:14px;font-weight:700;margin-bottom:8px">Preview (batch: ${UI.esc(this.batch_id)})</h3>
        <div style="margin-bottom:10px">${summaryHtml} <span style="color:var(--text-secondary);font-size:12px">/ 총 ${total}건</span></div>
        <button class="btn btn-primary btn-small" ${disabled}
          onclick="App.pages['coaching-log-migration'].runImport()">
          matched ${matched}건 본 import 실행
        </button>
      </div>
      <div class="card" style="margin-top:16px;overflow-x:auto">
        <table class="data-table">
          <thead><tr>
            <th>#</th><th>소리튠ID</th><th>매칭월</th><th>상품</th>
            <th>회차</th><th>상태</th><th>오류</th>
          </tr></thead>
          <tbody>${rowsHtml || '<tr><td colspan="7" style="text-align:center;color:var(--text-secondary)">행 없음</td></tr>'}</tbody>
        </table>
      </div>
    `;
  },

  async runImport() {
    const matched = parseInt(this.preview.summary.matched || 0, 10);
    if (!UI.confirm(`matched ${matched}건을 import 합니다. 진행할까요?`)) return;
    const res = await API.post('/api/coaching_log_migration.php?action=import', { batch_id: this.batch_id });
    if (!res.ok) { alert(res.message || 'import 실패'); return; }
    alert(`import ${res.data.imported} / errors ${res.data.errors}`);
    await this.loadPreview();
  },
});
