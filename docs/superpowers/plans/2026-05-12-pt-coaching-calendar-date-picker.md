# PT 매칭 캘린더 예정일 클릭형 캘린더 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PT 매칭 캘린더 생성/수정 모달의 예정일 입력을 textarea(한 줄에 하나)에서 **클릭형 월 그리드 캘린더**로 교체. textarea 완전 제거, 자동 패턴 버튼은 결과를 캘린더 선택으로 채우는 형태로 유지.

**Architecture:** 단일 파일(`public_html/admin/js/pages/coaching-calendar.js`) 내부 변경. `_selectedDates: Set<YYYY-MM-DD>` + `_viewMonth: {year, month}` 상태 page 객체에 추가. 헬퍼 함수 5개(_buildMonthGrid / _renderCalendar / _toggleDate / _setSelection / _updateBadge) 추가. 서버 API 변경 0, POST body 형식 그대로(`dates: [...]`).

**Tech Stack:** Vanilla JS (라이브러리 의존성 0), PT 디자인 시스템 (Soritune Orange `#FF5E00` selected, near-black surfaces, inline styles). PHP 서버 회귀로 검증(JS 단위 테스트 인프라 없음 — 브라우저 manual smoke).

**Spec:** `docs/superpowers/specs/2026-05-12-pt-coaching-calendar-date-picker-design.md`

---

## File Structure

| File | 변경 | 책임 |
|---|---|---|
| `public_html/admin/js/pages/coaching-calendar.js` | Modify (+~120 / -~15) | 캘린더 페이지 전체 — 모달 안에 캘린더 그리드/네비/배지/헬퍼 함수 모두 inline |

서버/CSS/다른 파일 변경 없음.

---

## Task 1: textarea 제거 + 캘린더 영역 placeholder

**Files:**
- Modify: `public_html/admin/js/pages/coaching-calendar.js:80-158` (`_openModal` 함수 내부)

기존 textarea form-group(137-143)을 캘린더 컨테이너 div로 교체. 상태 변수 init만 추가하고 그리드 렌더는 Task 2에서. 자동패턴 버튼 옆에 "전체 해제" 버튼과 배지 placeholder 추가.

- [ ] **Step 1: `_openModal` 상단에 상태 초기화 추가**

기존 `_openModal(cal) {` 직후, `const isNew = ...` 라인들 아래에 다음 추가:

```javascript
    // 캘린더 위젯 상태
    this._selectedDates = new Set(dates);  // Set<YYYY-MM-DD>
    const firstDate = dates[0];
    const cohortMonth = isNew ? '' : cal.cohort_month;  // 예: '2026-05'
    const initMonthStr = firstDate
      ? firstDate.slice(0, 7)
      : (cohortMonth && /^\d{4}-\d{2}$/.test(cohortMonth)
          ? cohortMonth
          : new Date().toISOString().slice(0, 7));
    this._viewMonth = {
      year:  parseInt(initMonthStr.slice(0, 4), 10),
      month: parseInt(initMonthStr.slice(5, 7), 10),  // 1-12
    };
```

- [ ] **Step 2: 자동 패턴 form-group 안 도움말 텍스트 갱신**

기존 (`_openModal` 안 form-group "자동 패턴 (선택)"):

```html
          <div style="color:var(--text-secondary);font-size:12px;margin-top:4px">
            시작일 + 회차 수 + 패턴으로 예정일 후보를 생성합니다. 아래 textarea 에서 수정 가능.
          </div>
```

다음으로 교체:

```html
          <div style="color:var(--text-secondary);font-size:12px;margin-top:4px">
            시작일 + 회차 수 + 패턴으로 예정일 후보를 생성합니다. 아래 캘린더에서 클릭으로 추가/해제.
          </div>
```

- [ ] **Step 3: textarea form-group을 캘린더 컨테이너로 교체**

기존 (현 137-143):

```html
        <div class="form-group">
          <label class="form-label">예정일 (한 줄에 하나, YYYY-MM-DD)</label>
          <textarea class="form-textarea" id="cal-dates" rows="10"
                    placeholder="2026-05-01&#10;2026-05-02&#10;...">${dates.join('\n')}</textarea>
          <div style="color:var(--text-secondary);font-size:12px;margin-top:4px">
            한 줄에 하나. 회차 수와 일치해야 함. 비워두면 dates 변경 없이 저장.
          </div>
        </div>
```

다음으로 교체:

```html
        <div class="form-group">
          <label class="form-label">예정일 (캘린더에서 클릭으로 선택)</label>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;flex-wrap:wrap">
            <button type="button" class="btn btn-secondary btn-small"
                    onclick="App.pages['coaching-calendar']._clearAll()">전체 해제</button>
            <span id="cal-badge" style="font-size:13px;padding:2px 10px;border-radius:9999px"></span>
          </div>
          <div id="cal-grid-container" style="background:#181818;border-radius:8px;padding:12px"></div>
          <div style="color:var(--text-secondary);font-size:12px;margin-top:4px">
            회차 수와 선택 수가 일치해야 저장됩니다. 과거 날짜도 클릭 가능 (회색 표시).
          </div>
        </div>
```

- [ ] **Step 4: 모달 open 직후 manual smoke**

브라우저: `/admin/#coaching-calendar` → "+ 신규" → 모달 열림 → textarea 사라지고 "캘린더에서 클릭으로 선택" 라벨 + "전체 해제" 버튼 + 빈 컨테이너 보임. (그리드는 Task 2에서.) 자바스크립트 콘솔 에러 없음.

- [ ] **Step 5: Commit**

```bash
git add public_html/admin/js/pages/coaching-calendar.js
git commit -m "refactor(pt): coaching calendar 모달 textarea 제거 + 캘린더 placeholder"
```

---

## Task 2: 월 그리드 렌더 헬퍼

**Files:**
- Modify: `public_html/admin/js/pages/coaching-calendar.js` (page 객체 메서드 추가)

`_buildMonthGrid(year, month)` 와 `_renderCalendar()` 추가. `_openModal` 마지막에 `_renderCalendar()` 호출하여 초기 그리드 표시. 셀 클릭 핸들러는 Task 3.

- [ ] **Step 1: `_buildMonthGrid` 헬퍼 추가 (page 객체 메서드)**

`save` 함수 직전(또는 page 객체 끝 부분)에 추가:

```javascript
  /**
   * 주어진 year/month(1-12)의 7×6 그리드 cell 배열 반환.
   * 첫 주는 sunday-start. 다른 달 spillover는 inMonth=false.
   * @returns {Array<{date:string|null, inMonth:boolean, isToday:boolean, isPast:boolean}>}
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
```

- [ ] **Step 2: `_renderCalendar` 추가**

`_buildMonthGrid` 직후에 추가:

```javascript
  /**
   * `_viewMonth` 기준으로 그리드 HTML을 #cal-grid-container 에 렌더.
   * 선택 상태는 `_selectedDates`. 셀 onclick 은 Task 3에서 wiring.
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
    return `
      <div data-date="${c.date}"
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
```

- [ ] **Step 3: `_openModal` 끝에서 `_renderCalendar` 호출**

기존 `_openModal` 의 마지막 부분(form submit listener 등록 후):

```javascript
    document.getElementById('calForm').addEventListener('submit', e => {
      e.preventDefault();
      this.save(isNew ? null : cal.id);
    });
  },
```

submit listener 등록 **앞**에 다음 한 줄 추가:

```javascript
    this._renderCalendar();
    document.getElementById('calForm').addEventListener('submit', e => {
      e.preventDefault();
      this.save(isNew ? null : cal.id);
    });
  },
```

- [ ] **Step 4: Manual smoke**

브라우저: 신규 모달 열기 → 캘린더 그리드 표시(현재 달 또는 today 기준 월) → 7×6 셀, 일~토 헤더, 좌우 화살표 + "년 월" 라벨 보임 → 화살표는 아직 작동 안 함(Task 3) → 콘솔 에러 없음.

수정 모달 열기(기존 캘린더 row 편집 클릭) → 첫 dates의 달 표시 → 기존 dates가 오렌지로 표시됨.

- [ ] **Step 5: Commit**

```bash
git add public_html/admin/js/pages/coaching-calendar.js
git commit -m "feat(pt): coaching calendar 월 그리드 렌더 (선택 표시·오늘 outline·spillover muted)"
```

---

## Task 3: 셀 토글 + 화살표 네비 + 배지 + 전체 해제

**Files:**
- Modify: `public_html/admin/js/pages/coaching-calendar.js` (페이지 메서드 추가)

상호작용 wiring. 셀 onclick은 Task 2에서 이미 `_toggleDate('YYYY-MM-DD')` 로 박혀있음 — 여기서 핸들러 본체 구현. 화살표 핸들러, 배지 업데이트, 전체 해제 추가.

- [ ] **Step 1: `_toggleDate` 추가**

```javascript
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
```

- [ ] **Step 2: `_navigateMonth` 추가**

```javascript
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
```

- [ ] **Step 3: `_updateBadge` 추가**

```javascript
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
```

- [ ] **Step 4: `_clearAll` 추가**

```javascript
  /**
   * 전체 선택 해제. view 는 유지.
   */
  _clearAll() {
    this._selectedDates.clear();
    this._renderCalendar();
    this._updateBadge();
  },
```

- [ ] **Step 5: 모달 open + 회차 수 변경 시 배지 갱신 wiring**

`_openModal` 마지막 부분(`this._renderCalendar();` 호출 직후, submit listener 등록 직전):

```javascript
    this._renderCalendar();
    this._updateBadge();
    document.getElementById('cal-count').addEventListener('input', () => this._updateBadge());
    document.getElementById('calForm').addEventListener('submit', e => {
      e.preventDefault();
      this.save(isNew ? null : cal.id);
    });
  },
```

- [ ] **Step 6: Manual smoke**

브라우저: 신규 모달 → 빈 셀 클릭 → 오렌지로 변하고 배지 "회차 20 / 선택 1" 빨강. 같은 셀 다시 클릭 → 해제. 좌/우 화살표 → 월 라벨 바뀌고 선택은 유지. "전체 해제" → 모든 선택 사라지고 배지 "선택 0". 회차 수 input 값을 0으로 바꿔도 배지 즉시 갱신.

- [ ] **Step 7: Commit**

```bash
git add public_html/admin/js/pages/coaching-calendar.js
git commit -m "feat(pt): coaching calendar 클릭 토글·화살표 네비·배지·전체 해제"
```

---

## Task 4: 자동 패턴 통합

**Files:**
- Modify: `public_html/admin/js/pages/coaching-calendar.js:160-173` (`generatePreview` 함수)

자동 패턴 후보를 textarea에 채우는 대신 `_selectedDates` set을 replace + view를 첫 날짜 달로 점프.

- [ ] **Step 1: `_setSelection` 헬퍼 추가**

(page 객체 메서드, 다른 헬퍼들 옆에 추가)

```javascript
  /**
   * datesArray로 _selectedDates 를 replace. view 를 첫 날짜의 달로 이동.
   * 자동 패턴 결과 적용 시 호출.
   */
  _setSelection(datesArray) {
    this._selectedDates = new Set(datesArray);
    if (datesArray.length > 0) {
      const first = datesArray[0];
      this._viewMonth = {
        year:  parseInt(first.slice(0, 4), 10),
        month: parseInt(first.slice(5, 7), 10),
      };
    }
    this._renderCalendar();
    this._updateBadge();
  },
```

- [ ] **Step 2: `generatePreview` 본체 교체**

기존 (160-173):

```javascript
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
```

다음으로 교체 (마지막 줄만 변경 — textarea 대신 _setSelection):

```javascript
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
    this._setSelection(res.data.dates);
  },
```

- [ ] **Step 3: Manual smoke**

브라우저: 신규 모달 → 시작일 2026-05-04 + 회차 5 + "평일 5회" → "후보 생성" 클릭 → 캘린더가 2026년 5월로 점프 + 5/4~5/8 5개 셀이 오렌지 + 배지 "5/5" 초록. 셀 하나 클릭으로 해제하면 빨강. 자동 패턴 다시 누르면 set 다시 replace됨.

- [ ] **Step 4: Commit**

```bash
git add public_html/admin/js/pages/coaching-calendar.js
git commit -m "feat(pt): coaching calendar 자동 패턴 → 캘린더 pre-select (textarea 대체)"
```

---

## Task 5: 저장 핸들러 갱신 + 회귀 + 헤더 주석 + DEV push

**Files:**
- Modify: `public_html/admin/js/pages/coaching-calendar.js:175-224` (`save` 함수) + 파일 상단 주석

textarea read를 set serialization으로 교체. 회귀 확인 후 push.

- [ ] **Step 1: `save` 함수 dates 읽기 부분 교체**

기존 (179-180):

```javascript
    const datesRaw = document.getElementById('cal-dates').value;
    const dates = datesRaw.split('\n').map(s => s.trim()).filter(Boolean);
```

다음으로 교체:

```javascript
    const dates = Array.from(this._selectedDates).sort();
```

- [ ] **Step 2: 수정 시 빈 선택 분기 단순화**

기존 (189-197):

```javascript
    if (isNew) {
      if (dates.length !== count) {
        alert(`회차 수(${count})와 예정일 개수(${dates.length}) 불일치`);
        return;
      }
    } else if (dates.length > 0 && dates.length !== count) {
      alert(`회차 수(${count})와 예정일 개수(${dates.length}) 불일치. 비워두면 dates 변경 없이 저장됩니다.`);
      return;
    }
```

다음으로 교체 (수정 시도 빈 선택은 의도된 dates 변경 의도가 명확하지 않으므로 일치 강제):

```javascript
    if (dates.length !== count) {
      alert(`회차 수(${count})와 선택 수(${dates.length})가 일치해야 합니다`);
      return;
    }
```

기존 "수정은 비워두면 dates 변경 없이 저장" 동작은 textarea 시절의 affordance(빈 textarea로 두면 무변경)였음. 캘린더 UI에서는 의도적 빈 선택 vs 무변경 의도를 구분할 수 없으므로 일치 강제가 명확. (수정 모달 open 시 기존 dates 가 항상 pre-fill되므로 사용자가 일부러 "전체 해제" 안 누르면 항상 일치 상태로 시작.)

- [ ] **Step 3: dates 항상 포함하도록 body 구성 변경**

기존 (199-203):

```javascript
    const body = {
      session_count: count,
      notes: notes || null,
    };
    if (dates.length > 0) body.dates = dates;
```

다음으로 교체 (Step 2 로직 변경에 따라 dates 는 항상 일치하므로 항상 포함):

```javascript
    const body = {
      session_count: count,
      notes: notes || null,
      dates: dates,
    };
```

- [ ] **Step 4: 파일 상단 주석(1-9) 갱신**

기존:

```javascript
/**
 * Coaching Calendar Management Page (admin only)
 *
 * 매칭월/상품 단위로 PT 회차 예정일을 관리한다.
 * - 신규 생성: cohort_month + product_name + session_count + dates 입력
 * - 수정: session_count + notes + dates 만 변경 가능 (cohort_month/product_name 은 immutable)
 * - 자동 패턴 미리보기로 dates 후보 생성 후 textarea 에서 수정 가능
 * - 삭제: order_sessions.calendar_id 가 NULL 로 끊김 (ON DELETE SET NULL)
 */
```

다음으로 교체:

```javascript
/**
 * Coaching Calendar Management Page (admin only)
 *
 * 매칭월/상품 단위로 PT 회차 예정일을 관리한다.
 * - 신규 생성: cohort_month + product_name + session_count + dates 입력
 * - 수정: session_count + notes + dates 만 변경 가능 (cohort_month/product_name 은 immutable)
 * - 예정일은 클릭형 캘린더로 선택. 자동 패턴 버튼은 후보를 캘린더에 pre-select.
 * - 삭제: order_sessions.calendar_id 가 NULL 로 끊김 (ON DELETE SET NULL)
 */
```

- [ ] **Step 5: PHP 회귀**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -3
```

Expected: `Total: 646  Pass: 646  Fail: 0` (서버 변경 0 이므로 회귀 0).

- [ ] **Step 6: Manual DEV smoke 시나리오**

브라우저(`https://dev-pt.soritune.com/admin/#coaching-calendar`):

1. **신규 + 자동 패턴**: "+ 신규" → 매칭월 2026-06 + 상품 "음성PT" + 회차 5 + 시작일 2026-06-01 + "평일 5회" → "후보 생성" → 캘린더 2026년 6월 + 5/1~ 5일 오렌지 → "등록" → 리스트 갱신.
2. **수정 + 부분 변경**: 새로 만든 row "편집" → 기존 5개 오렌지 표시 → 1개 셀 다시 클릭(해제) → 다른 미선택 셀 클릭(추가) → 배지 5/5 유지 → "저장".
3. **수정 + 화살표**: 다른 row 편집 → 좌/우 화살표로 다른 달 이동 → 추가 셀 클릭 → 저장.
4. **전체 해제 + 저장 시도**: "전체 해제" 클릭 → 저장 → "회차 수(5)와 선택 수(0)가 일치해야 합니다" alert.
5. **회차 수 변경**: 모달 열고 회차 수 input을 3으로 변경 → 배지 즉시 "회차 3 / 선택 5" 빨강 → 2개 클릭 해제 → 3/3 초록.
6. **과거 날짜**: 화살표로 과거 달 이동 → 회색 셀 클릭 가능 → 선택 표시 됨.
7. **삭제 동작 무변경**: 기존 row "편집" → "삭제" → confirm → 리스트에서 사라짐.

각 시나리오에서 JS 콘솔 에러 0건 확인.

- [ ] **Step 7: Commit + dev push**

```bash
cd /root/pt-dev
git add public_html/admin/js/pages/coaching-calendar.js
git commit -m "feat(pt): coaching calendar 저장 핸들러 set 직렬화 + 회차 일치 강제 + 파일 헤더"
git push origin dev
```

- [ ] **Step 8: ⛔ 사용자 DEV 확인 게이트**

dev push 완료 후 사용자에게 `https://dev-pt.soritune.com/admin/#coaching-calendar` 검증 요청. 운영 반영은 사용자가 "운영 반영해줘" 명시 요청 시에만(별도 task로) 진행.

---

## Self-Review Notes

**Spec coverage:**
- 모달 구조 ✅ Task 1, 2
- 셀 상태 (선택/오늘/과거/spillover) ✅ Task 2 `_renderCell`
- 상호작용 (클릭/화살표/자동패턴/전체해제) ✅ Task 3, 4
- 시작 달 결정 (신규/수정) ✅ Task 1 Step 1
- 카운트 배지 (live 색) ✅ Task 3
- 검증 (count 일치 강제) ✅ Task 5 Step 2
- 서버 영향 0 ✅ Task 5 Step 5 회귀 확인
- 파일 변경 (단일 JS 파일) ✅

**Placeholder scan:** 없음 — 모든 코드 블록은 실제 코드, 모든 명령은 실행 가능.

**Type consistency:** `_selectedDates: Set<string>`, `_viewMonth: {year, month}` 일관. `_renderCalendar` / `_renderCell` / `_buildMonthGrid` / `_toggleDate` / `_navigateMonth` / `_updateBadge` / `_clearAll` / `_setSelection` 모두 task 간 일관 사용.

**Ambiguity:** 수정 시 빈 선택 동작이 textarea 시절과 달라진 점은 Task 5 Step 2 본문에 명시.
