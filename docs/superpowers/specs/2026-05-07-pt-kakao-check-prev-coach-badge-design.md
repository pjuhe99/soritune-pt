# PT 카톡방 입장 체크 — 기존/신규 배지 (Design)

**대상 페이지:** `/admin/#kakao-check`, `/coach/#kakao-check` (pt.soritune.com)
**목표:** 각 order 행에 `[기존]` 또는 `[신규]` 배지를 이름 앞에 표시. 코치/어드민이 카톡방 입장 체크 시 "이 학생이 직전 cohort에도 같은 코치한테 같은 상품 들었던 학생인가"를 한눈에 판별.

---

## 1. 룰

각 표시 대상 order `o`(현재 cohort 화면 행)에 대해, **비교 대상 order `p`** 를 다음과 같이 잡는다.

```
p = MAX(orders) WHERE
      p.member_id     = o.member_id
  AND p.product_name  = o.product_name
  AND p.status        NOT IN ('환불','중단')
  AND p.id            <> o.id
  AND COALESCE(p.cohort_month, DATE_FORMAT(p.start_date,'%Y-%m'))
       < COALESCE(o.cohort_month, DATE_FORMAT(o.start_date,'%Y-%m'))
정렬: COALESCE(p.cohort_month, DATE_FORMAT(p.start_date,'%Y-%m')) DESC, p.id DESC
```

배지 결정:
- `p` 가 존재하고 `p.coach_id = o.coach_id` → **`[기존]`**
- 그 외 (`p` 없음 OR `p.coach_id ≠ o.coach_id`) → **`[신규]`**

### 룰 결정 근거 (브레인스토밍 합의)
- **마지막 정상 order 1건 기준** (운영 갭 달이 있어도 자연 처리). 정확히 한 달 전 cohort만 보는 룰은 운영 휴식 달이 있는 PT 데이터와 맞지 않음.
- **product_name 정확 일치** 필요. 음성PT → 화상PT 이동은 신규로 분류 (코치 종속 커리큘럼 인식).
- **다른 코치 케이스(2026-05 기준 14명, 3%)는 신규로 처리** (2-state). 사용자가 원래 의도에서 `그렇지 않다면 신규`로 명시.

### 정상 order 정의
`status NOT IN ('환불','중단')`. PT 매칭 시스템(`includes/matching_engine.php`)의 prev_coach 룰과 동일.

### 데이터 분포 (2026-05 cohort 기준 453명, 참고용)
| 분류 | 인원 | 비율 |
|------|------|------|
| 기존 (직전 한 달 + 같은 코치) | 329 | 73% |
| 기존 (갭 있음 2~12개월 + 같은 코치) | 27 | 6% |
| 기존 (13개월+ 같은 코치) | 1 | 0.2% |
| 신규 (이전 정상 order 없음) | 82 | 18% |
| 신규 (이전 다른 코치) | 14 | 3% |

→ 약 79%가 `[기존]`, 21%가 `[신규]`.

---

## 2. 표시

이름 컬럼에 inline prefix 배지.

```
| 입장 | 쿠폰 | 특이 | 이름             | ... |
| ☑    | ☐    | ☐    | [기존] 홍길동    | ... |
| ☐    | ☐    | ☐    | [신규] 김철수    | ... |
```

### 배지 마크업
```html
<span class="badge-returning">기존</span> 홍길동
<span class="badge-new">신규</span> 김철수
```

### 배지 CSS (`public_html/assets/css/style.css` 신규)
```css
.badge-returning,
.badge-new {
  display: inline-block;
  padding: 1px 6px;
  margin-right: 4px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 600;
  vertical-align: middle;
}
.badge-returning {
  background: #eee;
  color: #666;
}
.badge-new {
  background: #f57c00;
  color: #fff;
}
```

### 색상 의도
- **기존** = 회색 (`#eee` / `#666`) — 익숙한 학생, 신경 덜 써도 된다는 시그널.
- **신규** = 주황 (`#f57c00` / `#fff`) — 코치/어드민이 신경 써야 할 학생을 빠르게 식별.

---

## 3. 구현

### 3.1 서버 — `kakaoCheckList()` SELECT 1줄 추가

`public_html/api/kakao_check.php` 의 `$oSql` SELECT 컬럼에 다음 추가:

```sql
(SELECT p.coach_id
   FROM orders p
  WHERE p.member_id = o.member_id
    AND p.id <> o.id
    AND p.product_name = o.product_name
    AND p.status NOT IN ('환불','중단')
    AND COALESCE(p.cohort_month, DATE_FORMAT(p.start_date,'%Y-%m'))
         < COALESCE(o.cohort_month, DATE_FORMAT(o.start_date,'%Y-%m'))
  ORDER BY COALESCE(p.cohort_month, DATE_FORMAT(p.start_date,'%Y-%m')) DESC, p.id DESC
  LIMIT 1) AS prev_coach_id
```

응답 JSON 각 order row에 `prev_coach_id`(int|null) 추가.

### 3.2 프론트 — `_row()` 변경 (어드민 + 코치 동일)

```js
const prevCoachId = o.prev_coach_id != null ? parseInt(o.prev_coach_id, 10) : null;
const curCoachId = o.coach_id != null ? parseInt(o.coach_id, 10) : null;
const isReturning = prevCoachId !== null && curCoachId !== null && prevCoachId === curCoachId;
const badge = isReturning
  ? '<span class="badge-returning">기존</span>'
  : '<span class="badge-new">신규</span>';
```

이름 셀:
```js
<td>${badge} ${UI.esc(o.name)}</td>
```

### 3.3 CSS

`public_html/assets/css/style.css` 끝에 §2의 CSS 블록 append.

### 3.4 테스트

`tests/kakao_check_test.php` 끝에 신규 섹션 추가:

- **t_section('list — prev_coach_id: 같은 코치 같은 product')**
  - 같은 회원 + 같은 product + 같은 코치인 이전 정상 order 만들고, 새 cohort에 새 order 만든 뒤 list 호출 → `prev_coach_id == 이전 coach_id`.
- **t_section('list — prev_coach_id: 다른 코치')**
  - 이전 order의 coach_id를 다른 코치로 → list 결과의 `prev_coach_id == 다른 coach_id` (현재 coach_id와 다름).
- **t_section('list — prev_coach_id: 다른 product는 무시')**
  - 같은 회원 + 다른 product(예: '소리튜닝 화상PT') 이전 order 만들고, 음성PT 신규 order → `prev_coach_id IS NULL`.
- **t_section('list — prev_coach_id: 환불/중단 order는 무시')**
  - 이전 order status='환불' → `prev_coach_id IS NULL`.
- **t_section('list — prev_coach_id: 이전 order 없음')**
  - 신규 회원 → `prev_coach_id IS NULL`.

---

## 4. 변경 파일 / 비변경 영역

### 변경
- `public_html/api/kakao_check.php` — `kakaoCheckList()` SELECT만 (라우터/`toggle_flag`/`include_processed` 변경 없음)
- `public_html/admin/js/pages/kakao-check.js` — `_row()` 이름 셀
- `public_html/coach/js/pages/kakao-check.js` — `_row()` 이름 셀
- `public_html/assets/css/style.css` — 배지 2종 (append)
- `tests/kakao_check_test.php` — 5 신규 케이스

### 비변경
- DB 스키마 (계산형, 마이그 없음)
- `kakaoCheckToggleFlag()` / `toggle_flag` 라우터 / 어댑터 / 알림톡
- `cohort_month` / `start_date` 의미

---

## 5. 성능 고려

- correlated subquery는 화면 표시 대상 행 수만큼 (월별 평균 ~500행) 추가 lookup.
- `orders` 테이블 인덱스: `member_id` MUL, `cohort_month` MUL, `status` MUL 모두 존재. subquery WHERE는 `member_id` 인덱스 첫 적중 → product_name + status 필터 → 정렬.
- 11k orders 전체에서 500 회 inner lookup ≈ 수십 ms 수준 예상. 기존 페이지 응답이 즉시인 이유와 같은 패턴 (`includes/matching_engine.php` 의 prev_coach 룰 참고).
- 회귀 시 느려지면 `(member_id, product_name, status, cohort_month)` 복합 인덱스 추가 검토. 현 단계에서는 불필요.

---

## 6. 권한 / 보안

- `kakaoCheckList()` 자체에 새로운 권한 면이 추가되지 않음. 코치는 `WHERE coach_id = self` 로 자기 회원만 보고 있음. subquery는 `prev_coach_id` 만 노출 → 다른 코치 ID 정수 자체는 코치한테 노출되지만 의미 없음 (`isReturning` 비교에만 사용). 현행 응답에 `coach_id` 도 이미 노출.

---

## 7. UX 케이스

| 시나리오 | 결과 |
|---|---|
| 학생이 같은 코치한테 음성PT 두 달 연속 | `[기존]` |
| 학생이 같은 코치한테 음성PT 듣다가 갭 한 달 후 복귀 | `[기존]` |
| 학생이 다른 코치한테 듣다가 이번 달 코치 변경 | `[신규]` |
| 학생이 음성PT 듣다가 이번 달 화상PT 시작 (같은 코치) | `[신규]` (다른 product) |
| 첫 PT 학생 | `[신규]` |
| 이전 order가 환불/중단만 있고 그 후 첫 정상 order | `[신규]` |
| 매칭대기 상태(coach_id NULL) | 코치 페이지에선 자기 회원만 보이므로 자체 미노출. 어드민에선 매칭대기 행도 보이는데, `o.coach_id IS NULL` 이라 항상 `isReturning=false` → `[신규]`. |

---

## 8. 비범위

- 카톡방 입장 후 자동 알림(쿠폰지급) 로직 — 본 작업과 별개.
- 배지 클릭 시 이전 order 상세 보기 — YAGNI.
- 배지에 마우스오버 툴팁(이전 cohort 월/코치 정보) — 추후 follow-up 가능. 본 spec에는 미포함.
- 알림톡 어댑터 변경 — 본 spec은 표시만, 발송 룰은 그대로 (kakao/coupon/special OR 차단 유지).
