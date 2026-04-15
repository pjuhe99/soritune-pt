App.registerPage('import', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">데이터관리</h1></div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:32px">
        <div class="card">
          <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">회원 Import</h3>
          <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px">CSV/TSV 파일. 컬럼: 이름, 전화번호, 이메일, soritune_id, 메모</p>
          <input type="file" id="memberFile" accept=".csv,.tsv,.txt" style="display:none" onchange="App.pages.import.uploadFile('members')">
          <button class="btn btn-primary btn-small" onclick="document.getElementById('memberFile').click()">파일 선택</button>
          <div id="memberUploadResult"></div>
        </div>
        <div class="card">
          <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">PT이력 Import</h3>
          <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px">CSV/TSV 파일. 컬럼: 회원이름, 전화번호, 상품명, 상품유형(기간/횟수), 코치명(영문), 시작일, 종료일, 총횟수, 소진횟수, 금액, 상태, 메모</p>
          <input type="file" id="orderFile" accept=".csv,.tsv,.txt" style="display:none" onchange="App.pages.import.uploadFile('orders')">
          <button class="btn btn-primary btn-small" onclick="document.getElementById('orderFile').click()">파일 선택</button>
          <div id="orderUploadResult"></div>
        </div>
      </div>

      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px">Import 기록</h3>
      <div id="batchList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadBatches();
  },

  async uploadFile(type) {
    const fileInput = type === 'members' ? document.getElementById('memberFile') : document.getElementById('orderFile');
    const resultDiv = type === 'members' ? document.getElementById('memberUploadResult') : document.getElementById('orderUploadResult');
    const file = fileInput.files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append('file', file);

    resultDiv.innerHTML = '<div class="loading">업로드 중...</div>';
    const res = await API.upload('/api/import.php?action=upload', fd);
    if (!res.ok) { resultDiv.innerHTML = `<div style="color:var(--negative);margin-top:8px">${res.message}</div>`; return; }

    const d = res.data;
    resultDiv.innerHTML = `
      <div style="margin-top:12px">
        <div style="font-size:12px;color:var(--text-secondary)">컬럼: ${d.headers.join(', ')}</div>
        <div style="font-size:13px;margin:8px 0">${d.row_count}행 감지</div>
        <button class="btn btn-primary btn-small" onclick="App.pages.import.executeImport('${type}', '${d.batch_id}')">
          IMPORT 실행
        </button>
      </div>
    `;
    fileInput.value = '';
  },

  async executeImport(type, batchId) {
    if (!UI.confirm(`${type === 'members' ? '회원' : 'PT이력'} 데이터를 import하시겠습니까?`)) return;

    const action = type === 'members' ? 'import_members' : 'import_orders';
    const res = await API.post(`/api/import.php?action=${action}`, { batch_id: batchId });

    if (res.ok) {
      const s = res.data.stats;
      alert(`처리 완료\n성공: ${s.success}\n스킵: ${s.skipped}\n에러: ${s.error}`);
      await this.render();
    } else {
      alert(res.message);
    }
  },

  async loadBatches() {
    const res = await API.get('/api/import.php?action=batches');
    if (!res.ok) return;
    const batches = res.data.batches;

    if (batches.length === 0) {
      document.getElementById('batchList').innerHTML = '<div class="empty-state">아직 import 기록이 없습니다</div>';
      return;
    }

    document.getElementById('batchList').innerHTML = `
      <table class="data-table">
        <thead><tr><th>Batch ID</th><th>일시</th><th>성공</th><th>스킵</th><th>에러</th><th></th></tr></thead>
        <tbody>
          ${batches.map(b => `
            <tr>
              <td style="font-size:12px">${b.batch_id}</td>
              <td style="font-size:12px;color:var(--text-secondary)">${UI.formatDate(b.imported_at)}</td>
              <td style="color:var(--success)">${b.success_count}</td>
              <td style="color:var(--warning)">${b.skipped_count}</td>
              <td style="color:${b.error_count > 0 ? 'var(--negative)' : 'var(--text-secondary)'}">${b.error_count}</td>
              <td>${b.error_count > 0 || b.skipped_count > 0
                ? `<button class="btn btn-small btn-outline" onclick="App.pages.import.showErrors('${b.batch_id}')">상세</button>` : ''}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  },

  async showErrors(batchId) {
    const res = await API.get(`/api/import.php?action=batch_errors&batch_id=${batchId}`);
    if (!res.ok) return;
    const errors = res.data.errors;

    UI.showModal(`
      <div class="modal-title">Import 상세 — ${batchId}</div>
      <div style="max-height:400px;overflow-y:auto">
        <table class="data-table">
          <thead><tr><th>행</th><th>상태</th><th>사유</th></tr></thead>
          <tbody>
            ${errors.map(e => `
              <tr>
                <td>${e.source_row}</td>
                <td>${UI.statusBadge(e.status === 'error' ? '환불' : '연기')}</td>
                <td style="font-size:12px">${e.message}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick="UI.closeModal()">닫기</button>
      </div>
    `);
  },
});
