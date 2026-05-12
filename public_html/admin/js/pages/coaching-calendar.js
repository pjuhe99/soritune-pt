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
    // 셀의 _toggleDate / 헤더 화살표의 _navigateMonth onclick 참조는 Task 3 까지 의도적으로 orphan.

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

    this._renderCalendar();
    this._updateBadge();
    document.getElementById('cal-count').addEventListener('input', () => this._updateBadge());
    document.getElementById('calForm').addEventListener('submit', e => {
      e.preventDefault();
      this.save(isNew ? null : cal.id);
    });
  },

  /**
   * 주어진 year/month(1-12)의 7×6 그리드 cell 배열 반환.
   * 첫 주는 sunday-start. 다른 달 spillover는 inMonth=false.
   * @returns {Array<{date:string, inMonth:boolean, isToday:boolean, isPast:boolean}>}
   */
  _buildMonthGrid(year, month) {
    const firstOfMonth = new Date(year, month - 1, 1);
    const startWeekday = firstOfMonth.getDay();  // 0=Sun
    const daysInMonth = new Date(year, month, 0).getDate();
    const today = new Date();
    const todayStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;

    const cells = [];
    // 앞쪽 spillover
    const prevMonthLast = new Date(year, month - 1, 0).getDate();
    for (let i = startWeekday - 1; i >= 0; i--) {
      const d = prevMonthLast - i;
      const pm = month === 1 ? 12 : month - 1;
      const py = month === 1 ? year - 1 : year;
      const ds = `${py}-${String(pm).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      cells.push({date: ds, inMonth: false, isToday: ds === todayStr, isPast: ds < todayStr});
    }
    // 이번 달
    for (let d = 1; d <= daysInMonth; d++) {
      const ds = `${year}-${String(month).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      cells.push({date: ds, inMonth: true, isToday: ds === todayStr, isPast: ds < todayStr});
    }
    // 뒤쪽 spillover (42 칸 채움)
    let nextD = 1;
    while (cells.length < 42) {
      const nm = month === 12 ? 1 : month + 1;
      const ny = month === 12 ? year + 1 : year;
      const ds = `${ny}-${String(nm).padStart(2,'0')}-${String(nextD).padStart(2,'0')}`;
      cells.push({date: ds, inMonth: false, isToday: ds === todayStr, isPast: ds < todayStr});
      nextD++;
    }
    return cells;
  },

  /**
   * `_viewMonth` 기준으로 그리드 HTML을 #cal-grid-container 에 렌더.
   * 선택 상태는 `_selectedDates`. 셀 onclick 핸들러 `_toggleDate` 는 Task 3에서 정의.
   */
  _renderCalendar() {
    const {year, month} = this._viewMonth;
    const cells = this._buildMonthGrid(year, month);
    const weekdays = ['일','월','화','수','목','금','토'];

    const headerHtml = `
      <div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:12px">
        <button type="button" class="btn btn-secondary btn-small"
                onclick="App.pages['coaching-calendar']._navigateMonth(-1)"
                aria-label="이전 달">←</button>
        <div style="font-size:15px;font-weight:700;color:#fff;min-width:120px;text-align:center">
          ${year}년 ${month}월
        </div>
        <button type="button" class="btn btn-secondary btn-small"
                onclick="App.pages['coaching-calendar']._navigateMonth(1)"
                aria-label="다음 달">→</button>
      </div>
    `;

    const weekdayHtml = `
      <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:4px">
        ${weekdays.map(w => `
          <div style="text-align:center;font-size:11px;color:#b3b3b3;padding:4px 0">${w}</div>
        `).join('')}
      </div>
    `;

    const cellsHtml = `
      <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px">
        ${cells.map(c => this._renderCell(c)).join('')}
      </div>
    `;

    document.getElementById('cal-grid-container').innerHTML =
      headerHtml + weekdayHtml + cellsHtml;
  },

  /**
   * 단일 셀 HTML. PT 디자인 시스템 따라 inline 스타일.
   * 상태: 선택(orange), 오늘(outline), 과거(muted), spillover(disabled), hover(lighten)
   * `data-selected="true"` 마커로 hover override 가 선택 셀에서는 작동하지 않도록 함 (plan 의 "concern" 결정 B).
   */
  _renderCell(c) {
    if (!c.inMonth) {
      return `<div style="aspect-ratio:1;display:flex;align-items:center;justify-content:center;
                          color:#4d4d4d;font-size:13px;cursor:default">${parseInt(c.date.slice(8),10)}</div>`;
    }
    const isSelected = this._selectedDates.has(c.date);
    const day = parseInt(c.date.slice(8), 10);
    const bg = isSelected ? '#FF5E00' : 'transparent';
    const color = isSelected ? '#fff' : (c.isPast ? '#7c7c7c' : '#fff');
    const outline = c.isToday ? 'outline:2px solid #FF5E00;outline-offset:-2px;' : '';
    const selectedAttr = isSelected ? 'data-selected="true"' : '';
    return `
      <div data-date="${c.date}" ${selectedAttr}
           onclick="App.pages['coaching-calendar']._toggleDate('${c.date}')"
           style="aspect-ratio:1;display:flex;align-items:center;justify-content:center;
                  background:${bg};color:${color};font-size:13px;font-weight:${isSelected?700:400};
                  border-radius:6px;cursor:pointer;${outline}
                  transition:background 0.1s"
           onmouseover="if (!this.dataset.selected) this.style.background='#272727'"
           onmouseout="this.style.background='${bg}'">
        ${day}
      </div>
    `;
  },

  /**
   * 셀 클릭 시 선택 set 토글. 해당 셀만 다시 그리고 배지 갱신.
   * (전체 그리드 재렌더 안 함 — 빈번한 클릭 대비 가벼움 유지)
   */
  _toggleDate(dateStr) {
    if (this._selectedDates.has(dateStr)) {
      this._selectedDates.delete(dateStr);
    } else {
      this._selectedDates.add(dateStr);
    }
    // 빠른 갱신: 전체 그리드 재렌더 (셀 ~42개 + onclick 핸들러 inline 이라 cheap).
    this._renderCalendar();
    this._updateBadge();
  },

  /**
   * 화살표 클릭 시 viewMonth 만 변경. selectedDates 는 유지.
   */
  _navigateMonth(delta) {
    let {year, month} = this._viewMonth;
    month += delta;
    if (month < 1) { month = 12; year--; }
    else if (month > 12) { month = 1; year++; }
    this._viewMonth = {year, month};
    this._renderCalendar();
  },

  /**
   * 회차 수 (cal-count input) 와 선택 수를 비교해 배지 갱신.
   * 일치 = 초록, 불일치 = 빨강.
   */
  _updateBadge() {
    const badge = document.getElementById('cal-badge');
    if (!badge) return;
    const countInput = document.getElementById('cal-count');
    const count = countInput ? parseInt(countInput.value, 10) || 0 : 0;
    const selected = this._selectedDates.size;
    const match = count === selected;
    badge.textContent = `회차 ${count} / 선택 ${selected}`;
    badge.style.background = match ? 'rgba(30,215,96,0.15)' : 'rgba(243,114,127,0.15)';
    badge.style.color      = match ? '#1ed760'              : '#f3727f';
  },

  /**
   * 전체 선택 해제. view 는 유지.
   */
  _clearAll() {
    this._selectedDates.clear();
    this._renderCalendar();
    this._updateBadge();
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
