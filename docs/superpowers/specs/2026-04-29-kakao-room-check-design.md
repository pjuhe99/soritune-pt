# 카톡방 입장 체크 탭 설계

작성일: 2026-04-29
대상 사이트: pt.soritune.com
관련 사이드: 코치(`/coach/`) + 어드민(`/admin/`)

## 1. 배경 / 목적

PT 코치가 자기 회원의 "카톡방 입장 여부"를 월 단위로 체크하고, 추후 알림톡 자동화의 발송 대상 신호로 쓰기 위한 탭을 추가한다. 카톡방 입장은 일반적으로 PT 시작 시점 1회성 이벤트이므로, 한 order(주문 인스턴스)는 한 "코호트 월"에만 속한다.

### 핵심 결정사항 요약 (브레인스토밍 세션)

| # | 결정 | 선택 |
|---|------|------|
| 1 | order의 월 노출 방식 | (A) 코호트 — 한 order는 한 월에만 등장 |
| 2 | 코호트 월 결정 규칙 | (B) 자동(`MONTH(start_date)`) + admin 명시적 override |
| 3 | 체크박스 의미/저장 | order당 1개, DB 영속, 코치/admin 토글 가능 (해제도 가능) |
| 4 | override UX | (B) admin 사이드의 동일 탭 + bulk action (필터 후 다중 선택 → 일괄 이동) |
| 5 | 월 필터 UI | (A) 가로 pill 탭, 데이터 있는 월만 노출. 기본 = 오늘 기준 현재 월 (없으면 가장 가까운 미래 월, 그것도 없으면 가장 최근 과거 월) |
| 6 | 체크 완료 행 처리 | (B) 기본 숨김 + "체크 완료 N건 보기" 토글 |
| 7 | admin 화면 동작 | (A) 전체 조회 + 코치 필터 드롭다운 |

## 2. 아키텍처 개요

```
[orders 테이블]
    │ (cohort_month NULL = 자동, 그 외 = override)
    ▼
[api/kakao_check.php]
    ├─ action=cohorts        : 데이터 있는 월 목록
    ├─ action=list           : 특정 월의 order 행
    ├─ action=toggle_join    : 체크박스 토글 (코치/admin)
    └─ action=set_cohort     : admin bulk override
    │
    ▼
[코치 사이드: /coach/js/pages/kakao-check.js]
    └─ 본인 order만, 체크 토글, 월/상품 필터

[어드민 사이드: /admin/js/pages/kakao-check.js]
    └─ 전체 조회 + 코치 필터 + 다중 선택 + bulk override bar
```

## 3. 데이터 모델

### 3.1 `orders` 테이블 컬럼 추가

```sql
ALTER TABLE orders
  ADD COLUMN cohort_month CHAR(7) DEFAULT NULL
    COMMENT 'YYYY-MM, NULL이면 자동: DATE_FORMAT(start_date, "%Y-%m"). 명시값은 admin override',
  ADD COLUMN kakao_room_joined TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN kakao_room_joined_at DATETIME DEFAULT NULL,
  ADD COLUMN kakao_room_joined_by INT DEFAULT NULL
    COMMENT 'coach.id 또는 admin.id (actor 구분은 change_logs로)',
  ADD INDEX idx_cohort_month (cohort_month),
  ADD INDEX idx_kakao_room (kakao_room_joined);
```

마이그레이션 파일: `migrations/2026-04-29_kakao_room_check.sql` (idempotent ALTER 권장 — `IF NOT EXISTS` 또는 컬럼 존재 체크 후 ALTER).

### 3.2 effective_cohort 계산식 (조회 시 SQL로 derive)

```sql
COALESCE(cohort_month, DATE_FORMAT(start_date, '%Y-%m')) AS effective_cohort
```

원칙:
- `cohort_month NULL` = 자동 (start_date 기반). 95%는 NULL.
- `cohort_month = '2026-05'` = admin이 명시적으로 5월로 지정한 override. start_date가 4월 말이라도 5월 코호트.

### 3.3 cohort 등재 대상 status

`o.status IN ('진행중', '매칭완료')` (= display: 진행중 / 진행예정).
나머지(매칭대기/연기/중단/환불/종료)는 자동 제외. 컬럼은 보존되므로 status 복귀 시 자동 재등장.

## 4. API 명세

새 파일: `/public_html/api/kakao_check.php`. 인증: `requireAnyAuth()` (코치 + admin 모두 진입), 액션마다 권한 분기.

### 4.1 `GET ?action=cohorts`

쿼리: `coach_id=N` (admin만, 옵션)

응답:
```json
{ "ok": true, "data": { "cohorts": ["2026-04", "2026-05", "2026-06"] } }
```

scope:
- 코치 호출 → `WHERE o.coach_id = {본인}` (강제)
- admin 호출 → 전체. `coach_id` 파라미터 있으면 추가 필터

```sql
SELECT DISTINCT COALESCE(cohort_month, DATE_FORMAT(start_date, '%Y-%m')) AS cohort
FROM orders o
WHERE o.status IN ('진행중', '매칭완료')
  AND {scope}
ORDER BY cohort
```

### 4.2 `GET ?action=list`

쿼리: `cohort=YYYY-MM` (필수), `product=문자열` (옵션), `include_joined=0|1` (default 0), `coach_id=N` (admin만, 옵션)

응답:
```json
{
  "ok": true,
  "data": {
    "orders": [{
      "order_id": 1234,
      "member_id": 567,
      "name": "홍길동",
      "phone": "010-...",
      "email": "...",
      "product_name": "Speaking 1:1 3개월",
      "start_date": "2026-04-28",
      "end_date": "2026-07-27",
      "status": "매칭완료",
      "display_status": "진행예정",
      "cohort_month_override": "2026-05",
      "effective_cohort": "2026-05",
      "kakao_room_joined": 0,
      "kakao_room_joined_at": null,
      "coach_id": 12,
      "coach_name": "Soriya"
    }],
    "products": ["Speaking 1:1 3개월", "..."]
  }
}
```

- `display_status`는 helpers.php의 매칭완료→진행예정 매핑을 따름 (단 행 단위라 단순 CASE 사용 가능).
- `products`는 같은 cohort/coach/include_joined scope의 distinct product_name 목록 (드롭다운 옵션용). **product 필터는 products 리스트 계산에서는 무시** (드롭다운에서 다른 상품으로 전환 가능해야 하므로). 즉 별도 쿼리 1회로 계산.
- 정렬: `start_date ASC, name ASC`.

### 4.3 `POST ?action=toggle_join`

Body: `{ "order_id": 1234, "joined": true }`

- 코치: `coach_id == 본인` order만 허용 (그 외 403)
- admin: 전부 허용
- `joined=true` → `kakao_room_joined=1, kakao_room_joined_at=NOW(), kakao_room_joined_by={user_id}`
- `joined=false` → `kakao_room_joined=0, kakao_room_joined_at=NULL, kakao_room_joined_by=NULL`
- idempotent: 같은 값으로 호출해도 200 OK + no-op
- audit: `change_logs(target_type='order', target_id={order_id}, action='kakao_room_join'|'kakao_room_unjoin', actor_type=user.role, actor_id=user.id, old_value, new_value)`

### 4.4 `POST ?action=set_cohort`

Body: `{ "order_ids": [1234, 1235], "cohort_month": "2026-05" }` (또는 `"cohort_month": null`)

- admin only (코치 403)
- 검증: `cohort_month`가 null이거나 `^\d{4}-\d{2}$` 매칭
- 트랜잭션으로 일괄 UPDATE
- audit: 각 order당 `change_logs(target_type='order', action='cohort_month_set', old_value, new_value, actor_type='admin', actor_id=admin.id)`
- `cohort_month=null` 호출 = override 해제(자동 복원)

응답: `{ "ok": true, "data": { "updated": N } }`

## 5. UI

### 5.1 코치 사이드 (`/coach/`)

**사이드바 (`index.php`)**: `<nav class="sidebar-nav">`에 한 줄 추가
```html
<a href="#kakao-check" data-page="kakao-check">카톡방 입장 체크</a>
```

**새 파일**: `/coach/js/pages/kakao-check.js` (기존 `my-members.js` 패턴 재사용)

`<script>` 추가: `index.php`에 `<script src="/coach/js/pages/kakao-check.js"></script>` 등록

**레이아웃**:
```
┌─────────────────────────────────────────────────────┐
│ 카톡방 입장 체크                                       │
├─────────────────────────────────────────────────────┤
│ [2026-04] [2026-05] [2026-06]   ← 월 탭 (가로 pill) │
├─────────────────────────────────────────────────────┤
│ [진행중✓] [진행예정✓]            ← status filter pill │
│ [전체 상품 ▾]                    ← 상품 드롭다운       │
│ [□ 체크 완료 N건도 보기]          ← include_joined 토글 │
├─────────────────────────────────────────────────────┤
│ ☐ │ 이름   │ 전화번호      │ 이메일       │ 상품      │
│ ☐ │ 홍길동 │ 010-1234-... │ a@b.com     │ Speaking…│
│ ☑ │ 김영희 │ ...          │ ...         │ ...      │
├─────────────────────────────────────────────────────┤
│ N명 (체크 X) / M명 (체크 O 숨김)                       │
└─────────────────────────────────────────────────────┘
```

**동작**:
- 진입: `cohorts` 호출 → 탭 그림 → 기본 월 자동 선택 → `list` 호출
- 기본 월 규칙: 오늘(KST) 기준 현재 월 데이터 있으면 거기, 없으면 가장 가까운 미래 월, 그것도 없으면 가장 최근 과거 월
- cohorts 응답이 비어있으면 "현재 카톡방 입장 체크 대상이 없습니다" 빈 상태 (탭/필터 영역 통째로 숨김)
- 체크박스 클릭 → `toggle_join` 호출 → 성공 시 행이 fade-out되며 사라짐 (default include_joined=0)
- 상품 드롭다운: 현재 list 응답의 `products` 기반. 월 변경 시 재로딩
- 진행중/진행예정 pill: 디폴트 둘 다 ON, 토글 가능. 둘 다 OFF면 "최소 1개 선택" 가드 (자동으로 직전 활성 pill 다시 켬)
- "체크 완료 N건도 보기" 토글 → `include_joined=1`로 재호출, 체크된 행은 회색 + 체크박스 ✓ 상태로 표시

### 5.2 어드민 사이드 (`/admin/`)

**사이드바**에 동일하게 "카톡방 입장 체크" 추가.

**추가 파일**: `/admin/js/pages/kakao-check.js`. 코치 화면을 베이스로 다음만 추가:
- 상단에 **코치 필터** 드롭다운: `[전체 코치 ▾] / [Soriya] / [Daniel] / ...` (coaches API 재사용)
- 행 좌측에 **다중 선택 체크박스** (입장 체크박스와 별개)
- 다중 선택 활성 시 하단에 sticky **bulk action bar**:
  ```
  N건 선택됨 │ [목적지 월 ▾: 2026-05 …] [적용] [원래대로(자동)]
  ```
- "적용" → `set_cohort` POST `{order_ids, cohort_month: "<선택 월>"}`
- "원래대로(자동)" → `set_cohort` POST `{order_ids, cohort_month: null}` (override 해제)
- 단축 버튼(이전/다음 달)은 일부러 두지 않음 — 행마다 effective_cohort가 다를 수 있어 "선택 행 모두 ±1"이 모호한 결과를 만들 수 있음. 명시적 월 선택만 허용
- 성공 시 목록 재로딩 (옮긴 행은 다른 cohort 탭으로 빠짐)

코치 화면에는 select 체크박스도 bulk bar도 코치 필터도 없음.

## 6. Edge Cases

| # | 상황 | 동작 |
|---|------|------|
| 1 | status가 진행중→종료/중단/환불/연기로 변경 | 다음 list 호출에서 자동 제외. cohort_month/kakao_room_joined 컬럼은 유지 (히스토리 보존). status 복귀 시 자동 재등장. |
| 2 | start_date 변경 (admin 수정) | `cohort_month=NULL`이면 새 start_date 기준 자동 재분류. override 값이면 그대로 유지. |
| 3 | member merge | orders가 primary member로 따라감. cohort_month/kakao_room_joined 그대로. |
| 4 | coach 재배정 (`coach_id` 변경) | 그 시점부터 새 코치 화면에 등장, 옛 코치 화면에선 사라짐. kakao_room_joined 상태 유지 (이미 입장한 사람은 입장한 거). `kakao_room_joined_by`는 옛 코치 ID로 audit 보존. |
| 5 | 이미 체크된 order에 admin이 cohort 이동 | kakao_room_joined 유지. 새 cohort 월에 "체크 완료 N건 보기" 토글 켜야 보임. |
| 6 | cohorts 0건 | 탭/필터/표 영역 통째로 숨기고 빈 상태 메시지. |
| 7 | 상품 필터로 좁힌 후 0건 | "조건에 맞는 회원이 없습니다" (my-members 패턴 동일). |
| 8 | bulk override 일부 실패 | admin only이므로 권한 실패 거의 없음. 트랜잭션으로 묶어 모두 성공 또는 모두 실패. |

## 7. 검증 / 테스트

PT 프로젝트의 `/tests` 디렉토리 기존 패턴 따라 추가:

1. **마이그레이션 idempotency**: ALTER 두 번 실행해도 안전한지. 컬럼 존재 체크 후 ALTER, 재실행 시 no-op.
2. **API 단위 테스트** (`/tests/api/test_kakao_check.php`):
   - `cohorts`: 코치 본인 데이터만 노출, status 필터 정확
   - `list`: cohort_month override가 effective_cohort에 반영, include_joined 동작, products 리스트 정확
   - `toggle_join`: 코치는 본인 order만, admin은 전부, 다른 코치 order에 코치 호출 시 403, audit 기록 검증
   - `set_cohort`: admin only(코치 403), NULL 복원 동작, 트랜잭션
3. **수동 시나리오**:
   - 4월 28일 시작 order → admin이 5월 cohort로 override → 코치 5월 탭에 등장
   - 코치 체크 → 행 사라짐 → "체크 완료 N건 보기" 토글 → 등장 → 재토글 해제
   - bulk: 같은 상품 5건 일괄 cohort 변경

## 8. 비기능 / 보안

- 모든 list/toggle 쿼리에 코치는 `coach_id = 본인` 강제 필터 (기존 members.php 패턴 동일).
- admin 전용 액션은 `$user['role'] === 'admin'` 체크.
- `toggle_join`은 idempotent (같은 값으로 호출해도 200 OK).
- 인덱스 `idx_cohort_month`, `idx_kakao_room` 추가로 list 조회 최적화.
- audit는 `change_logs` 테이블 재사용 — 신규 테이블 추가 없음.

## 9. 알림톡 자동화 후속 (이번 스코프 외, 인터페이스 호환성만 확보)

추후 cron/배치에서 사용할 데이터 시그니처:
```sql
WHERE o.status IN ('진행중','매칭완료')
  AND o.kakao_room_joined = 0
  AND COALESCE(o.cohort_month, DATE_FORMAT(o.start_date, '%Y-%m')) = '{이번 달}'
```

이번 설계의 컬럼만으로 추가 변경 없이 자동화 연결 가능. PT 알림톡 시스템(`2026-04-29-pt-notify-design.md`)의 어댑터 한 종류로 추가하면 됨.

## 10. 변경 영향 범위

추가 파일:
- `migrations/2026-04-29_kakao_room_check.sql`
- `public_html/api/kakao_check.php`
- `public_html/coach/js/pages/kakao-check.js`
- `public_html/admin/js/pages/kakao-check.js`
- `tests/api/test_kakao_check.php`

수정 파일:
- `public_html/coach/index.php` (사이드바 + script 등록)
- `public_html/admin/index.php` (사이드바 + script 등록)
- `schema.sql` (cohort_month + kakao_room_joined 컬럼 4개 + 인덱스 2개)

기존 테이블/컬럼 변경: `orders` 테이블에 컬럼 4개, 인덱스 2개 추가만. 기존 컬럼/데이터 변형 없음.
