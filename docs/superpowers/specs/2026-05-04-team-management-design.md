# PT 팀원 관리 (면담 차트 + 코치 교육 출석) 설계

- 작성일: 2026-05-04
- 사이트: pt.soritune.com (DEV: dev-pt.soritune.com)
- 작업 디렉토리: `/root/pt-dev` (브랜치 `dev`)

## 1. 배경

PT 코치 팀(2026-05-01 PROD 적용 — Kel/Nana/Flora 팀, 팀당 10명) 위에 팀장이 자기 팀원을 관리하는 기능 1차로 두 가지를 추가한다.

1. **면담 차트** — 팀장이 팀원과 면담 시 특이사항을 날짜별로 기록. 작성/수정/삭제는 작성한 팀장 본인만, 조회는 작성 팀장 + 어드민(운영팀). 팀원 본인은 못 봄.
2. **코치 교육 출석** — 매주 목요일 코치 교육 출석체크. 팀장이 자기 팀원의 출석을 토글하고 직전 4주 출석율을 본다. 팀장만 접근(어드민 화면 1차에는 없음).

추후 같은 페이지에 "팀원의 PT 진행 상황" 탭을 추가할 예정이라, 이번 1차 설계는 그 자리(상세 페이지의 탭)도 자연스럽게 끼워 넣을 수 있는 구조로 잡는다.

## 2. 범위와 비범위

**범위**
- 코치 사이드 신규 SPA 메뉴 "팀원 관리" 1개 (팀장 전용)
- 면담 기록 테이블 + CRUD API + UI
- 코치 교육 출석 테이블 + 토글/조회 API + UI
- 어드민 코치 페이지에 면담 read-only 모달 진입점
- 자동 테스트 + DEV 매뉴얼 검증

**비범위 (이번 plan 외)**
- 팀원의 PT 진행 상황 탭 (추후 별도 plan)
- 어드민 사이드 면담 가로 검색(전 코치 면담 통합 목록)
- 어드민 사이드 출석 화면
- 코치 교육 휴강 처리 (`coach_training_holidays` 등) — 운영 필요해질 때 추가
- 면담 본문 백업/이력 (`coach_meeting_notes_history`) — 운영 필요해질 때 추가
- 팀 이동 시 과거 면담 가시성 정책 — 현 정책(작성자만 read)이 그대로 적용됨, 운영 결정 필요 시점에 별도 처리

## 3. 데이터 모델

### 3.1 신규 테이블 `coach_meeting_notes` (면담 기록)

```sql
CREATE TABLE coach_meeting_notes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  coach_id      INT NOT NULL,                  -- 면담 대상 (팀원)
  meeting_date  DATE NOT NULL,                 -- 팀장이 선택한 면담 일자
  notes         TEXT NOT NULL,
  created_by    INT NOT NULL,                  -- 작성한 팀장 coach.id
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_coach_date (coach_id, meeting_date DESC, id DESC),
  INDEX idx_created_by (created_by),
  CONSTRAINT fk_cmn_coach   FOREIGN KEY (coach_id)   REFERENCES coaches(id) ON DELETE CASCADE,
  CONSTRAINT fk_cmn_creator FOREIGN KEY (created_by) REFERENCES coaches(id)
);
```

- `coach_id` ON DELETE CASCADE — 면담 대상 코치 row가 실제 삭제되면 메모도 정리. PT는 보통 `status='inactive'`로 운영하므로 발동 거의 없음.
- `created_by`는 NO ACTION (기본) — 작성 팀장이 inactive/삭제되어도 면담 row는 유지. 어드민 조회를 위해.
- 본문 길이: 컬럼은 `TEXT`(64KB), API에서 1~50,000자 가드(빈 문자열 차단 + 페이스트 사고 방지).
- 정렬 키: `(coach_id, meeting_date DESC, id DESC)` — 같은 날짜 여러 건도 안정 정렬.

### 3.2 신규 테이블 `coach_training_attendance` (코치 교육 출석)

```sql
CREATE TABLE coach_training_attendance (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  coach_id       INT NOT NULL,
  training_date  DATE NOT NULL,                -- 가상 회차의 일자 (보통 목요일)
  marked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  marked_by      INT NOT NULL,                 -- 체크한 팀장 coach.id
  UNIQUE KEY uk_coach_date (coach_id, training_date),
  INDEX idx_training_date (training_date),
  CONSTRAINT fk_cta_coach  FOREIGN KEY (coach_id)  REFERENCES coaches(id) ON DELETE CASCADE,
  CONSTRAINT fk_cta_marker FOREIGN KEY (marked_by) REFERENCES coaches(id)
);
```

- **row 존재 = 출석, 없음 = 결석**. `attended` 컬럼을 일부러 두지 않음 — `attended=0` 행과 `행 없음`이 의미 중복되어 혼란 방지.
- 토글: 체크 시 INSERT, 언체크 시 DELETE. 멱등.
- `UNIQUE (coach_id, training_date)`로 중복 INSERT 차단.

### 3.3 가상 회차 — 코드 상수 + 산출 함수

회차 테이블을 두지 않는다. 교육 일자는 코드 상수로 정의하고 함수로 산출한다.

`includes/coach_training.php`:

```php
const COACH_TRAINING_DOW = 4;          // ISO-8601 요일 (1=월 ... 7=일). 4=목요일.
const COACH_TRAINING_RECENT_COUNT = 4; // 출석율 산출 분모

/**
 * KST 기준 직전 N개 교육 일자(목요일).
 * 오늘이 교육 요일이면 오늘을 첫 번째로 포함.
 *
 * @param DateTimeImmutable $nowKst KST 시각
 * @param int $n 개수
 * @param int $dow ISO-8601 요일 (1~7)
 * @return string[] YYYY-MM-DD ×N (DESC, 최신이 첫 원소)
 */
function recentTrainingDates(DateTimeImmutable $nowKst, int $n = COACH_TRAINING_RECENT_COUNT, int $dow = COACH_TRAINING_DOW): array;
```

요일이 바뀌면 상수 1줄만 변경. 휴강은 처음에 모델링 안 함.

**분모 정책**: 출석율 분모는 항상 `COACH_TRAINING_RECENT_COUNT`(=4) 고정. 코치 입사일이 직전 4주보다 최근이라도 분모는 4로 둔다 — 1차 단순화. 운영상 의미 있는 noise가 발생하면 추후 별도 plan으로 입사일 가중치 추가.

### 3.4 마이그레이션 파일

- `migrations/20260504_add_coach_meeting_notes.sql`
- `migrations/20260504_add_coach_training_attendance.sql`

두 파일로 분리해 영역별 독립 롤백 가능. `schema.sql`에 두 CREATE TABLE 추가.

## 4. API

### 4.1 파일 구성 (PT 관행: 도메인별 1파일, LIB_ONLY 가드)

| 파일 | 책임 |
|---|---|
| `api/coach_self.php` (기존) | `team_overview` 액션 추가 — 우리 팀원 명단 + 각자 직전 4주 출석율 |
| `api/coach_meeting_notes.php` (신규) | 면담 CRUD (코치-팀장 / 어드민 read) |
| `api/coach_training_attendance.php` (신규) | 출석 history + toggle (코치-팀장 only) |
| `includes/coach_training.php` (신규) | 상수 + `recentTrainingDates()` 순수 함수 |
| `includes/coach_team_guard.php` (신규) | `assertIsLeader`, `assertCoachIsMyMember` 공용 가드 (시그니처는 §4.6) |

LIB_ONLY 가드: 두 신규 API 파일 모두 `if (PHP_SAPI === 'cli' || defined('XXX_LIB_ONLY')) return;` 라우터 분리. 단위 테스트가 함수만 require해서 사용.

### 4.2 액션 명세

#### `coach_self.php?action=team_overview` (코치-팀장 전용)

응답:
```json
{
  "ok": true,
  "data": {
    "recent_dates": ["2026-05-01","2026-04-24","2026-04-17","2026-04-10"],   // DESC, 최신 첫 원소
    "members": [
      {
        "coach_id": 12,
        "coach_name": "Kel",
        "korean_name": "켈",
        "is_self": true,
        "attendance": [
          {"date":"2026-04-30","attended":1},
          {"date":"2026-04-23","attended":1},
          {"date":"2026-04-16","attended":0},
          {"date":"2026-04-09","attended":1}
        ],
        "attended_count": 3,
        "total_count": 4,
        "attendance_rate": 0.75,
        "meeting_notes_count": 0
      },
      ...
    ]
  }
}
```

- 본인 첫 행, 나머지 coach_name ASC.
- 비-팀장 호출 시 `403`.
- `meeting_notes_count`는 해당 코치(팀원) 대상으로 작성된 전체 면담 row 수(필터 없음).
- `attendance`: `recent_dates` 순서(DESC)와 동일한 4-entry 배열. `attended=1` = 출석 row 존재, `attended=0` = 결석(row 없음).

#### `coach_meeting_notes.php`

| 액션 | 메서드 | 누가 | 가드 |
|---|---|---|---|
| `list` (`?coach_id=X`) | GET | 코치-팀장(본인 팀원) / 어드민(전 코치) | 팀장: `assertCoachIsMyMember` / 어드민: 통과 |
| `create` | POST | 코치-팀장만 | 본인 팀원 + meeting_date 검증 + notes 1~50,000자 |
| `update` (`?id=X`) | POST | 작성자 본인만 | `WHERE id=? AND created_by=?` 한 문장 |
| `delete` (`?id=X`) | POST | 작성자 본인만 | 동일 |

응답 (list):
```json
{
  "ok": true,
  "data": {
    "notes": [
      {
        "id": 7,
        "meeting_date": "2026-05-01",
        "notes": "발성 톤이 좋아짐...",
        "created_by": 12,
        "created_by_name": "Kel",
        "created_at": "2026-05-01 14:22:01",
        "updated_at": "2026-05-01 14:22:01",
        "can_edit": true
      }
    ]
  }
}
```

- 어드민 조회 시 `can_edit=false` 강제(역할로 결정).
- `update`/`delete` 권한 누락 시 0 rows affected → `403` 또는 `404` (`message`로 구분 안내).

#### `coach_training_attendance.php`

| 액션 | 메서드 | 누가 | 가드 |
|---|---|---|---|
| `history` (`?coach_id=X`) | GET | 코치-팀장(본인 팀원) | `assertCoachIsMyMember` |
| `toggle` (body `{coach_id, training_date, attended:0/1}`) | POST | 코치-팀장(본인 팀원) | 위 + `training_date` 요일=COACH_TRAINING_DOW |

`history` 응답:
```json
{
  "ok": true,
  "data": {
    "recent": [
      {"date":"2026-05-01","attended":1,"marked_at":"2026-05-01 21:00:00","marked_by_name":"Kel"},
      ...4 entries
    ],
    "earlier": [
      ...8 entries (직전 5~12번째 회차)
    ],
    "attended_count": 3,
    "total_count": 4,
    "attendance_rate": 0.75
  }
}
```

`toggle` 멱등성:
- row 없는데 `attended=1` → INSERT
- row 있는데 `attended=1` → no-op
- row 있는데 `attended=0` → DELETE
- row 없는데 `attended=0` → no-op

### 4.3 권한 행렬

|  | 코치-팀장(본인 팀원) | 코치-팀장(남의 팀원) | 일반 코치 | 어드민 |
|---|---|---|---|---|
| 면담 read | ✓ | ✗ | ✗ | ✓ |
| 면담 create | ✓ | ✗ | ✗ | ✗ |
| 면담 update/delete | ✓ (자기가 쓴 것만) | ✗ | ✗ | ✗ |
| 출석 read/toggle | ✓ | ✗ | ✗ | ✗ |
| `team_overview` | ✓ (본인 팀만) | (해당 없음) | ✗ | ✗ |

### 4.4 검증 규칙

- `meeting_date`: `^\d{4}-\d{2}-\d{2}$` + `checkdate()`. 미래 일자 허용(사전 메모 시나리오).
- `training_date`: 동일 정규식 + `(int)$dt->format('N') === COACH_TRAINING_DOW` (요일 어긋난 일자 차단). 미래/과거 범위는 검증하지 않음(백필 허용).
- `notes`: `trim` 후 1~50,000자, 빈 문자열 차단.
- 모든 쓰기 액션은 `logChange()` 호출. `entity_type`은 `meeting_note` / `training_attendance`. payload는 메타만(`meeting_date`, `training_date` 등) — **본문 전체는 audit에 저장하지 않음**(개인정보 + 로그 비대화 방지).

### 4.5 공용 가드 (`includes/coach_team_guard.php`)

```php
/**
 * $coachId가 팀장(team_leader_id == 자기 id)인지 검증.
 * 팀장이 아니면 jsonError('팀장 권한이 필요합니다', 403) 후 exit.
 */
function assertIsLeader(PDO $db, int $coachId): void;

/**
 * $targetCoachId가 $leaderId 팀장의 본인 팀 멤버(같은 team_leader_id)인지 검증.
 * 팀장 자신도 자기 팀 멤버로 통과(본인 면담은 없지만 출석은 가능).
 * 멤버 아니면 jsonError('해당 코치에 대한 권한이 없습니다', 403) 후 exit.
 */
function assertCoachIsMyMember(PDO $db, int $leaderId, int $targetCoachId): void;
```

두 가드 모두 검증 실패 시 즉시 `exit` (PT 관행). 호출 직후 코드는 검증 통과를 가정.

### 4.6 동시성 / TOCTOU

- 팀원 소속 검증과 INSERT/UPDATE 사이에 락 미적용 — PT 관행 동일, 운영 규모 작음.
- `update`/`delete`는 `WHERE id=? AND created_by=?` 한 문장으로 권한+row 동시 검증 (race-free).
- 출석 `toggle`은 UNIQUE 제약 + try/catch로 race 시 MySQL `1062` (Duplicate entry) 처리 후 SELECT 재확인 → 멱등 응답.

## 5. UI

### 5.1 코치 사이드 — 사이드바

```
SoriTune PT
─ 내 회원
─ 카톡방 입장 체크
─ 팀원 관리          ← NEW (팀장에게만 노출)
─ 코치 교육 출석      ← NEW (팀장에게만 노출, Task 18)
─ 내 정보
```

비-팀장에게는 PHP(`coach/index.php`) 단계에서 `<a>` 자체를 출력하지 않는다 (DOM 가드 우회 방지). 서버 가드도 별도로 `team_overview` 등 모든 액션이 비-팀장 호출 시 `403`.

### 5.2 화면 1 — `#team` (팀원 명단) — 요약 모드

> **Task 17 → Task 18 변경**: Task 17에서 일자별 4 컬럼 매트릭스(체크박스)로 구현했으나, 컬럼 헤더 일자가 매주 바뀌는 점에서 사용자 인지 부담이 발생. Task 18에서 출석 상세 입력은 별도 `#training-attendance` 페이지로 분리하고, 명단은 요약 모드로 복귀. 4 컬럼 매트릭스 제거.

```
┌─ 팀원 관리 ───────────────────────────────────────────────────────┐
│  ┌──────────────────────────────────────────────────────────────┐ │
│  │ 이름  │ 한글이름 │ 직전 4주 출석           │  면담  │         │
│  │──────────────────────────────────────────────────────────────│ │
│  │ Kel*  │ 켈      │ ████░ 3/4 75%          │   3    │         │
│  │ Lulu  │ 룰루    │ █░░░ 1/4 25%           │   1    │         │
│  │ Mia   │ 미아    │ ░░░░ 0/4 0%            │   -    │         │
│  └──────────────────────────────────────────────────────────────┘ │
│  ★ = 본인(팀장) · 행 클릭 → 상세                                   │
└───────────────────────────────────────────────────────────────────┘
```

- 열 구성: 이름 | 한글이름 | 직전 4주 출석(막대 + n/4 NN%) | 면담.
- 출석 막대: `attendanceBar(attended, total)` — 색상 기준: 100%=#1ed760, 75%+=#a3e635, 50%+=#ffa42b, 1%+=#f3727f, 0%=#4d4d4d. 칸 너비 12px.
- 행 클릭 → `#team/<coach_id>` 이동.
- 면담 카운트 0이면 `-` 표시, 1+ 면 숫자.
- 정렬: 본인 첫 행 → 이후 coach_name ASC.
- 팀원 0명 시 empty-state.
- 일자별 4 컬럼 매트릭스 / `handleBulkToggle` 제거됨 (`#training-attendance`로 이동).

### 5.3 화면 2 — `#team/<coach_id>` (팀원 상세)

```
┌─ ← 팀원 관리 / Lulu (룰루) ──────────────────────┐
│  [ 면담 기록 ]  [ 코치 교육 출석 ]              │
│                                                  │
│  ─── 면담 기록 탭 ───────────────────────────    │
│  [ + 새 면담 기록 ]                              │
│  ┌──────────────────────────────────────────┐   │
│  │ 2026-05-01                  [수정][삭제] │   │
│  │ 발성 톤이 좋아짐...                      │   │
│  └──────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────┐   │
│  │ 2026-04-22                  [수정][삭제] │   │
│  │ 회원 X에 대한 코칭 우려...               │   │
│  └──────────────────────────────────────────┘   │
└──────────────────────────────────────────────────┘
```

- breadcrumb의 "← 팀원 관리"는 hash navigate (`location.hash = 'team'`).
- 탭 전환은 페이지 내 toggle, 라우트 추가 안 함.
- **면담 탭**:
  - [+ 새 면담 기록] → 모달(date input + textarea + 저장/취소).
  - 카드: meeting_date DESC. 본인이 쓴 행에만 [수정][삭제]. 본인 외 작성 시 작성자 이름 부기("by Kel") — 일반 운영에선 본인만 작성하므로 거의 표시되지 않음.
- **출석 탭**:
  ```
  ┌──────────────────────────────────────────┐
  │  직전 4주: 3/4  (75%)                   │
  │                                          │
  │  2026-05-01 (목)  ☑ 출석              │
  │  2026-04-24 (목)  ☑ 출석              │
  │  2026-04-17 (목)  ☐ 결석              │
  │  2026-04-10 (목)  ☑ 출석              │
  │  ────────────────                       │
  │  더 이전 회차                          │
  │  ...8 entries                            │
  └──────────────────────────────────────────┘
  ```
  - 체크박스 클릭 = 즉시 `toggle` API (낙관적 UI: 체크 표시 즉시 반영, 실패 시 롤백 + 알림).
  - 명단 화면으로 복귀 시 출석율 컬럼 갱신.

### 5.4 화면 3 — 어드민 코치 페이지 면담 read 모달

`/admin/#coaches` 표 액션 컬럼에 [면담] 버튼 추가 → 모달:

```
┌─ Lulu (룰루) — 면담 기록 ─────────────────┐
│  ┌──────────────────────────────────────┐ │
│  │ 2026-05-01 · by Kel                  │ │
│  │ 발성 톤이 좋아짐...                  │ │
│  └──────────────────────────────────────┘ │
│  ┌──────────────────────────────────────┐ │
│  │ 2026-04-22 · by Kel                  │ │
│  │ 회원 X에 대한 코칭 우려...           │ │
│  └──────────────────────────────────────┘ │
│                            [닫기]          │
└────────────────────────────────────────────┘
```

- 어드민은 read-only — [수정][삭제] 없음, 작성자 항상 표기.
- 빈 상태: "면담 기록 없음".

### 5.5 모바일

- 명단 표는 `overflow-x:auto` 가로 스크롤로 처리.
- 상세는 1열 카드라 좁은 화면에서도 자연스러움.
- 어드민 모달은 기존 modal 스타일 재사용.

### 5.7 화면 4 — `#training-attendance` 코치 교육 출석 (Task 18 신설)

> 팀원 관리 명단에서 분리된 전용 출석체크 페이지. 사이드바에 "코치 교육 출석" 메뉴로 노출 (팀장 전용 PHP 가드).

```
┌─ 코치 교육 출석 ────────────────────────────────────────────────────┐
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ 회차: [2026-05-01 (목) ▼]            출석 8 / 11명          │  │
│  └──────────────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ 이름  │ 한글이름 │ 출석                                      │  │
│  │──────────────────────────────────────────────────────────────│  │
│  │ Kel★ │ 켈      │  ☑                                        │  │
│  │ Lulu  │ 룰루    │  ☐                                        │  │
│  │ Mia   │ 미아    │  ☑                                        │  │
│  └──────────────────────────────────────────────────────────────┘  │
│  ★ = 본인(팀장) · 체크박스 클릭 즉시 저장                           │
└─────────────────────────────────────────────────────────────────────┘
```

- 라우트: `#training-attendance` / 파일: `public_html/coach/js/pages/training-attendance.js`
- 데이터: `coach_self.php?action=team_overview` 재사용 (`members[].attendance` + `recent_dates`). 별도 API 없음.
- 회차 드롭다운: `recent_dates` 4개 — 가장 최근이 default. 선택 변경 시 표 즉시 재렌더링(in-memory 데이터 기준).
- 헤드라인: 선택 회차 기준 `출석 N / M명` (실시간 반영).
- 출석 컬럼: 선택 회차에 해당하는 `attendance[i].attended` 기준 체크박스 1개.
- 체크박스 클릭 → `coach_training_attendance.php?action=toggle` API → 성공 시 in-memory 캐시 갱신 후 `renderBody()` 재호출 (헤드라인 갱신 포함) / 실패 시 `alert` + 롤백.
- `attendance` 배열은 `team_overview` 응답에 이미 있음 (§4.2) — 신규 API 불필요.

### 5.6 XSS 가드

- 모든 텍스트 필드는 `UI.esc()` 처리.
- 본문 줄바꿈은 `white-space: pre-wrap` CSS로 처리(텍스트 escape 후 그대로).
- onclick 인라인 핸들러는 정수 ID에만 사용. 텍스트는 data-attribute + 위임 리스너.

## 6. 테스트

### 6.1 자동 테스트 — `tests/team_management_test.php`

PT 관행(`coach_team_kakao_test.php` 등) 따라 단일 PHP assertion 파일. LIB_ONLY 가드된 함수만 require.

**`recentTrainingDates()`**
- 오늘이 목요일 → 오늘 포함 직전 4개
- 오늘이 금요일 → 어제(목) 포함 직전 4개
- 오늘이 수요일 → 지난 목요일이 첫 원소
- 월/연도 경계 (예: 2026-01-01 기준)
- DOW 6(토요일) 파라미터 → 토요일들로 작동

**면담 CRUD**
- create 정상 / 본인 팀원 아니면 차단 / notes 빈 차단 / notes 50,001자 차단 / meeting_date 형식 차단
- update 작성자만 / 다른 팀장 시도 차단
- delete 동일
- list (코치): 본인 팀원만, 다른 팀 코치 id → 403
- list (어드민): 전체 통과, `can_edit=false`

**출석 toggle**
- INSERT 멱등 / DELETE 멱등 / 화요일 일자 차단 / 다른 팀 코치 차단
- attendance_rate 계산 검증
- history 응답 형식 (recent 4 + earlier 8)

**team_overview**
- 비-팀장 → 403
- 팀장 → 본인 첫 행 + 팀원 + 출석율
- 빈 팀

**logChange 검증**
- 면담 CRUD + 출석 toggle 모두 logs row 생성 (payload는 메타만, 본문 미포함 검증)

목표: 60+ assertion.

### 6.2 매뉴얼 검증 (DEV)

`https://dev-pt.soritune.com/coach/`에서:

1. Lulu(팀원) 로그인 → 사이드바 "팀원 관리" **없음**
2. Kel(팀장) 로그인 → "팀원 관리" 노출 → 명단 11명, 출석율 모두 0/4
3. Lulu 행 클릭 → 상세 진입, breadcrumb로 복귀
4. 면담 추가(오늘, 짧은 메모) → 카드 추가, 명단 면담 카운트 +1
5. 면담 수정 → 본문 변경 반영
6. 면담 삭제 → 카드 사라짐
7. 출석 탭 → 직전 4회 목요일 노출, 한 회차 체크 → 출석율 0% → 25%
8. 명단 복귀 → 해당 팀원 출석율 컬럼 갱신
9. Nana(다른 팀장) 로그인 → Kel의 면담 안 보임. URL `#team/<lulu_id>` 직접 입력 시 403/빈 상태
10. 어드민 로그인 → `/admin/#coaches` Lulu 행 [면담] → 모달에 Kel이 쓴 면담 표시, 수정/삭제 버튼 없음

## 7. 마이그레이션 / 배포

### 7.1 DEV

```
1. mysql DEV_PT < migrations/20260504_add_coach_meeting_notes.sql
2. mysql DEV_PT < migrations/20260504_add_coach_training_attendance.sql
3. schema.sql 업데이트 (CREATE TABLE 2개 추가)
4. 자동 테스트 PASS
5. §6.2 매뉴얼 검증 10 시나리오 통과
6. git push origin dev
7. ⛔ 사용자 운영 반영 명시 요청 대기
```

### 7.2 PROD (사용자 명시 요청 후)

```
1. junior-dev에서 main merge dev → push origin main
2. pt-prod에서 git pull origin main
3. mysql PROD_PT < migrations/20260504_add_coach_meeting_notes.sql
4. mysql PROD_PT < migrations/20260504_add_coach_training_attendance.sql
5. PROD 스모크: 어드민으로 임의 코치 [면담] 클릭 → 빈 모달 정상
```

### 7.3 롤백

- 면담 마이그: `DROP TABLE coach_meeting_notes;` (FK 영향 없음)
- 출석 마이그: `DROP TABLE coach_training_attendance;`
- 코드: `git revert <merge-sha>` 후 `pt-prod git pull`
- 마이그-코드 분리되어 한쪽만 롤백 가능

## 8. 보안 / 운영 노트

- `notes` 본문은 sensitive — DB 백업 정책은 PT 일반 백업과 동일 등급(별도 암호화 안 함; PT는 회원 PII 보유 사이트와 동일 등급).
- Audit `logChange()`에 본문 전체는 기록 안 함(메타만). 잘못 삭제 시 본문 복구 불가하다는 점은 사용자에게 환기. 필요 시 `coach_meeting_notes_history` 추후 추가.
- 면담 권한이 작성자에 한정되므로, 코치 팀 이동 시 과거 메모는 새 팀장에게 보이지 않음(어드민은 봄). 이 정책은 사용자가 명시적으로 결정한 사항(B 옵션).
- 출석 화면을 어드민에게 1차에서 노출하지 않으므로, 운영팀이 전 팀 출석율을 봐야 할 시점에 별도 plan으로 추가.
