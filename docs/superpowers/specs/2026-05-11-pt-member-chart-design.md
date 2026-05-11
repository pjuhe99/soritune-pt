# PT 회원 차트 시스템 (시트 → 웹) 설계 spec

작성일: 2026-05-11
범위: pt.soritune.com (`pt-dev` / `pt-prod`)
유래: 뉴소리튜닝 영어 코칭 회원 차트를 구글 스프레드시트에서 PT 자체 웹서비스로 전환

## 1. 개요

뉴소리튜닝 코치들이 구글 시트로 운영 중인 "회원 차트"(기본 인적사항 + 심층 성향 분석 + 매칭별 일별 코칭 로그 + 진도·개선율 자동 계산)를 PT 시스템 안으로 흡수한다. 시트의 수식 결과와 100% 일치하는 자동 계산, 코치 권한 분리, 데이터 마이그레이션(시트 → DB), 변경 감사가 핵심 요구사항이다.

### 1.1 스택·통합 전략

PT 기존 스택을 그대로 따른다.

- PHP 8 + MariaDB
- vanilla JS + 기존 PT UI 패턴 (`/admin/`, `/coach/` SPA 스타일 탭)
- 권한 가드: `auth.php`, `coach_team_guard.php`, `matching_engine.php` 재사용
- 감사: 기존 `change_logs` 테이블 재사용 (target_type ENUM 확장)
- 마이그레이션 UI: PT 기존 `notify_preview` / `coach_assignment_drafts` 패턴 재사용

원본 요청서의 PostgreSQL / Python / React 권고는 **거부** — PT 운영 인프라(php-fpm, MariaDB, vanilla JS 패턴)와 정합성을 우선한다.

### 1.2 이번 spec In-scope

1. **매칭 캘린더** — 월별 × 상품별 single source. 관리자만 입력. 자동 패턴 후보(평일 5회 등) + 관리자 수정.
2. **일별 코칭 로그** — 한 회차 = 한 row. `order_sessions` 컬럼 확장. 코치가 회원 차트 페이지 modal로 작성, bulk edit 지원.
3. **자동 계산 지표** — 피드백 진도율, 개선율. 질의 시 계산(캐싱 X). 시트 수식과 100% 일치.
4. **회원 차트 페이지** — `/admin/`, `/coach/` 양쪽 신규. 기본정보 + 기존 DISC/sensory/voice_intake 카드 재사용 + 캘린더 + 일별 로그 테이블 + 메트릭 헤더.
5. **시트 → DB 마이그레이션** — 관리자가 CSV 업로드(기간 본인 컷). soritune_id 매칭. dry-run preview → 본 import.
6. **권한·감사** — 코치는 자기 담당 회원만. 일별 로그 수정은 그 회원 담당 코치 누구든 + 관리자. 모든 CUD가 `change_logs`에 기록.

### 1.3 Out-of-scope (다음 업데이트 명시)

- **A1/A2 단계별 승급 D-Day·달성 추적** — 별도 spec, `member_stage_targets` 테이블 + 자동 계산
- **`/me/` 회원 본인 노출** — 운영하다 필요성 느낄 때 공개
- **알림톡 트리거** (D-3, 진도율 부진) — `notify_*` 인프라 활용해 별도 spec

### 1.4 기존 자산 재사용 (신규 모듈 X)

- DISC / 감각 선호도(sensory) / voice_intake 사전설문 → 기존 `test_results` + `/me/` 로직 그대로 카드로 표시 (회원 차트 페이지에서 조회만)
- 회원 마스터 / 주문 / 메모 → 기존 `members`, `orders`, `member_notes` 그대로
- 코치 권한 가드 → 기존 매칭 엔진의 `get_coach_assigned_members()` 헬퍼 재사용
- 변경 감사 → 기존 `change_logs` 의 target_type ENUM 확장

## 2. 데이터 모델

### 2.1 신규 테이블 2개

```sql
-- 매칭 캘린더 (월별 × 상품별, single source)
CREATE TABLE coaching_calendars (
  id            INT PRIMARY KEY AUTO_INCREMENT,
  cohort_month  CHAR(7)      NOT NULL,            -- '2026-05' 형식
  product_name  VARCHAR(200) NOT NULL,            -- orders.product_name 과 매칭
  session_count INT          NOT NULL,            -- = 캘린더 일자 수 = 상품 회차 수
  notes         VARCHAR(500),
  created_by    INT NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT current_timestamp(),
  updated_at    DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY uk_cohort_product (cohort_month, product_name),
  KEY idx_cohort (cohort_month)
) ENGINE=InnoDB;

-- 캘린더 일자 (1:N, N = session_count)
CREATE TABLE coaching_calendar_dates (
  id              INT PRIMARY KEY AUTO_INCREMENT,
  calendar_id     INT NOT NULL,
  session_number  INT NOT NULL,                   -- 1..N
  scheduled_date  DATE NOT NULL,
  UNIQUE KEY uk_calendar_session (calendar_id, session_number),
  KEY idx_date (scheduled_date),
  FOREIGN KEY (calendar_id) REFERENCES coaching_calendars(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 2.2 기존 `order_sessions` 확장

```sql
ALTER TABLE order_sessions
  ADD COLUMN calendar_id  INT       NULL AFTER order_id,    -- 매칭 캘린더 link
  ADD COLUMN progress     TEXT      NULL AFTER memo,        -- 진도
  ADD COLUMN issue        TEXT      NULL AFTER progress,    -- 문제점
  ADD COLUMN solution     TEXT      NULL AFTER issue,       -- 솔루션
  ADD COLUMN improved     TINYINT(1) NOT NULL DEFAULT 0 AFTER solution,   -- 개선 체크
  ADD COLUMN improved_at  DATETIME  NULL AFTER improved,
  ADD COLUMN updated_by   INT       NULL AFTER improved_at,
  ADD COLUMN updated_at   DATETIME  NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  ADD KEY idx_order_completed (order_id, completed_at),
  ADD KEY idx_order_improved  (order_id, improved),
  ADD CONSTRAINT fk_session_calendar FOREIGN KEY (calendar_id) REFERENCES coaching_calendars(id) ON DELETE SET NULL;
```

- `scheduled_date`는 별도 컬럼으로 저장하지 않는다 — `calendar_id + session_number` 로 `coaching_calendar_dates` lookup (single source 보장)
- 기존 `memo` (varchar 255) 그대로 유지 — 기존 데이터 보존, 마이그레이션 완료 후 deprecate 여부 결정

### 2.3 `change_logs.target_type` ENUM 확장

PT 현재 ENUM 값 (2026-05-11 시점):
`'member','order','coach_assignment','merge','retention_allocation','meeting_note','training_attendance'`

신규로 3개 추가:

```sql
ALTER TABLE change_logs
  MODIFY COLUMN target_type ENUM(
    'member','order','coach_assignment','merge','retention_allocation',
    'meeting_note','training_attendance',
    'coaching_calendar',
    'coaching_calendar_date',
    'order_session'
  ) NOT NULL;
```

### 2.4 마이그레이션 staging 테이블 (신규)

```sql
-- CSV 업로드 → dry-run preview 행 단위 staging
CREATE TABLE coaching_log_migration_preview (
  id              INT PRIMARY KEY AUTO_INCREMENT,
  batch_id        VARCHAR(50) NOT NULL,
  source_row      INT NOT NULL,                  -- CSV row number (1-based)
  soritune_id     VARCHAR(50),
  cohort_month    CHAR(7),
  product_name    VARCHAR(200),
  session_number  INT,
  scheduled_date  DATE,
  completed_at    DATETIME,
  progress        TEXT,
  issue           TEXT,
  solution        TEXT,
  improved        TINYINT(1) DEFAULT 0,
  sheet_progress_rate     DECIMAL(5,2) NULL,
  sheet_improvement_rate  DECIMAL(5,2) NULL,
  match_status    ENUM('matched','member_not_found','order_not_found',
                       'duplicate','date_invalid','calendar_missing','imported') NOT NULL,
  target_order_id INT NULL,
  error_detail    VARCHAR(500),
  created_at      DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY idx_batch (batch_id),
  KEY idx_batch_status (batch_id, match_status)
) ENGINE=InnoDB;
```

본 import 결과는 기존 `migration_logs` (batch_id, source_type='coaching_log', source_row, target_table='order_sessions', target_id, status, message) 에도 한 행씩 기록한다.

### 2.5 인덱스 전략

- `coaching_calendars (cohort_month, product_name)` UNIQUE — 매칭 lookup
- `order_sessions (order_id, completed_at)` — 진도율 계산
- `order_sessions (order_id, improved)` — 개선율 계산
- `coaching_calendar_dates (calendar_id, session_number)` UNIQUE — 일자 lookup

캐싱은 도입하지 않는다. PT 데이터 규모(회원 ~만, 주문 ~만, 세션 ~수십만)에서 인덱스만으로 충분하다. 추후 PROD에서 EXPLAIN으로 검증.

### 2.6 데이터 변환 규칙 (마이그 단계용)

- 시트 체크박스 TRUE/FALSE → `improved TINYINT(1)` 0/1
- 시트 ✓/공란 / 1/0 / TRUE/FALSE / Y/N 모두 흡수
- 시트 빈 칸 (텍스트/날짜) → 컬럼 NULL (빈 문자열 대신)
- 날짜는 ISO `YYYY-MM-DD` 권장 + `DateTime::createFromFormat` 으로 fallback 시도 (예: `2026/05/11`, `26.5.11` 등)

## 3. 자동 계산 (시트 수식 ↔ DB)

### 3.1 피드백 진도율

```sql
SELECT
  COUNT(CASE WHEN os.completed_at IS NOT NULL THEN 1 END) AS done,
  cc.session_count AS total
FROM order_sessions os
JOIN orders o            ON o.id = os.order_id
LEFT JOIN coaching_calendars cc ON cc.id = os.calendar_id
WHERE os.order_id = :order_id;
-- progress_rate = done / total (calendar 없거나 total=0 이면 0)
```

### 3.2 개선율

```sql
SELECT
  COUNT(CASE WHEN improved = 1 THEN 1 END) AS improved_n,
  COUNT(CASE WHEN solution IS NOT NULL AND solution <> '' THEN 1 END) AS solution_n
FROM order_sessions
WHERE order_id = :order_id;
-- improvement_rate = improved_n / solution_n  (solution_n=0 이면 0% 표시)
```

### 3.3 PHP 헬퍼

`public_html/includes/coaching_metrics.php` 한 파일에 함수 모음:

- `metrics_for_order($order_id) : array`
  → `['done'=>n,'total'=>N,'progress_rate'=>0.xx,'improved'=>n,'solution_total'=>n,'improvement_rate'=>0.xx]`
- `metrics_for_member($member_id) : array`
  → 회원의 모든 주문 합산
- `metrics_for_cohort($cohort_month) : array`
  → 매칭 단위 통계 (관리자 대시보드용)

단위 테스트(`tests/CoachingMetricsTest.php`)에 시트 대표 케이스 5~10건을 fixture로 포함해 시트 수식과 결과가 일치함을 회귀 검증.

## 4. 권한 매트릭스

| 동작 | 관리자 | 코치(자기 담당) | 회원 본인 |
|---|---|---|---|
| 매칭 캘린더 생성/수정/삭제 | ✅ | ❌ | ❌ |
| 매칭 캘린더 조회 | ✅ 모두 | ✅ 자기 회원의 매칭만 | ❌ |
| 일별 로그 생성 | ✅ | ✅ | ❌ |
| 일별 로그 수정/삭제 | ✅ | ✅ (그 회원 담당 코치 누구든) | ❌ |
| 일별 로그 조회 | ✅ | ✅ 자기 회원 | ❌ |
| 진도율/개선율 조회 | ✅ | ✅ 자기 회원 | ❌ |
| 회원 차트 페이지 진입 | ✅ | ✅ 자기 회원만 | ❌ |

- 코치 권한 체크 헬퍼: `coach_can_access_member($coach_id, $member_id)` — `matching_engine.php` 에서 회원-코치 매칭을 lookup
- 자기 담당이 아닌 경우 응답: **404** (존재 여부 자체 노출 방지)

## 5. UI 흐름

### 5.1 관리자: 매칭 캘린더 입력 — `/admin/?tab=coaching_calendar`

1. **신규 캘린더 생성** 모달 → `cohort_month` 선택, `product_name` 선택(현 orders.product_name distinct), `session_count` 입력
2. **자동 패턴 후보 생성** 버튼 → 시작일 + 요일패턴(평일 5회 / 주 3회 / 주 2회 등) 입력
3. 시스템이 후보 N개 날짜를 캘린더 위젯에 ● 마크로 표시
4. 관리자가 공휴일/사정 있는 날을 클릭으로 빼거나 다른 날로 옮김
5. **확정** → `coaching_calendars` + `coaching_calendar_dates` row 생성, `change_logs` 기록

캘린더 수정 시: `coaching_calendar_dates` 변경분이 곧바로 해당 매칭에 속한 모든 `order_sessions`의 `scheduled_date` 조회 결과에 반영됨 (single source).

### 5.2 코치 / 관리자: 회원 차트 페이지

URL: `/coach/?member_id=N` 또는 `/admin/?member_id=N`

페이지 구조 (위 → 아래 sections):

1. **헤더** — 회원 기본정보 (이름, 소리튠ID, 전화/이메일 마스킹, 코치, 매칭월/상품, 진행상태) + **메트릭 카드 3개**:
   - 피드백 진도율 % (`done / total`)
   - 개선율 % (`improved / solution_total`)
   - 남은 회차 (`total - done`)
2. **성향 분석** — DISC / 감각 선호도 / voice_intake (기존 카드 컴포넌트 재사용). 결과 없으면 회색 "미응시" 카드.
3. **매칭 캘린더** — 캘린더 위젯에 예정일 ● / 완료 ✓ / 미진행 ○ 마크
4. **일별 코칭 로그 테이블** — 한 row = 한 회차
   - 컬럼: 회차번호 / 예정일 / 완료일 / 진도 / 문제 / 솔루션 / 개선체크
   - 회차 row 클릭 → modal 인라인 편집 (progress + issue + solution + improved 한 곳에서)
   - 모달 안에 "완료 처리" 토글 (completed_at = NOW())
   - 상단 체크박스 다중 선택 → **bulk edit 바** (선택 일괄 완료처리 / 일정 변경 / 삭제)
5. **변경 이력** — `change_logs` 그 회원 관련 entry list (read-only)

### 5.3 코치 메인 — `/coach/`

- 자기 담당 회원 list: 이름 / 매칭월 / 상품 / 진도율 / 개선율 / 마지막 회차일
- 검색·필터: cohort_month, status, 진도율 범위
- row 클릭 → 회원 차트 페이지

### 5.4 관리자 메인 — `/admin/?tab=member_chart`

- 모든 회원 list (위와 동일 + 코치 컬럼 추가) + 검색·필터
- 행정 작업용 export(CSV) 옵션 (PII 마스킹 토글)

## 6. 데이터 마이그레이션 (시트 → PT DB)

전제: 관리자가 시트에서 기간을 직접 잘라 CSV로 추출 후 업로드한다. 회원 매칭 키 = `soritune_id`.

### 6.1 입력 CSV 포맷

관리자에게 가이드 제공할 컬럼:

```
soritune_id, cohort_month, product_name, session_number,
scheduled_date, completed_at, progress, issue, solution, improved
```

선택 컬럼(있으면 검증에 사용):

```
sheet_progress_rate, sheet_improvement_rate
```

정규화 규칙:

- `improved` = TRUE/FALSE / 1/0 / ✓/공란 / Y/N 모두 흡수 → 0/1
- 빈 칸 = NULL
- 날짜 = ISO 권장 + 다중 포맷 fallback

### 6.2 처리 흐름 (`notify_preview` / `coach_assignment_drafts` 패턴 재사용)

1. **업로드** — `/admin/?tab=migration` 에서 CSV 업로드
2. **Preview (dry-run)** — `coaching_log_migration_preview` (§2.4) 에 row별 매칭 결과 저장:
   - `match_status` ENUM('matched', 'member_not_found', 'order_not_found', 'duplicate', 'date_invalid', 'calendar_missing', 'imported')
   - `target_order_id` (matched 시), `error_detail`
3. **관리자가 preview 화면에서 매칭 실패 list 검토 / 해결**:
   - `member_not_found` → soritune_id 오타 또는 PT에 미등록 → 회원 등록 후 재시도
   - `order_not_found` → cohort_month + product_name 매칭 실패 → 주문 정정 후 재시도
   - `calendar_missing` → 해당 매칭 캘린더 미생성 → 캘린더 만든 후 재시도
4. **본 import** — preview status='matched' 한 row만 `order_sessions` UPSERT
   - 같은 `(order_id, session_number)` 이미 있으면 UPDATE (idempotent — 재실행 안전)
   - **500 row chunk 단위 트랜잭션** (chunk 실패 시 그 chunk만 rollback + 다음 chunk 진행, batch 전체는 계속). 한 batch가 매우 클 때 lock 시간 / 메모리 부담 최소화
   - import 성공한 preview row는 `match_status='imported'` 로 update
5. **마이그 결과 기록** — `migration_logs` 에 row 별로 (batch_id, source_type='coaching_log', source_row, target_table='order_sessions', target_id, status('success'/'skipped'/'error'), message) 기록

### 6.3 시트 수식 vs DB 결과 일치 검증

- CSV에 `sheet_progress_rate`, `sheet_improvement_rate` optional 컬럼 포함 권장
- 본 import 후 동일 회원에 대해 PHP 헬퍼 계산값과 시트값 비교, 불일치 row는 alert 표시 + `migration_logs.discrepancy_count` 누적
- 단위 테스트 fixture로 5~10건 회귀 검증

## 7. 보안

### 7.1 개인정보·심리 데이터 보호

- 회원 차트 페이지 = `auth.php` 로그인 + role 체크 우선
- 코치 접근은 `coach_can_access_member($coach_id, $member_id)` 가드 통과 시에만 — URL `member_id` 직접 조작(IDOR) 방어
- AJAX 엔드포인트(`/api/coaching_log.php` 등)도 동일 가드 + CSRF 토큰
- 자기 담당 아닌 경우 응답 = **404** (존재 여부 자체 노출 방지)

### 7.2 감사 (change_logs)

- `order_sessions` / `coaching_calendars` / `coaching_calendar_dates` CUD 모두 `change_logs` 기록 (actor_type, actor_id, target_type, target_id, before_json, after_json, created_at)
- bulk edit 도 row 별로 N건 기록

### 7.3 CSV 업로드 안전

- 확장자 + MIME + 매직바이트 모두 검증
- 사이즈 limit 5MB
- 업로드 디렉토리 SELinux 컨텍스트 = `httpd_sys_rw_content_t`
- 웹 직접 접근 차단 (`.htaccess` 또는 public 밖에 배치)
- 파일명 sanitize (UUID 재명명)

### 7.4 민감 필드 마스킹

- 코치 페이지의 전화·이메일은 default 마스킹, hover/클릭 시 노출 (기존 PT 패턴 일치)
- 관리자 CSV export 는 마스킹 토글 (default ON)

## 8. 검증 체크리스트 (구현 후)

- [ ] 시트 fixture 5~10건과 PHP 헬퍼 계산값 100% 일치 (단위 테스트)
- [ ] 매칭 캘린더 — N이 다른 상품 2개 동시 운영 OK
- [ ] 코치 권한 — 담당 아닌 회원 직접 URL 접근 시 404
- [ ] bulk edit — 100건 한 번에 처리 OK, `change_logs` 100건 모두 기록
- [ ] CSV 마이그 — preview/실 import 트랜잭션 분리, 중복 import idempotent (재실행 시 UPDATE)
- [ ] 인덱스 — EXPLAIN으로 회원 차트 페이지 주요 쿼리(메트릭, 일별 로그 list, 캘린더 lookup) 인덱스 활용 확인
- [ ] `change_logs.target_type` ENUM 확장 마이그가 기존 row와 충돌 없음

## 9. 향후 확장 (다음 spec)

- **A1/A2 단계별 승급 시스템** — `member_stage_targets(member_id, stage, target_date, achieved_at)` + D-Day/초과일수 자동 계산 + `/coach/` 알림
- **`/me/` 회원 본인 노출** — 진도율 카드 + 캘린더만 노출(문제/솔루션 디테일은 숨김) 또는 전체 노출
- **알림톡 트리거** — 진도율 부진(예: 3회 연속 미진행), 예정일 D-3 리마인드 등 `notify_*` 인프라 활용
