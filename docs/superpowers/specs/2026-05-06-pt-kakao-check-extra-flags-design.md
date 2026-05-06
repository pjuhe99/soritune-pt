# PT 카톡방 입장 체크 — 쿠폰 지급 / 특이 건 플래그 추가

**작성일:** 2026-05-06
**상태:** Spec / 사용자 리뷰 대기

## 배경

`pt.soritune.com/admin/#kakao-check` 카톡방 입장 체크 탭은 매일 19시 KST 알림톡(`pt_kakao_room_remind`) 대상 선정에 사용된다. 현재는 `orders.kakao_room_joined = 0`인 회원에게만 발송된다.

운영 중 두 가지 상황이 추가로 식별됨:

1. **쿠폰 지급** — 회원이 "다음 기수에 듣겠다"고 해서 쿠폰을 지급한 경우. 카톡방 입장은 안 했지만 알림톡을 보낼 필요가 없음.
2. **특이 건** — 코치를 통해 별도 문의를 한 경우. 운영자가 사유를 남기고, 알림톡 발송 일시 중단.

두 케이스 모두 알림톡 발송 대상에서 제외해야 하며, 카톡방 입장 체크와 동일한 시각적 처리(`opacity 0.55`, "처리 완료도 보기" 토글로 가시성 제어)가 필요하다.

## 목표

- `orders` 테이블에 두 플래그(`coupon_issued`, `special_case`) + 메모 필드(`special_case_note`) 추가
- 어드민 / 코치 양쪽 `kakao-check` 페이지에 두 체크박스 추가
- 세 플래그(`kakao_room_joined`, `coupon_issued`, `special_case`) 중 하나라도 1이면:
  - 알림톡 발송 대상에서 제외
  - UI 행 dim(`opacity:0.55`)
  - "처리 완료도 보기" OFF 시 행 숨김
- 코치 권한: 자기 `coach_id` 회원에게만 토글 가능 (기존 `kakao_room_joined`과 동일 규칙)

## 비목표

- 일괄 처리(bulk action) — 개별 토글만. 필요해지면 후속 작업.
- bypass(1회 발송 차단) UI — PT엔 미구현 상태이며 본 작업과 분리.
- 쿠폰 지급 금액/사유 입력 — 단순 0/1 플래그만.
- `_at` / `_by` 메타의 UI 노출 — DB에 저장하되 표시는 후속.

## 데이터 모델

`orders` 테이블에 7개 컬럼 추가:

```sql
ALTER TABLE orders
  ADD COLUMN coupon_issued    TINYINT(1)    NOT NULL DEFAULT 0  AFTER kakao_room_joined_by,
  ADD COLUMN coupon_issued_at DATETIME      NULL                AFTER coupon_issued,
  ADD COLUMN coupon_issued_by INT UNSIGNED  NULL                AFTER coupon_issued_at,
  ADD COLUMN special_case      TINYINT(1)   NOT NULL DEFAULT 0  AFTER coupon_issued_by,
  ADD COLUMN special_case_at   DATETIME     NULL                AFTER special_case,
  ADD COLUMN special_case_by   INT UNSIGNED NULL                AFTER special_case_at,
  ADD COLUMN special_case_note VARCHAR(255) NULL                AFTER special_case_by;
```

**메타 컬럼 규칙** — 기존 `kakao_room_joined*` 패턴을 그대로 따름:

- `_at`: 토글 ON 시점 `NOW()`. OFF 시 NULL로 복원.
- `_by`: 토글한 actor의 user.id. coach인지 admin인지는 `change_logs.actor_type`으로 추적.
- `special_case_note`:
  - `special_case=1`로 토글할 때 함께 저장(빈 문자열 허용 → NULL 저장).
  - `special_case=0`으로 OFF할 때 NULL로 리셋.

마이그레이션 파일: `migrations/20260506_orders_add_coupon_special.sql`

## API

`api/kakao_check.php`

### `list` 액션 변경

`SELECT`에 `coupon_issued`, `special_case`, `special_case_note` 추가. WHERE/JOIN 변경 없음.

### 새 액션 `toggle_flag` (단일 통합 핸들러)

```
POST /api/kakao_check.php?action=toggle_flag
{
  "order_id": 123,
  "flag": "coupon" | "special" | "kakao",
  "value": 0 | 1,
  "note": "string?"   // flag='special'일 때만 사용
}
```

**동작:**

- `flag='kakao'`: 기존 `toggle_join`과 동일하게 `kakao_room_joined`/`_at`/`_by` 갱신.
- `flag='coupon'`: `coupon_issued`/`_at`/`_by` 갱신. `note` 무시.
- `flag='special'`:
  - `value=1`: `special_case=1`, `_at=NOW()`, `_by=actor_id`, `special_case_note = note ?: NULL`.
  - `value=0`: `special_case=0`, `_at=NULL`, `_by=NULL`, `special_case_note=NULL`.
- 같은 값이면 no-op(idempotent), `change_logs`에도 기록 안 함.
- `change_logs.action`:
  - `kakao_room_join` / `kakao_room_unjoin` (기존 유지)
  - `coupon_issued_set` / `coupon_issued_unset`
  - `special_case_set` / `special_case_unset`
- `change_logs.changes_after`에 `note` 변경도 포함 (special만).

**권한 체크 (모든 flag 공통):**

- admin: 모든 order 토글 가능
- coach: `orders.coach_id = $user.id`인 order만 토글 가능, 그 외 403

### `toggle_join`(기존) 호환성

당분간 유지(deprecated). 새 클라이언트 코드는 `toggle_flag`만 사용. 차후 정리.

## UI

### 어드민 (`public_html/admin/js/pages/kakao-check.js`)

표 헤더 (12열):

```
선택 | 입장 | 쿠폰 | 특이 | 이름 | 전화번호 | 이메일 | 상품 | 코치 | 시작일 | 상태 | 코호트
```

각 체크 컬럼 폭 32px, 헤더는 짧은 명칭(`쿠폰`/`특이`).

### 코치 (`public_html/coach/js/pages/kakao-check.js`)

표 헤더 (9열):

```
입장 | 쿠폰 | 특이 | 이름 | 전화번호 | 이메일 | 상품 | 시작일 | 상태
```

### 행 dim 처리

```js
const isProcessed = parseInt(o.kakao_room_joined,10) === 1
                 || parseInt(o.coupon_issued,10)    === 1
                 || parseInt(o.special_case,10)     === 1;
// row style: isProcessed ? 'opacity:0.55' : ''
```

### 특이 건 메모 UX

- **체크 ON**: `prompt('특이 사유를 입력하세요 (없으면 비워두세요)', '')` 호출 → 입력값(빈 문자열 허용)을 `note`로 함께 POST.
  - prompt에서 Cancel 클릭 시 토글 취소(체크박스 원복).
- **체크된 행의 메모 표시**: 체크박스 셀 아래 `<small>` 16자 ellipsis. 클릭 시 다시 prompt → `toggle_flag` flag=special, value=1, note=새값. (체크 상태는 1 유지.)
- **체크 OFF**: `note`도 자동 NULL.

### 필터 라벨 변경

- 변수: `includeJoined` → `includeProcessed`
- 쿼리 파라미터: `include_joined` → `include_processed`
- 라벨: "체크 완료도 보기" → "처리 완료도 보기"
- API `list` 액션: `include_processed`이면 세 플래그 무관하게 모든 row 반환. 기본(0)이면 세 플래그 모두 0인 row만.

### 토글 실패 시 원복

기존 패턴 그대로: 서버 응답이 실패하면 체크박스 원복. 메모 prompt 입력 후 서버 실패 시에도 체크 원복(메모는 어차피 저장 안 됨).

## 알림톡 어댑터

`public_html/includes/notify/source_pt_orders_query.php` WHERE 절:

```sql
WHERE o.product_name      = ?
  AND o.kakao_room_joined = ?
  AND o.coupon_issued     = 0
  AND o.special_case      = 0
  AND o.cohort_month      = ?
  AND o.status IN (...)
```

- `coupon_issued = 0` / `special_case = 0`은 **상수로 박음**. cfg에서 받지 않음.
- `kakao_room_joined`은 기존처럼 cfg에서 받음(향후 다른 시나리오용 0/1 가변성 유지).
- 시나리오 파일(`scenarios/pt_kakao_room_remind.php`) 변경 없음 — 어댑터에서 자동 차단.

## 테스트

PHP 테스트 (`tests/kakao_check_test.php`, `tests/notify_pt_orders_query_test.php`)

### `tests/kakao_check_test.php` 추가 케이스

1. `toggle_flag(coupon, value=1)` → `coupon_issued=1`, `coupon_issued_at` NOT NULL, `coupon_issued_by = actor_id`.
2. `toggle_flag(coupon, value=0)` → 다 NULL/0으로 복원.
3. `toggle_flag(special, value=1, note='문의함')` → `special_case=1`, `special_case_note='문의함'`.
4. `toggle_flag(special, value=1, note='')` → `special_case=1`, `special_case_note=NULL` (빈 문자열 → NULL).
5. `toggle_flag(special, value=0)` → 모든 special 컬럼 리셋(note 포함).
6. 같은 값으로 재토글 → no-op (return false), `change_logs` 행 추가 안 됨.
7. 코치 권한: 다른 coach의 order에 toggle_flag → HTTP 403.
8. coach 자기 order에 모든 flag 토글 가능.
9. `change_logs`에 `coupon_issued_set` / `special_case_set` 등 새 action 적재 확인.

### `tests/notify_pt_orders_query_test.php` 보강

1. `coupon_issued=1`만 ON인 회원 → 결과 제외.
2. `special_case=1`만 ON인 회원 → 결과 제외.
3. `kakao_room_joined=0 AND coupon=0 AND special=0` → 결과 포함.
4. 셋 다 0 + 다른 필터 정상 동작 (regression).

### `tests/kakao_check_test.php` 기존 케이스 유지

기존 `toggle_join` 호환성 테스트는 그대로 남기고, 새 `toggle_flag` 케이스를 추가.

## 마이그레이션 / 배포 순서

1. `pt-dev` 브랜치에서:
   - 마이그 작성 → DEV DB 적용
   - API 변경 (`toggle_flag` 추가, `toggle_join` 유지)
   - 어댑터 WHERE 절 변경
   - 어드민/코치 JS 변경
   - 테스트 작성 → 모두 통과
2. dev commit & push → ⛔ 사용자 확인
3. 사용자가 "운영 반영" 명시 시:
   - `pt-dev`에서 main 머지 → push origin main → checkout dev
   - `pt-prod`에서 git pull origin main
   - PROD DB에 마이그 적용

## 후속 작업 (out of scope)

- `_at` / `_by` UI 노출 (hover tooltip)
- 쿠폰/특이 건 일괄 처리(bulk action)
- 통계 대시보드 (월별 쿠폰 지급 / 특이 건 수)
- bypass UI 구현 (1회성 발송 차단)
