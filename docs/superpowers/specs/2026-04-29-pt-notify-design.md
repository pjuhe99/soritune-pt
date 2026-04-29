# PT 알림톡 발송 시스템 설계 (boot 이식)

작성일: 2026-04-29
대상 사이트: pt.soritune.com
참고 구현: boot.soritune.com (boot-dev/public_html/includes/notify/*)

## 1. 배경 / 목적

boot.soritune.com에 운영 중인 알림톡 발송 시스템을 PT에도 도입한다. PT는 boot과 다른 도메인 모델(PT 회원/코치/orders)을 가지므로 그대로 복제할 수 없고, 데이터 소스 부분만 PT 도메인에 맞춰 재작성한다.

### 첫 캠페인 형태
- Google Sheet 기반 단발성 캠페인 (boot의 시나리오 E와 동일 형태)
- **차이점:** 시트는 "발송 대상 식별"만 담당하고, 실제 휴대폰 번호는 PT MySQL `members` 테이블에서 lookup
- 시트 컬럼: `아이디`(soritune_id), `발송대상`(Y/N), 그리고 템플릿 변수용 자유 컬럼들

### 결정사항 요약 (브레인스토밍 세션)
| # | 결정 | 선택 |
|---|------|------|
| 1 | 첫 시나리오 형태 | E (Google Sheets 기반 캠페인) + PT DB phone lookup |
| 2 | 매칭 실패 처리 | C 하이브리드 — 미리보기 빨강 강조 + 운영자 override 옵션 |
| 3 | 병합된 회원(`merged_into`) | 마스터 회원의 phone 자동 추적 |
| 4 | Solapi 키/채널 | B — boot 채널/키 재사용, PT `keys/` 디렉토리에 별도 파일 |
| 5 | 시트 모델 | PT 전용 새 시트 (아이디 + 발송대상 + 변수 컬럼) |
| 6 | 알림톡 템플릿 | boot 채널 안에 새 템플릿 검수 받음 |
| 7 | 트리거 방식 | B — cron 자동 발송 + 수동 1회 발송 모두 지원 |
| 8 | 안전장치 | A+B — `dry_run_default: true` + `test_phone_override` |
| 9 | UI 위치 | A — `admin/notify.php` 단독 탭, `logs` 옆 |
| - | 어댑터 구조 | 방식 1 — 합성 어댑터 신규 작성 (`source_pt_sheet_member.php`) |

## 2. 아키텍처 개요

```
[Google Sheet (PT 캠페인용)]
        │  (운영자가 만든 새 시트, 헤더: 아이디, 발송대상Y/N, [변수컬럼들])
        ▼
[cron/GoogleSheets.php]  ← boot에서 그대로 복사
        │
        ▼
[includes/notify/source_pt_sheet_member.php]  ★ 신규 어댑터
   ├─ 시트 행 읽기 + check_col == check_value(예: "Y") 필터
   ├─ soritune_id로 PT members lookup (merged_into 추적)
   ├─ phone 채워넣기
   └─ unknown(매칭실패/phone공백) 행도 그대로 반환 (status='unknown'으로 분류)
        │
        ▼
[includes/notify/dispatcher.php]  ← boot에서 그대로 복사 (수정 없음)
   ├─ cooldown 체크 / 미리보기 생성 / 디스패치 / Solapi 호출
   └─ 4개 테이블에 기록
        │
        ▼
[notify_scenario_state / notify_batch / notify_message / notify_preview]
        │
        ▼
[admin/notify.php]  ★ 신규 UI (boot operation/index.php 참고하여 PT 어드민 톤)
   ├─ 시나리오 카드(토글, 미리보기, 발송, 수동 1회)
   ├─ 매칭 실패 행 빨강 강조 + override 체크박스
   ├─ dry_run / test_phone_override 토글
   └─ 발송 이력
        │
        ▲
[Solapi]  ← boot 채널/키 그대로, keys/solapi.json 별도 파일
```

### 코드 격리 전략
- boot의 핵심 코드(`dispatcher.php`, `solapi_client.php`, `notify_functions.php`, `scenario_registry.php`, `source_google_sheet.php`)는 **변경 없이 PT에 복사**한다. 양쪽 코드베이스 동기화 부담을 만들지 않는다.
- PT 고유 로직(시트→DB lookup)은 새 파일 `source_pt_sheet_member.php`로 격리한다.
- 시나리오 파일은 source 타입을 `'pt_sheet_member'`로 선언하여 새 어댑터를 가리킨다.

## 3. DB 스키마

### 3.1 boot에서 그대로 복사하는 4개 테이블

`migrations/20260429_add_notify_tables.sql`에 다음 4개 테이블 정의:
- `notify_scenario_state` — 시나리오별 활성 토글, 마지막 실행 상태, 1회용 우회 플래그
- `notify_batch` — 발송 배치 단위 (시간/대상수/성공/실패 카운터)
- `notify_message` — 개별 발송 메시지 (phone, 템플릿, 상태, Solapi 메시지ID, cooldown 추적)
- `notify_preview` — 미리보기 토큰(32자) → 실발송 시 토큰으로 행 키 잠금

스키마는 `boot-dev/migrate_notify_tables.php`의 SQL을 그대로 가져온다 (`IF NOT EXISTS`라 재실행 안전).

### 3.2 boot에서 가져오는 bypass 컬럼

`migrations/20260429_add_notify_bypass_columns.sql`:
- `notify_scenario_state`에 `bypass_dry_run_once`, `bypass_cooldown_once` TINYINT(1) DEFAULT 0 컬럼 추가
- 운영자가 "다음 1회만 dry_run 무시" 같은 일회성 결정을 시나리오 파일 수정 없이 할 수 있게
- 한 번 발송에 사용되면 자동으로 0으로 리셋

### 3.3 PT 신규 테이블 1개

`migrations/20260429_add_notify_member_match_log.sql`:

```sql
CREATE TABLE notify_member_match_log (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  preview_id      CHAR(32)     NULL,
  batch_id        BIGINT       NULL,
  scenario_key   VARCHAR(64)   NOT NULL,
  sheet_row_idx  INT           NOT NULL,
  soritune_id    VARCHAR(50)   NOT NULL,
  match_status   ENUM('matched','member_not_found','phone_empty','merged_followed') NOT NULL,
  resolved_member_id INT       NULL,
  resolved_phone VARCHAR(20)   NULL,
  override_applied TINYINT(1)  NOT NULL DEFAULT 0,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_preview (preview_id),
  INDEX idx_batch (batch_id),
  INDEX idx_soritune (soritune_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**왜 필요한가:** boot에는 없는 PT 고유 단계(시트→DB lookup)의 디버깅 로그. `notify_message.skip_reason` 텍스트에 끼워 넣으면 후속 분석이 어렵기 때문에 별도 테이블로 분리.

## 4. 컴포넌트 (파일 단위)

### 4.1 boot에서 그대로 복사
| 신규 경로 | 출처 | 역할 |
|-----------|------|------|
| `public_html/cron/GoogleSheets.php` | boot | Sheets API readonly 클라이언트 |
| `public_html/includes/notify/dispatcher.php` | boot 베이스 | 디스패처. boot 파일 복사 후 source 타입 match 표현식(302~307줄 부근)에 `'pt_sheet_member' => notifySourcePtSheetMember(...)` 1줄 + 상단 require_once 1줄 추가. boot의 `requireAdmin(NOTIFY_ROLES)` 호출 시그니처 차이는 dispatcher 내부에서는 발생 안 함(role 검증은 API 레이어) |
| `public_html/includes/notify/solapi_client.php` | boot | Solapi HTTP 클라이언트 |
| `public_html/includes/notify/notify_functions.php` | boot | 공통 유틸 |
| `public_html/includes/notify/scenario_registry.php` | boot | 시나리오 자동 로드 + 검증. 변경 없이 복사 |
| `public_html/includes/notify/source_google_sheet.php` | boot | (방식 1에서는 import만 됨, 직접 호출 안 됨) |
| `keys/solapi.json` | boot 키 동일 | apache:apache 0640 |
| `keys/google-sheets-service-account.json` | boot 키 동일 | apache:apache 0640 |

### 4.2 PT 신규 작성
| 경로 | 역할 |
|------|------|
| `public_html/includes/notify/source_pt_sheet_member.php` | ★ 새 어댑터. 시트 + PT DB lookup + merged_into 추적 + match log 기록 |
| `public_html/includes/notify/scenarios/.gitkeep` | 빈 디렉토리 마커 |
| `public_html/api/services/notify.php` | boot 거 베이스로 복사. PT auth 시그니처(`requireAdmin()` 인자 없음)로 교체. NOTIFY_ROLES 상수/role 인자 제거 (PT는 단일 admin role). 7개 handler 함수 본체 (실제 라우팅은 위의 `api/notify.php`가 담당) |
| `public_html/admin/index.php` | (수정) sidebar nav에 `<a data-page="notify">알림톡</a>` 1줄 + `<script src="/admin/js/pages/notify.js"></script>` 1줄 추가 |
| `public_html/admin/js/pages/notify.js` | ★ 새 SPA 페이지 모듈. PT admin SPA 패턴 (App.init() 라우팅) 준수. boot `operation/index.php`의 notify 섹션 로직을 SPA 모듈 함수로 변환 |
| `public_html/api/notify.php` | ★ 새 API 라우터. PT 단일 라우터 패턴 (`?action=`로 switch 분기), 7개 액션(listScenarios/toggle/preview/sendNow/listBatches/batchDetail/retryFailed) 처리. handler 본체는 `services/notify.php`에 둠 |
| `public_html/assets/css/notify.css` | boot `css/notify.css` 베이스 + PT 톤(이미 로드된 `/assets/css/style.css`와 충돌 안 나는 클래스 prefix `notify-` 사용). admin/index.php의 head에 link 추가 |
| `public_html/cron/notify_dispatch.php` | ★ 신규. crontab에서 호출, 활성 시나리오 일괄 디스패치 |
| `migrations/20260429_add_notify_tables.sql` | 4개 테이블 생성 (boot SQL 그대로) |
| `migrations/20260429_add_notify_bypass_columns.sql` | bypass 컬럼 추가 |
| `migrations/20260429_add_notify_member_match_log.sql` | match log 테이블 신규 |

### 4.3 시나리오 파일은 운영 시점에 추가
- 첫 시나리오 파일은 시트와 템플릿이 결정된 시점에 작성한다.
- 본 설계 범위에서는 **인프라(코드/UI/스키마)만 셋업**하고, `scenarios/` 디렉토리는 빈 상태로 머지한다.
- 첫 캠페인이 정해지면 단일 PHP 파일을 `scenarios/`에 추가하는 것만으로 운영 UI에 자동 노출된다.

### 4.4 PT 컨벤션 준수
- boot은 `css/`, `js/`를 루트에 두지만, PT는 `assets/css/`, `assets/js/` 컨벤션. PT 컨벤션을 따름.
- boot은 마이그를 PHP 파일로 작성(`migrate_*.php`)하지만, PT는 SQL 직접 실행 컨벤션(`migrations/YYYYMMDD_*.sql`). PT 컨벤션을 따름.

## 5. 데이터 흐름

### 5.1 미리보기 생성 (운영자가 "미리보기" 클릭)

```
admin/notify.php
  → POST /api/services/notify.php?action=preview&scenario=<key>
    → notify_dispatcher::preview(scenario_key)
      ├─ scenario 파일 로드 (source.type='pt_sheet_member')
      ├─ source_pt_sheet_member::fetch(cfg)
      │   ├─ Google Sheets에서 행 읽기 (check_col == check_value 필터)
      │   ├─ 각 행마다:
      │   │   ├─ soritune_id로 SELECT FROM members
      │   │   │   ├─ 매칭 실패 → match_status='member_not_found', phone=NULL
      │   │   │   ├─ merged_into IS NOT NULL → 마스터 row로 재조회 → match_status='merged_followed'
      │   │   │   └─ phone IS NULL/'' → match_status='phone_empty'
      │   │   └─ 매칭 OK → match_status='matched', phone 채움
      │   └─ match_log row 객체 배열을 결과에 포함 (preview_id는 아직 NULL)
      ├─ cooldown 체크 (notify_message에서 최근 N시간 발송 이력)
      ├─ 변수 치환 미리보기 (rendered_text 시뮬레이션)
      ├─ notify_preview INSERT (32자 토큰, expires_at = NOW() + 1일)
      ├─ notify_member_match_log INSERT (방금 INSERT된 preview_id 채워서 기록)
      └─ JSON 반환:
          {
            preview_id, matched[], unknown[], cooldown_skip[], target_count
          }
```

### 5.2 발송 (운영자가 미리보기 화면에서 "발송" 클릭)

```
admin/notify.php
  → POST /api/services/notify.php?action=dispatch
       body: {preview_id, dry_run, test_phone_override?}
    → notify_dispatcher::run(preview_id, options)
      ├─ notify_preview에서 토큰 검증 + 만료 체크 + used_at 검증 (idempotent)
      ├─ notify_batch INSERT (started_at, dry_run, trigger_type='manual')
      ├─ matched 대상만 발송 큐 (unknown은 옵션 1 정책에 따라 항상 skip)
      ├─ 각 행:
      │   ├─ test_phone_override 있으면 phone을 그 번호로 강제
      │   ├─ dry_run=1이면 Solapi 호출 없이 status='dry_run'으로 INSERT
      │   ├─ dry_run=0이면 Solapi 호출 → status='sent'/'failed'
      │   └─ notify_message INSERT
      ├─ notify_member_match_log의 batch_id 채움
      ├─ notify_batch UPDATE (sent/failed/skipped/finished_at)
      └─ JSON 반환: {batch_id, sent, failed, skipped, ...}
```

### 5.3 cron 자동 발송

```
*/5 * * * * apache  php /var/www/html/_______site_SORITUNECOM_PT/public_html/cron/notify_dispatch.php
  → notify_dispatch.php
    ├─ notify_scenario_state에서 is_active=1 시나리오 SELECT
    ├─ 각 시나리오:
    │   ├─ schedule(cron 식) 매치 안 되면 skip
    │   ├─ is_running=1 (다른 인스턴스 실행 중) → skip
    │   ├─ is_running=1 SET (잠금)
    │   ├─ preview 자동 생성 → 곧장 dispatch (manual override 없이)
    │   │   ├─ unknown 자동 skip (override 없음)
    │   │   ├─ test_phone_override는 시나리오 파일에 명시된 경우만 적용
    │   │   └─ dry_run은 시나리오 파일의 dry_run_default 값
    │   └─ is_running=0 SET (잠금 해제)
```

**핵심 포인트:**
- 미리보기 토큰(`preview_id`)은 "이 운영자가 본 그 행 집합"을 잠그는 역할 — 발송 직전에 시트가 바뀌어도 미리보기 시점의 행으로 발송된다.
- cron 자동 발송은 운영자 개입 없이 돌아가는 안전 모드: dry_run/test_override는 **시나리오 파일에 박힌 값만** 사용. 미리보기에서 운영자가 override한 값은 cron에 영향 없음.

## 6. 에러 처리 / 안전장치

### 6.1 단계별 안전장치

| 단계 | 위험 | 안전장치 |
|------|------|----------|
| 시트 읽기 | 시트 구조 변경(헤더 누락) | 어댑터에서 필수 헤더 검증, 누락 시 throw → 미리보기 단계에서 빨강 에러(발송 차단) |
| 시트 읽기 | Service Account 시트 접근 불가 | `RuntimeException` → 운영 화면에 "시트 공유 필요: <SA email>" 메시지 |
| DB lookup | 동일 soritune_id 다중 매칭 | UNIQUE 제약으로 발생 안 함 (스키마 보장) |
| DB lookup | merged_into 순환 참조 | 최대 5회 추적 후 fail-safe로 `match_status='member_not_found'` |
| 미리보기 → 발송 | 시트가 미리보기 이후 변경됨 | `preview_id` 토큰으로 미리보기 시점 행 잠금. 발송은 토큰의 행만 사용 |
| 미리보기 → 발송 | 토큰 만료 | `expires_at < NOW()` → 410 에러, 미리보기 다시 요청 |
| 미리보기 → 발송 | 한 토큰 두 번 발송 | `notify_preview.used_at` IS NOT NULL이면 거부 (idempotent) |
| 디스패치 | dry_run 토글 미스 | 시나리오 파일 `dry_run_default: true`. 운영자가 명시적으로 OFF 토글해야 실발송. 토글은 매 발송마다 리셋 |
| 디스패치 | 잘못된 시트로 cron 자동 발송 | (1) `is_active`는 운영자가 수동 ON 해야 시작 — 기본 OFF, (2) cron은 시나리오 파일의 `dry_run_default`/`test_phone_override`만 사용, (3) `cooldown_hours`로 중복 방지 |
| 디스패치 | cron + 수동 동시 실행 | `is_running=1` 잠금. 다른 인스턴스 진행 중이면 skip + UI에 "진행 중" 표시 |
| 디스패치 | 동일 phone 중복 발송 | `cooldown_hours` 내에 같은 (scenario_key, phone)에 status='sent' 이력 있으면 자동 skip |
| 디스패치 | `max_attempts` 초과 | 같은 행에 retry 누적 횟수가 max 넘으면 skip |
| Solapi 호출 | API 실패/타임아웃 | `notify_message.status='failed'`, `fail_reason`에 응답 본문 저장. 배치 진행 계속 |
| Solapi 호출 | 부분 실패 | `notify_batch.status='partial'`. 운영 UI에서 실패 행만 재시도 가능 |
| 운영자 실수 | unknown 행 override 시도 | **6.1a 항목 참조 — override 정책 명확화 필요(설계 모호성, 사용자 리뷰 항목)** |

### 6.1a Unknown override 정책 (사용자 리뷰 시 결정 필요)

**모호성:** 질문 2에서 "C. 미리보기에서 빨간색 강조 + 운영자가 무시하고 발송 가능"으로 결정했지만, unknown 분류는 phone이 없는 경우(member_not_found / phone_empty)에만 발생한다. phone이 없는데 override해서 발송한다는 게 기술적으로 불가능.

**해결 옵션 (3가지 중 사용자 선택):**

- **옵션 1: override 기능을 제거하고 안내 메시지만 제공.** unknown 행은 시트/DB를 고친 후 미리보기 재생성. UI는 빨간 강조 + "이 회원은 phone이 없어 발송 불가, members 테이블 또는 시트 아이디 수정 필요" 안내. 가장 단순.
- **옵션 2: unknown override 시 시트의 phone 컬럼 값을 fallback으로 사용.** 어댑터가 시트의 `phone_col`(있는 경우)도 함께 보관. 운영자가 override하면 시트 phone으로 발송. 사용자 의도("PT DB phone 사용")와 부분 어긋남.
- **옵션 3: unknown override 시 운영 UI에서 수동 phone 입력.** 운영자가 빨간 행 옆 input 박스에 phone 직접 입력 → DB members.phone에 UPSERT 후 발송. DB 정합성 유지.

**현 spec에서는 옵션 1을 잠정 채택하여 진행** (가장 단순하고 데이터 흐름이 명확). 사용자 리뷰 시 다른 옵션 원하시면 spec 갱신 후 재검토. 옵션 1을 채택할 경우 질문 2의 "C 하이브리드"는 **"빨간색 강조 + 안내 메시지(override 없음)"** 으로 약화된다.

### 6.2 1회용 우회(bypass) 컬럼 (boot 패턴)
- `notify_scenario_state.bypass_dry_run_once`, `bypass_cooldown_once`
- 시나리오 파일 수정 없이 일회성 결정 가능
- 한 번 발송에 사용되면 자동 0으로 리셋

### 6.3 로깅
- 모든 발송 시도 → `notify_message`
- 모든 매칭 시도(성공+실패) → `notify_member_match_log`
- 배치 단위 → `notify_batch`
- Solapi raw 응답 → `notify_message.fail_reason`(실패) / `solapi_message_id`(성공)
- 운영 UI에 "최근 배치 10개" 리스트 + 각 배치 상세

## 7. 테스트 계획

PT는 DEV/PROD 분리가 없으므로 PROD DB에서 단계적으로 검증한다.

### 단계 1. 코드/스키마 단위 검증 (Solapi/시트 접근 없이)
- **마이그 검증**: 3개 SQL 파일을 PROD DB에 적용 → 5개 테이블 + bypass 컬럼이 정확히 생기는지 SHOW TABLES / DESCRIBE
- **어댑터 단위 검증**: `source_pt_sheet_member.php`에 `--smoke` CLI 모드 추가하여 가짜 시트 데이터 array 1건을 넣고 lookup 결과 출력. 다음 6개 케이스 통과:
  1. soritune_id 매칭 + phone 있음 → `match_status='matched'`
  2. soritune_id 없음 → `match_status='member_not_found'`
  3. phone NULL → `match_status='phone_empty'`
  4. phone '' (빈 문자열) → `match_status='phone_empty'`
  5. merged_into 1단계 추적 → `match_status='merged_followed'`, 마스터 phone 사용
  6. merged_into 5단계 초과(의도적 순환) → `match_status='member_not_found'` (fail-safe)

### 단계 2. dry_run 미리보기 검증 (테스트 시트, Solapi 호출 안 됨)
- 운영자가 만든 **테스트 시트**(소수 행, 의도적 매칭 실패 행 포함)로 시나리오 파일 작성, `dry_run_default: true`
- admin/notify.php에서 미리보기 클릭 → 다음 확인:
  - matched / unknown / cooldown_skip 분류가 시트와 일치
  - unknown 행 빨강 강조 + override 체크박스 노출
  - rendered_text(템플릿 변수 치환)가 의도한 문구와 일치
- 발송 클릭(dry_run 상태) → `notify_message.status='dry_run'`만 INSERT, Solapi 호출 안 됨

### 단계 3. test_phone_override 실발송 검증 (운영자 본인 번호로만)
- 시나리오 파일에 `test_phone_override: '<운영자 번호>'` 추가
- dry_run OFF 토글 → 발송. 모든 메시지가 운영자 본인 번호로 도착하는지 카카오톡에서 직접 확인
- 변수 치환 + 폴백 + 발신자명 톤 검증
- `notify_message.status='sent'`, `solapi_message_id`가 실제 채워졌는지

### 단계 4. unknown 행 처리 검증 (옵션 1 정책)
- 테스트 시트에 의도적으로 DB에 없는 아이디 1행 + phone NULL인 매칭된 회원 1행 추가 → 미리보기에서 unknown으로 분류
- 두 행 모두 빨간 강조 + 안내 메시지 노출 확인
- 발송 클릭 → matched 행만 발송, unknown은 `notify_message`에 `status='skipped'`, `skip_reason='unknown'`으로 기록되는지
- `notify_member_match_log`에 두 행이 각각 `member_not_found`, `phone_empty`로 기록되는지

### 단계 5. cron 자동 발송 검증
- crontab 등록 (5분 간격 또는 시나리오 schedule)
- `is_active=0` 상태 → cron 호출 후 `notify_batch` 추가 없음 확인
- `is_active=1` + `dry_run_default: true` → cron이 dry_run 배치만 만드는지
- `is_active=1` + `dry_run_default: false` + `test_phone_override` → 운영자 본인에게만 발송
- 같은 시나리오 cron 도는 중 수동 발송 시도 → `is_running` 잠금으로 차단
- `cooldown_hours` 내 동일 phone 재발송 시도 → 자동 skip

### 단계 6. 운영자가 결정하는 "실 캠페인 라이브" 게이트
- 단계 1~5 모두 통과 후, 운영자가 실 캠페인 시트로 시나리오 파일 추가 + `is_active=1` 토글
- 첫 cron 발송은 운영자가 명시적으로 시간 맞춰 모니터링하면서 진행
- 첫 N건 발송 후 `is_active=0` 토글 → 결과 검토 → 다시 ON

### 통과 기준
단계 1~5의 모든 체크포인트 통과 시에만 단계 6 라이브 가능. 단계 1~4는 자동/반자동 체크 가능, 단계 5는 시계 동기화 의존이라 사람이 모니터.

## 8. 범위 / 비범위

### 본 설계 범위
- 마이그 SQL 3개
- boot 코드 7개 파일 복사 + 키 2개 복사
- 새 어댑터 1개 (`source_pt_sheet_member.php`)
- 새 admin 페이지 1개 (`admin/notify.php`)
- 새 cron entry 1개 (`cron/notify_dispatch.php`)
- 새 API 1개 (`api/services/notify.php`)
- 새 CSS/JS 2개 (PT 톤)
- crontab 등록

### 본 설계 비범위 (별도 PR/세션)
- 첫 시나리오 PHP 파일 작성 (시트와 템플릿이 결정된 시점에 별도 진행)
- Solapi 알림톡 템플릿 검수 신청 (외부 절차, 본 설계 영역 아님)
- PT 전용 Solapi 채널 분리 (memory 보안 권고는 별도 일정. 본 설계는 B 결정에 따라 boot 채널 공유)
- 두 번째 시나리오 추가 시의 시나리오 빌더 UI (YAGNI, 첫 캠페인 패턴이 잡힌 후 결정)

## 9. 알려진 위험 / 사후 검토 항목

- **PT는 DEV/PROD 분리 없음** → 단계 1~5 검증을 우회하지 않도록 운영자 자체 규율 필요
- **Solapi 키 공유**(B 결정) → boot/PT 어느 한쪽에서 키 노출 사고 발생 시 양쪽 영향. 향후 분리 검토 항목
- **시나리오 파일은 코드 PR로 추가** → 운영자가 직접 시트/템플릿 변경 시 개발자 개입 필요. 두 번째 시나리오 즈음 운영 UI에서 직접 편집할지 판단
