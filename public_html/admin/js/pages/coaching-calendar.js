/**
 * Coaching Calendar Management Page (admin only)
 *
 * 매칭월/상품 단위로 PT 회차 예정일을 관리한다.
 * - 신규 생성: cohort_month + product_name + session_count + dates 입력
 * - 수정: session_count + notes + dates 만 변경 가능 (cohort_month/product_name 은 immutable)
 * - 자동 패턴 미리보기로 dates 후보 생성 후 textarea 에서 수정 가능
 * - 삭제: order_sessions.calendar_id 가 NULL 로 끊김 (ON DELETE SET NULL)
 */
App.registerPage('coaching-calendar', {
  calendars: [],

  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h1 class="page-title">매칭 캘린더</h1>
        <button class="btn btn-primary" onclick="App.pages['coaching-calendar'].openCreate()">+ 신규</button>
      </div>
      <div id="calendarList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadList();
  },

  async loadList() {
    const res = await API.get('/api/coaching_calendar.php?action=list');
    if (!res.ok) {
      document.getElementById('calendarList').innerHTML =
        `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    this.calendars = res.data.calendars;

    if (!this.calendars.length) {
      document.getElementById('calendarList').innerHTML =
        '<div class="empty-state">등록된 캘린더가 없습니다</div>';
      return;
    }

    document.getElementById('calendarList').innerHTML = `
      <div class="data-table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>매칭월</th>
              <th>상품</th>
              <th>회차 수</th>
              <th>메모</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            ${this.calendars.map(c => `
              <tr>
                <td>${UI.esc(c.cohort_month)}</td>
                <td>${UI.esc(c.product_name)}</td>
                <td>${c.session_count}회</td>
                <td>${UI.esc(c.notes || '')}</td>
                <td>
                  <button class="btn btn-small btn-secondary"
                          onclick="App.pages['coaching-calendar'].openEdit(${c.id})">편집</button>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  },

  openCreate() {
    this._openModal(null);
  },

  async openEdit(id) {
    const res = await API.get(`/api/coaching_calendar.php?action=get&id=${id}`);
    if (!res.ok) { alert(res.message); return; }
    this._openModal(res.data.calendar);
  },

  _openModal(cal) {
    const isNew = !cal;
    const dates = isNew ? [] : (cal.dates || []).map(d => d.scheduled_date);
    const title = isNew
      ? '신규 캘린더'
      : `${UI.esc(cal.cohort_month)} / ${UI.esc(cal.product_name)}`;

    // 캘린더 위젯 상태
    this._selectedDates = new Set(dates);  // Set<YYYY-MM-DD>
    const firstDate = dates[0];
    const cohortMonth = isNew ? '' : cal.cohort_month;  // 예: '2026-05'
    const now = new Date();
    const todayMonth = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
    const initMonthStr = firstDate
      ? firstDate.slice(0, 7)
      : (cohortMonth && /^\d{4}-\d{2}$/.test(cohortMonth)
          ? cohortMonth
          : todayMonth);
    this._viewMonth = {
      year:  parseInt(initMonthStr.slice(0, 4), 10),
      month: parseInt(initMonthStr.slice(5, 7), 10),  // 1-12
    };
    // NOTE: generatePreview()/save() 가 `cal-dates` 를 참조하던 부분은 Task 4/5 에서 _setSelection / set 직렬화로 교체됨.
    // 이 task 단독으로는 "후보 생성"·"저장" 버튼이 에러를 발생시키므로 Task 5 이전까지 미사용.

    UI.showModal(`
      <div class="modal-title">${title}</div>
      <form id="calForm">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label class="form-label">매칭월 (YYYY-MM)</label>
            <input class="form-input" id="cal-cohort"
                   value="${isNew ? '' : UI.esc(cal.cohort_month)}"
                   ${isNew ? '' : 'disabled style="opacity:0.5"'}
                   placeholder="2026-05" required>
          </div>
          <div class="form-group">
            <label class="form-label">상품명</label>
            <input class="form-input" id="cal-product"
                   value="${isNew ? '' : UI.esc(cal.product_name)}"
                   ${isNew ? '' : 'disabled style="opacity:0.5"'}
                   placeholder="음성PT" required>
          </div>
          <div class="form-group">
            <label class="form-label">회차 수</label>
            <input class="form-input" id="cal-count" type="number" min="1"
                   value="${isNew ? 20 : (cal.session_count || 20)}" required>
          </div>
          <div class="form-group">
            <label class="form-label">메모</label>
            <input class="form-input" id="cal-notes"
                   value="${isNew ? '' : UI.esc(cal.notes || '')}">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">자동 패턴 (선택)</label>
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
            <input class="form-input" id="cal-start" type="date"
                   value="${dates[0] || ''}" style="flex:0 0 160px">
            <select class="form-select" id="cal-pattern" style="flex:0 0 200px">
              <option value="weekday5">평일 5회 (월~금)</option>
              <option value="mwf">주 3회 (월수금)</option>
              <option value="tt">주 2회 (화목)</option>
              <option value="every_day">매일</option>
            </select>
            <button type="button" class="btn btn-secondary"
                    onclick="App.pages['coaching-calendar'].generatePreview()">후보 생성</button>
          </div>
          <div style="color:var(--text-secondary);font-size:12px;margin-top:4px">
            시작일 + 회차 수 + 패턴으로 예정일 후보를 생성합니다. 아래 캘린더에서 클릭으로 추가/해제.
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">예정일 (캘린더에서 클릭으로 선택)</label>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;flex-wrap:wrap">
            <button type="button" class="btn btn-secondary btn-small"
                    onclick="App.pages['coaching-calendar']._clearAll()">전체 해제</button>
            <span id="cal-badge" style="font-size:13px;padding:2px 10px;border-radius:9999px"></span>
          </div>
          <div id="cal-grid-container" style="background:var(--surface-card);border-radius:8px;padding:12px"></div>
          <div style="color:var(--text-secondary);font-size:12px;margin-top:4px">
            회차 수와 선택 수가 일치해야 저장됩니다. 과거 날짜도 클릭 가능 (회색 표시).
          </div>
        </div>

        <div class="modal-actions">
          ${isNew ? '' : `<button type="button" class="btn btn-danger btn-small"
                                   onclick="App.pages['coaching-calendar'].deleteCal(${cal.id})">삭제</button>`}
          <button type="button" class="btn btn-secondary" onclick="UI.closeModal()">취소</button>
          <button type="submit" class="btn btn-primary">${isNew ? '등록' : '저장'}</button>
        </div>
      </form>
    `);

    document.getElementById('calForm').addEventListener('submit', e => {
      e.preventDefault();
      this.save(isNew ? null : cal.id);
    });
  },

  async generatePreview() {
    const start = document.getElementById('cal-start').value;
    const count = parseInt(document.getElementById('cal-count').value, 10);
    const pattern = document.getElementById('cal-pattern').value;
    if (!start || !count) {
      alert('시작일과 회차 수가 필요합니다');
      return;
    }
    const res = await API.post('/api/coaching_calendar.php?action=pattern_preview', {
      start, count, pattern,
    });
    if (!res.ok) { alert(res.message); return; }
    document.getElementById('cal-dates').value = res.data.dates.join('\n');
  },

  async save(id) {
    const isNew = !id;
    const count = parseInt(document.getElementById('cal-count').value, 10);
    const notes = document.getElementById('cal-notes').value.trim();
    const datesRaw = document.getElementById('cal-dates').value;
    const dates = datesRaw.split('\n').map(s => s.trim()).filter(Boolean);

    if (!count || count <= 0) {
      alert('회차 수는 1 이상이어야 합니다');
      return;
    }

    // 신규는 dates 가 반드시 회차 수와 일치해야 함.
    // 수정은 dates 비어있으면 변경 안 함 (서버에서 isset 체크), 입력했으면 일치 검증.
    if (isNew) {
      if (dates.length !== count) {
        alert(`회차 수(${count})와 예정일 개수(${dates.length}) 불일치`);
        return;
      }
    } else if (dates.length > 0 && dates.length !== count) {
      alert(`회차 수(${count})와 예정일 개수(${dates.length}) 불일치. 비워두면 dates 변경 없이 저장됩니다.`);
      return;
    }

    const body = {
      session_count: count,
      notes: notes || null,
    };
    if (dates.length > 0) body.dates = dates;

    let url;
    if (isNew) {
      const cohort = document.getElementById('cal-cohort').value.trim();
      const product = document.getElementById('cal-product').value.trim();
      if (!cohort || !product) {
        alert('매칭월과 상품명은 필수입니다');
        return;
      }
      body.cohort_month = cohort;
      body.product_name = product;
      url = '/api/coaching_calendar.php?action=create';
    } else {
      url = `/api/coaching_calendar.php?action=update&id=${id}`;
    }

    const res = await API.post(url, body);
    if (!res.ok) { alert(res.message); return; }
    UI.closeModal();
    await this.loadList();
  },

  async deleteCal(id) {
    if (!UI.confirm('이 캘린더를 삭제하시겠습니까?\n관련 order_sessions 의 calendar_id 는 NULL 로 끊깁니다.')) return;
    const res = await API.post(`/api/coaching_calendar.php?action=delete&id=${id}`);
    if (!res.ok) { alert(res.message); return; }
    UI.closeModal();
    await this.loadList();
  },
});
