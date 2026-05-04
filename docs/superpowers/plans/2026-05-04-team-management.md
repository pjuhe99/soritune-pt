# PT 팀원 관리 (면담 차트 + 코치 교육 출석) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 팀장이 자기 팀원의 면담 기록(CRUD)과 코치 교육 출석(직전 4주 기준)을 통합 SPA 메뉴 "팀원 관리"에서 관리. 어드민은 코치 페이지에서 면담을 read-only로 조회.

**Architecture:** 두 신규 테이블(`coach_meeting_notes`, `coach_training_attendance`) + 두 신규 도메인 API 파일 + 공용 가드(`coach_team_guard.php`) + 가상 회차 산출 함수(`coach_training.php`). 코치 SPA에 `#team` / `#team/<coach_id>` 두 라우트와 `team-management.js` 한 페이지 모듈을 추가하고, 어드민 코치 페이지 표에 [면담] 버튼으로 read-only 모달 진입점만 추가한다. 모든 신규 API는 `LIB_ONLY` 가드 패턴(라우터 분리)으로 단위 테스트가 함수만 require해서 사용한다.

**Tech Stack:** PHP 8 + MySQL (PDO), 바닐라 JS SPA (`CoachApp.registerPage` 패턴), 자체 테스트 러너(`tests/_bootstrap.php` + `t_assert_*`).

**Spec:** `docs/superpowers/specs/2026-05-04-team-management-design.md`

---

## File Structure

**신규 파일**:
- `migrations/20260504_add_coach_meeting_notes.sql` — 면담 테이블
- `migrations/20260504_add_coach_training_attendance.sql` — 출석 테이블
- `public_html/includes/coach_training.php` — `COACH_TRAINING_DOW`, `COACH_TRAINING_RECENT_COUNT`, `recentTrainingDates()`
- `public_html/includes/coach_team_guard.php` — `assertIsLeader`, `assertCoachIsMyMember`
- `public_html/api/coach_meeting_notes.php` — 면담 CRUD (함수 + 라우터)
- `public_html/api/coach_training_attendance.php` — 출석 history/toggle (함수 + 라우터)
- `public_html/coach/js/pages/team-management.js` — 팀원 관리 SPA (`#team` 명단 + `#team/<id>` 상세 한 모듈)
- `tests/team_management_test.php` — 통합 테스트 (60+ assertion 목표)

**수정 파일**:
- `schema.sql` — CREATE TABLE 2개 추가
- `public_html/api/coach_self.php` — `team_overview` 액션 추가
- `public_html/coach/index.php` — 사이드바 메뉴(팀장만) + 스크립트 등록
- `public_html/admin/js/pages/coaches.js` — 표에 [면담] 버튼 + read-only 모달

---

## Task 1: 마이그레이션 작성 + DEV 적용 + schema.sql 동기화

**Files:**
- Create: `migrations/20260504_add_coach_meeting_notes.sql`
- Create: `migrations/20260504_add_coach_training_attendance.sql`
- Modify: `schema.sql` (CREATE TABLE coaches 정의 직후 두 테이블 추가)

- [ ] **Step 1.1: 면담 테이블 마이그레이션 작성**

Create `migrations/20260504_add_coach_meeting_notes.sql`:

```sql
-- 코치 면담 기록 테이블
-- 팀장이 자기 팀원과의 면담 시 특이사항을 일자별로 기록.
-- 작성/수정/삭제는 작성한 팀장 본인만, 조회는 작성 팀장 + 어드민(read-only).

CREATE TABLE coach_meeting_notes (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  coach_id      INT NOT NULL COMMENT '면담 대상 (팀원) coaches.id',
  meeting_date  DATE NOT NULL COMMENT '팀장이 선택한 면담 일자',
  notes         TEXT NOT NULL,
  created_by    INT NOT NULL COMMENT '작성한 팀장 coaches.id',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_coach_date (coach_id, meeting_date DESC, id DESC),
  INDEX idx_created_by (created_by),
  CONSTRAINT fk_cmn_coach   FOREIGN KEY (coach_id)   REFERENCES coaches(id) ON DELETE CASCADE,
  CONSTRAINT fk_cmn_creator FOREIGN KEY (created_by) REFERENCES coaches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 1.2: 출석 테이블 마이그레이션 작성**

Create `migrations/20260504_add_coach_training_attendance.sql`:

```sql
-- 코치 교육 출석 (가상 회차 모델: row 존재=출석, 없음=결석)
-- 매주 목요일 코치 교육. 팀장이 자기 팀원의 출석을 토글.

CREATE TABLE coach_training_attendance (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  coach_id       INT NOT NULL,
  training_date  DATE NOT NULL COMMENT '가상 회차 일자 (보통 목요일)',
  marked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  marked_by      INT NOT NULL COMMENT '체크한 팀장 coaches.id',
  UNIQUE KEY uk_coach_date (coach_id, training_date),
  INDEX idx_training_date (training_date),
  CONSTRAINT fk_cta_coach  FOREIGN KEY (coach_id)  REFERENCES coaches(id) ON DELETE CASCADE,
  CONSTRAINT fk_cta_marker FOREIGN KEY (marked_by) REFERENCES coaches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 1.3: DEV DB에 적용**

Run:
```bash
cd /root/pt-dev
source .db_credentials
mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" < migrations/20260504_add_coach_meeting_notes.sql
mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" < migrations/20260504_add_coach_training_attendance.sql
```

Expected: 출력 없음(성공).

검증:
```bash
mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" "$DB_NAME" -e "SHOW CREATE TABLE coach_meeting_notes\G SHOW CREATE TABLE coach_training_attendance\G" | head -50
```
Expected: 두 테이블 정의 출력, FK/인덱스 모두 존재.

- [ ] **Step 1.4: schema.sql 동기화**

`schema.sql`을 열어 `CREATE TABLE coaches` 정의가 끝나는 다음 위치에 두 CREATE TABLE을 추가한다 (Step 1.1, 1.2의 SQL을 그대로 붙여넣기, 마지막 ENGINE 절까지 포함).

- [ ] **Step 1.5: Commit**

```bash
git -C /root/pt-dev add \
  migrations/20260504_add_coach_meeting_notes.sql \
  migrations/20260504_add_coach_training_attendance.sql \
  schema.sql
git -C /root/pt-dev commit -m "feat(team-mgmt): 면담/출석 테이블 마이그레이션 + schema.sql"
```

---

## Task 2: `coach_training.php` — 상수 + `recentTrainingDates()` 순수 함수

**Files:**
- Create: `public_html/includes/coach_training.php`
- Create: `tests/team_management_test.php` (이 task에서 신규 + 점진 추가)

- [ ] **Step 2.1: 실패 테스트 작성**

Create `tests/team_management_test.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/coach_training.php';

t_section('recentTrainingDates — 오늘이 목요일이면 오늘 포함');
$thu = new DateTimeImmutable('2026-04-30 21:00:00', new DateTimeZone('Asia/Seoul')); // Thursday
t_assert_eq(
    ['2026-04-30','2026-04-23','2026-04-16','2026-04-09'],
    recentTrainingDates($thu, 4, 4),
    '목요일 21시 → 오늘 포함 직전 4개'
);

t_section('recentTrainingDates — 금요일은 어제(목) 포함');
$fri = new DateTimeImmutable('2026-05-01 09:00:00', new DateTimeZone('Asia/Seoul')); // Friday
t_assert_eq(
    ['2026-04-30','2026-04-23','2026-04-16','2026-04-09'],
    recentTrainingDates($fri, 4, 4),
    '금요일 → 어제 목요일 첫 원소'
);

t_section('recentTrainingDates — 수요일은 지난 목요일이 첫 원소');
$wed = new DateTimeImmutable('2026-04-29 12:00:00', new DateTimeZone('Asia/Seoul')); // Wednesday
t_assert_eq(
    ['2026-04-23','2026-04-16','2026-04-09','2026-04-02'],
    recentTrainingDates($wed, 4, 4),
    '수요일 → 지난 목요일'
);

t_section('recentTrainingDates — 월/연도 경계');
$earlyJan = new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('Asia/Seoul')); // Monday
t_assert_eq(
    ['2026-01-01','2025-12-25','2025-12-18','2025-12-11'],
    recentTrainingDates($earlyJan, 4, 4),
    '연도 넘어가는 경계'
);

t_section('recentTrainingDates — DOW 파라미터(토요일=6)');
$sat = new DateTimeImmutable('2026-05-02 10:00:00', new DateTimeZone('Asia/Seoul')); // Saturday
t_assert_eq(
    ['2026-05-02','2026-04-25','2026-04-18','2026-04-11'],
    recentTrainingDates($sat, 4, 6),
    '토요일 DOW로도 작동'
);

t_section('recentTrainingDates — N=8');
t_assert_eq(8, count(recentTrainingDates($thu, 8, 4)), 'N개수 그대로');
```

- [ ] **Step 2.2: 테스트 실패 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -20
```
Expected: `Fatal error: Uncaught Error: Failed opening required '...coach_training.php'` 또는 `Call to undefined function recentTrainingDates()`.

- [ ] **Step 2.3: `coach_training.php` 구현**

Create `public_html/includes/coach_training.php`:

```php
<?php
declare(strict_types=1);

/**
 * 코치 교육 회차 정의 (가상 회차 — DB 테이블 없음)
 * 요일이 바뀌면 COACH_TRAINING_DOW 한 줄만 변경.
 */
const COACH_TRAINING_DOW = 4;          // ISO-8601: 1=월 ... 7=일. 4=목.
const COACH_TRAINING_RECENT_COUNT = 4; // 출석율 분모 (직전 N회)

/**
 * KST 기준 직전 N개 교육 일자(목요일)를 DESC로 반환.
 * 오늘이 교육 요일이면 오늘을 첫 번째로 포함.
 *
 * @param DateTimeImmutable $nowKst KST timezone 시각
 * @param int               $n      반환 개수 (>=1)
 * @param int               $dow    ISO-8601 요일 (1~7)
 * @return string[] DESC YYYY-MM-DD ×N (최신이 [0])
 */
function recentTrainingDates(
    DateTimeImmutable $nowKst,
    int $n = COACH_TRAINING_RECENT_COUNT,
    int $dow = COACH_TRAINING_DOW
): array {
    if ($n < 1) {
        throw new InvalidArgumentException('n must be >= 1');
    }
    if ($dow < 1 || $dow > 7) {
        throw new InvalidArgumentException('dow must be 1..7');
    }

    // 오늘 기준 가장 최근의 (오늘 포함) $dow 요일까지 거슬러 올라간다
    $today = $nowKst->setTime(0, 0, 0);
    $todayDow = (int)$today->format('N'); // 1..7
    $diff = ($todayDow - $dow + 7) % 7;    // 0~6
    $latest = $today->modify("-{$diff} days");

    $out = [];
    $cur = $latest;
    for ($i = 0; $i < $n; $i++) {
        $out[] = $cur->format('Y-m-d');
        $cur = $cur->modify('-7 days');
    }
    return $out;
}
```

- [ ] **Step 2.4: 테스트 통과 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -20
```
Expected: `recentTrainingDates` 섹션의 모든 t_assert PASS.

- [ ] **Step 2.5: Commit**

```bash
git -C /root/pt-dev add \
  public_html/includes/coach_training.php \
  tests/team_management_test.php
git -C /root/pt-dev commit -m "feat(team-mgmt): recentTrainingDates 가상 회차 산출 함수"
```

---

## Task 3: `coach_team_guard.php` — 공용 가드

**Files:**
- Create: `public_html/includes/coach_team_guard.php`
- Modify: `tests/team_management_test.php` (append)

가드 함수가 검증 실패 시 `jsonError()` → `exit`. 테스트에서는 `exit`을 직접 발동할 수 없으니, 검증 통과 시 silent return 만 검증하고, 실패 케이스는 OutputBufferingException 패턴 대신 가드 내부에서 `RuntimeException`을 throw하는 변형을 두지 않고, **검증 통과만 t_assert로 확인**한다 (실패 케이스는 라우터 통합 테스트에서 HTTP 코드로 검증).

대신 가드의 내부 SQL 동작을 확인하기 위한 더 작은 helper `coachIsMyMember(PDO,$leaderId,$targetCoachId): bool` 도 함께 export 해서 테스트 가능하게 한다.

- [ ] **Step 3.1: 실패 테스트 추가 (`tests/team_management_test.php` append)**

Append:

```php
require_once __DIR__ . '/../public_html/includes/coach_team_guard.php';

t_section('coachIsMyMember — 같은 팀이면 true');
$db = getDB();
// 시드: Kel(팀장) — Lulu/Ella/... (같은 팀)
$kelId  = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Kel'")->fetchColumn();
$luluId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Lulu'")->fetchColumn();
$nanaId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Nana'")->fetchColumn();
$hyunId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Hyun'")->fetchColumn();

t_assert_true(coachIsMyMember($db, $kelId, $luluId), 'Kel→Lulu 같은 팀');
t_assert_true(coachIsMyMember($db, $kelId, $kelId),  'Kel→Kel 자기 자신도 true');
t_assert_eq(false, coachIsMyMember($db, $kelId, $hyunId), 'Kel→Hyun(Nana팀) false');
t_assert_eq(false, coachIsMyMember($db, $kelId, 99999),   '존재하지 않는 coach_id false');

t_section('coachIsLeader — 자기 팀장이면 true');
t_assert_true(coachIsLeader($db, $kelId),  'Kel은 팀장');
t_assert_true(coachIsLeader($db, $nanaId), 'Nana는 팀장');
t_assert_eq(false, coachIsLeader($db, $luluId), 'Lulu는 팀장 아님');
t_assert_eq(false, coachIsLeader($db, 99999),   '존재하지 않는 coach_id false');
```

- [ ] **Step 3.2: 테스트 실패 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -10
```
Expected: `Failed opening required '...coach_team_guard.php'`.

- [ ] **Step 3.3: `coach_team_guard.php` 구현**

Create `public_html/includes/coach_team_guard.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * $coachId가 자기 자신을 팀장으로 가리키는지(team_leader_id == id) 검증.
 */
function coachIsLeader(PDO $db, int $coachId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM coaches WHERE id = ? AND team_leader_id = id LIMIT 1"
    );
    $stmt->execute([$coachId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * $targetCoachId가 $leaderId 팀장의 본인 팀 멤버(같은 team_leader_id)인지 검증.
 * 팀장 자신($targetCoachId == $leaderId)도 자기 팀의 멤버로 통과한다.
 */
function coachIsMyMember(PDO $db, int $leaderId, int $targetCoachId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM coaches WHERE id = ? AND team_leader_id = ? LIMIT 1"
    );
    $stmt->execute([$targetCoachId, $leaderId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * 팀장 권한 가드. 비-팀장이면 jsonError 403 후 exit.
 */
function assertIsLeader(PDO $db, int $coachId): void
{
    if (!coachIsLeader($db, $coachId)) {
        jsonError('팀장 권한이 필요합니다', 403);
    }
}

/**
 * 본인 팀 멤버 가드. 멤버 아니면 jsonError 403 후 exit.
 */
function assertCoachIsMyMember(PDO $db, int $leaderId, int $targetCoachId): void
{
    if (!coachIsMyMember($db, $leaderId, $targetCoachId)) {
        jsonError('해당 코치에 대한 권한이 없습니다', 403);
    }
}
```

- [ ] **Step 3.4: 테스트 통과 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -15
```
Expected: 신규 8개 t_assert 모두 PASS.

- [ ] **Step 3.5: Commit**

```bash
git -C /root/pt-dev add \
  public_html/includes/coach_team_guard.php \
  tests/team_management_test.php
git -C /root/pt-dev commit -m "feat(team-mgmt): 팀장/멤버 가드 (coachIsLeader/coachIsMyMember + assert*)"
```

---

## Task 4: `coach_meeting_notes.php` — CRUD 함수 (라우터 제외)

**Files:**
- Create: `public_html/api/coach_meeting_notes.php` (함수만 — Task 5에서 라우터 추가)
- Modify: `tests/team_management_test.php` (append)

함수 시그니처:
```php
function listMeetingNotes(PDO $db, int $coachId): array         // [{id,meeting_date,notes,created_by,created_by_name,created_at,updated_at}]
function createMeetingNote(PDO $db, int $coachId, int $createdBy, string $meetingDate, string $notes): int  // new id
function updateMeetingNote(PDO $db, int $id, int $createdBy, string $meetingDate, string $notes): bool  // affected>0
function deleteMeetingNote(PDO $db, int $id, int $createdBy): bool  // affected>0
```

- 검증(`createMeetingNote`/`updateMeetingNote`): 형식 위반 시 `InvalidArgumentException`
- 권한(`updateMeetingNote`/`deleteMeetingNote`): `WHERE id=? AND created_by=?` 한 문장. 0 rows → false
- 권한 검증(소속): caller 책임 (라우터에서 가드)
- `notes` 1~50000자, `meeting_date` `YYYY-MM-DD` + `checkdate()`

- [ ] **Step 4.1: 실패 테스트 추가 (append)**

Append to `tests/team_management_test.php`:

```php
const COACH_MEETING_NOTES_LIB_ONLY = true;
require_once __DIR__ . '/../public_html/api/coach_meeting_notes.php';

t_section('createMeetingNote — 정상 INSERT');
$db = getDB();
$kelId  = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Kel'")->fetchColumn();
$luluId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Lulu'")->fetchColumn();

$noteId = createMeetingNote($db, $luluId, $kelId, '2026-05-01', 'Lulu 발성 톤 좋아짐');
t_assert_true($noteId > 0, '신규 id 반환');

$row = $db->query("SELECT * FROM coach_meeting_notes WHERE id={$noteId}")->fetch(PDO::FETCH_ASSOC);
t_assert_eq($luluId, (int)$row['coach_id'], 'coach_id 일치');
t_assert_eq($kelId,  (int)$row['created_by'], 'created_by 일치');
t_assert_eq('2026-05-01', $row['meeting_date'], 'meeting_date 일치');
t_assert_eq('Lulu 발성 톤 좋아짐', $row['notes'], 'notes 일치');

t_section('createMeetingNote — 검증');
t_assert_throws(
    fn() => createMeetingNote($db, $luluId, $kelId, '2026-13-01', 'x'),
    InvalidArgumentException::class,
    '잘못된 날짜 형식 거부 (월=13)'
);
t_assert_throws(
    fn() => createMeetingNote($db, $luluId, $kelId, '2026-05-01', ''),
    InvalidArgumentException::class,
    '빈 notes 거부'
);
t_assert_throws(
    fn() => createMeetingNote($db, $luluId, $kelId, '2026-05-01', '   '),
    InvalidArgumentException::class,
    '공백 only notes 거부'
);
t_assert_throws(
    fn() => createMeetingNote($db, $luluId, $kelId, '2026-05-01', str_repeat('a', 50001)),
    InvalidArgumentException::class,
    '50001자 거부'
);

t_section('listMeetingNotes — DESC 정렬 + JOIN');
$noteId2 = createMeetingNote($db, $luluId, $kelId, '2026-05-03', '두 번째 메모');
$rows = listMeetingNotes($db, $luluId);
$ids = array_map(fn($r) => (int)$r['id'], $rows);
$pos1 = array_search($noteId,  $ids, true);
$pos2 = array_search($noteId2, $ids, true);
t_assert_true($pos1 !== false && $pos2 !== false, '두 메모 모두 list에 존재');
t_assert_true($pos2 < $pos1, '최신(2026-05-03)이 먼저');
$row2 = array_values(array_filter($rows, fn($r) => (int)$r['id'] === $noteId2))[0];
t_assert_eq('Kel', $row2['created_by_name'], 'created_by_name JOIN');

t_section('updateMeetingNote — 작성자 본인만');
t_assert_true(
    updateMeetingNote($db, $noteId, $kelId, '2026-05-01', '수정 본문'),
    'Kel이 자기가 쓴 메모 수정 → true'
);
$row = $db->query("SELECT notes FROM coach_meeting_notes WHERE id={$noteId}")->fetch();
t_assert_eq('수정 본문', $row['notes'], '수정 반영');

$nanaId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Nana'")->fetchColumn();
t_assert_eq(false,
    updateMeetingNote($db, $noteId, $nanaId, '2026-05-01', '탈취 시도'),
    'Nana가 Kel 메모 수정 → false'
);

t_section('deleteMeetingNote — 작성자 본인만');
t_assert_eq(false,
    deleteMeetingNote($db, $noteId, $nanaId),
    'Nana 삭제 시도 → false'
);
t_assert_true(deleteMeetingNote($db, $noteId,  $kelId), 'Kel 본인 삭제 → true');
t_assert_true(deleteMeetingNote($db, $noteId2, $kelId), '두 번째도 삭제');

$cnt = (int)$db->query(
    "SELECT COUNT(*) FROM coach_meeting_notes WHERE id IN ({$noteId},{$noteId2})"
)->fetchColumn();
t_assert_eq(0, $cnt, '두 row 모두 삭제됨');

t_section('createMeetingNote — logChange 본문 미저장');
$noteId3 = createMeetingNote($db, $luluId, $kelId, '2026-05-04', '로그 검증 본문');
$logRow = $db->query(
    "SELECT new_value FROM change_logs
      WHERE target_type='meeting_note' AND target_id={$noteId3} AND action='create'"
)->fetch(PDO::FETCH_ASSOC);
t_assert_true($logRow !== false, 'create 로그 row 생성됨');
$payload = json_decode($logRow['new_value'], true);
t_assert_true(!isset($payload['notes']), 'logChange new_value에 본문(notes) 미저장');
t_assert_eq($luluId, $payload['coach_id'], 'logChange new_value에 coach_id 메타');
deleteMeetingNote($db, $noteId3, $kelId);
```

- [ ] **Step 4.2: 테스트 실패 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -10
```
Expected: `Failed opening required '...coach_meeting_notes.php'`.

- [ ] **Step 4.3: 함수 구현 (`coach_meeting_notes.php` 신규, 라우터는 Task 5)**

Create `public_html/api/coach_meeting_notes.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coach_team_guard.php';

/**
 * 면담 본문 검증.
 * @throws InvalidArgumentException
 */
function validateMeetingNoteBody(string $notes): string
{
    $trimmed = trim($notes);
    if ($trimmed === '') {
        throw new InvalidArgumentException('notes는 빈 문자열일 수 없습니다');
    }
    if (mb_strlen($trimmed) > 50000) {
        throw new InvalidArgumentException('notes는 50,000자를 초과할 수 없습니다');
    }
    return $trimmed;
}

/**
 * meeting_date 검증. YYYY-MM-DD + checkdate.
 * @throws InvalidArgumentException
 */
function validateMeetingDate(string $date): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        throw new InvalidArgumentException('meeting_date 형식 오류 (YYYY-MM-DD)');
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new InvalidArgumentException('meeting_date 유효하지 않은 일자');
    }
    return $date;
}

/**
 * 한 코치 대상의 면담 list. meeting_date DESC, id DESC.
 * 권한 검증은 caller 책임.
 */
function listMeetingNotes(PDO $db, int $coachId): array
{
    $stmt = $db->prepare("
        SELECT n.id, n.meeting_date, n.notes,
               n.created_by, c.coach_name AS created_by_name,
               n.created_at, n.updated_at
          FROM coach_meeting_notes n
          JOIN coaches c ON c.id = n.created_by
         WHERE n.coach_id = ?
         ORDER BY n.meeting_date DESC, n.id DESC
    ");
    $stmt->execute([$coachId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 면담 INSERT. 권한 검증은 caller 책임.
 * @return int 신규 row id
 * @throws InvalidArgumentException
 */
function createMeetingNote(
    PDO $db, int $coachId, int $createdBy, string $meetingDate, string $notes
): int {
    $meetingDate = validateMeetingDate($meetingDate);
    $notes       = validateMeetingNoteBody($notes);

    $stmt = $db->prepare("
        INSERT INTO coach_meeting_notes (coach_id, meeting_date, notes, created_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$coachId, $meetingDate, $notes, $createdBy]);
    $id = (int)$db->lastInsertId();

    logChange($db, 'meeting_note', $id, 'create',
        null,
        ['coach_id' => $coachId, 'meeting_date' => $meetingDate],
        'coach', $createdBy);

    return $id;
}

/**
 * 면담 UPDATE — 작성자 본인만 가능 (WHERE created_by=?로 race-free).
 * @return bool affected_rows > 0
 * @throws InvalidArgumentException
 */
function updateMeetingNote(
    PDO $db, int $id, int $createdBy, string $meetingDate, string $notes
): bool {
    $meetingDate = validateMeetingDate($meetingDate);
    $notes       = validateMeetingNoteBody($notes);

    $stmt = $db->prepare("
        UPDATE coach_meeting_notes
           SET meeting_date = ?, notes = ?
         WHERE id = ? AND created_by = ?
    ");
    $stmt->execute([$meetingDate, $notes, $id, $createdBy]);
    $affected = $stmt->rowCount() > 0;

    if ($affected) {
        logChange($db, 'meeting_note', $id, 'update',
            null,
            ['meeting_date' => $meetingDate],
            'coach', $createdBy);
    }
    return $affected;
}

/**
 * 면담 DELETE — 작성자 본인만 가능.
 * @return bool affected_rows > 0
 */
function deleteMeetingNote(PDO $db, int $id, int $createdBy): bool
{
    $stmt = $db->prepare("
        DELETE FROM coach_meeting_notes
         WHERE id = ? AND created_by = ?
    ");
    $stmt->execute([$id, $createdBy]);
    $affected = $stmt->rowCount() > 0;

    if ($affected) {
        logChange($db, 'meeting_note', $id, 'delete', null, null, 'coach', $createdBy);
    }
    return $affected;
}

// 라우터는 Task 5에서 추가
if (PHP_SAPI === 'cli' || defined('COACH_MEETING_NOTES_LIB_ONLY')) return;
```

- [ ] **Step 4.4: 테스트 통과 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -25
```
Expected: 면담 CRUD 신규 t_assert 모두 PASS.

- [ ] **Step 4.5: Commit**

```bash
git -C /root/pt-dev add \
  public_html/api/coach_meeting_notes.php \
  tests/team_management_test.php
git -C /root/pt-dev commit -m "feat(team-mgmt): 면담 CRUD 함수 (list/create/update/delete + 검증)"
```

---

## Task 5: `coach_meeting_notes.php` — 라우터

**Files:**
- Modify: `public_html/api/coach_meeting_notes.php:끝부분` (Task 4의 LIB_ONLY 가드 다음에 라우터 코드 추가)

라우터:
- `GET ?action=list&coach_id=X`: 코치-팀장(본인 팀원만) 또는 어드민(전 코치)
- `POST ?action=create`: 코치-팀장만, body `{coach_id, meeting_date, notes}`
- `POST ?action=update&id=X`: 작성자 본인만, body `{meeting_date, notes}`
- `POST ?action=delete&id=X`: 작성자 본인만

응답에 `can_edit` 부가:
- 코치 본인 list 시: created_by == 본인이면 true
- 어드민 list 시: 전부 false

- [ ] **Step 5.1: 라우터 추가**

Edit `public_html/api/coach_meeting_notes.php` 끝부분 (`if (PHP_SAPI === 'cli' || defined('COACH_MEETING_NOTES_LIB_ONLY')) return;` 라인 다음에 이어서 append):

```php

header('Content-Type: application/json; charset=utf-8');
$user = requireAnyAuth();
$db   = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list': {
        $coachId = (int)($_GET['coach_id'] ?? 0);
        if (!$coachId) jsonError('coach_id가 필요합니다');

        $isAdmin = $user['role'] === 'admin';
        if (!$isAdmin) {
            // 코치: 자기 팀 멤버만
            assertIsLeader($db, (int)$user['id']);
            assertCoachIsMyMember($db, (int)$user['id'], $coachId);
        }

        $notes = listMeetingNotes($db, $coachId);
        $viewerId = (int)$user['id'];
        foreach ($notes as &$n) {
            $n['can_edit'] = !$isAdmin && (int)$n['created_by'] === $viewerId;
        }
        unset($n);

        jsonSuccess(['notes' => $notes]);
    }

    case 'create': {
        if ($user['role'] !== 'coach') jsonError('코치만 가능합니다', 403);
        $input = getJsonInput();
        $coachId     = (int)($input['coach_id'] ?? 0);
        $meetingDate = (string)($input['meeting_date'] ?? '');
        $notes       = (string)($input['notes'] ?? '');
        if (!$coachId) jsonError('coach_id가 필요합니다');

        assertIsLeader($db, (int)$user['id']);
        assertCoachIsMyMember($db, (int)$user['id'], $coachId);

        try {
            $id = createMeetingNote($db, $coachId, (int)$user['id'], $meetingDate, $notes);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        jsonSuccess(['id' => $id]);
    }

    case 'update': {
        if ($user['role'] !== 'coach') jsonError('코치만 가능합니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('id가 필요합니다');

        $input = getJsonInput();
        $meetingDate = (string)($input['meeting_date'] ?? '');
        $notes       = (string)($input['notes'] ?? '');

        try {
            $ok = updateMeetingNote($db, $id, (int)$user['id'], $meetingDate, $notes);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        if (!$ok) jsonError('수정할 권한이 없거나 메모를 찾을 수 없습니다', 403);

        jsonSuccess(['updated' => true]);
    }

    case 'delete': {
        if ($user['role'] !== 'coach') jsonError('코치만 가능합니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('id가 필요합니다');

        $ok = deleteMeetingNote($db, $id, (int)$user['id']);
        if (!$ok) jsonError('삭제할 권한이 없거나 메모를 찾을 수 없습니다', 403);

        jsonSuccess(['deleted' => true]);
    }

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 5.2: 라우터 syntax 확인**

Run:
```bash
cd /root/pt-dev && php -l public_html/api/coach_meeting_notes.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5.3: 라우터가 LIB_ONLY 모드를 깨지 않는지 회귀 테스트**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -10
```
Expected: 기존 테스트 모두 통과 (라우터가 LIB_ONLY define 후 require에서 return으로 스킵).

- [ ] **Step 5.4: Commit**

```bash
git -C /root/pt-dev add public_html/api/coach_meeting_notes.php
git -C /root/pt-dev commit -m "feat(team-mgmt): coach_meeting_notes 라우터 (list/create/update/delete)"
```

---

## Task 6: `coach_training_attendance.php` — 함수

**Files:**
- Create: `public_html/api/coach_training_attendance.php` (함수만 — Task 7에서 라우터)
- Modify: `tests/team_management_test.php` (append)

함수 시그니처:
```php
function listAttendanceHistory(PDO $db, int $coachId, DateTimeImmutable $nowKst): array
// returns ['recent' => [...4], 'earlier' => [...8], 'attended_count' => int, 'total_count' => 4, 'attendance_rate' => float]
// recent[i] = ['date'=>'YYYY-MM-DD','attended'=>0|1,'marked_at'=>?,'marked_by_name'=>?]

function toggleAttendance(PDO $db, int $coachId, string $trainingDate, bool $attended, int $markedBy): bool
// returns true if state changed
// throws InvalidArgumentException for date format / DOW mismatch
```

`history`는 직전 4회 + 그 이전 8회 (총 12회) recent_dates를 산출하고 그 일자에 대한 출석 row를 LEFT JOIN.

- [ ] **Step 6.1: 실패 테스트 추가 (append)**

Append:

```php
const COACH_TRAINING_ATTENDANCE_LIB_ONLY = true;
require_once __DIR__ . '/../public_html/api/coach_training_attendance.php';

t_section('toggleAttendance — INSERT/DELETE 멱등');
$db = getDB();
$kelId  = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Kel'")->fetchColumn();
$luluId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Lulu'")->fetchColumn();
$thuStr = '2026-04-30'; // Thursday

// 시작 상태: 깨끗하게
$db->prepare("DELETE FROM coach_training_attendance WHERE coach_id=? AND training_date=?")
   ->execute([$luluId, $thuStr]);

t_assert_true(toggleAttendance($db, $luluId, $thuStr, true, $kelId), '체크 ON → 변경');
t_assert_eq(false, toggleAttendance($db, $luluId, $thuStr, true, $kelId), '두번째 체크 ON → no-op');

$cnt = (int)$db->query(
    "SELECT COUNT(*) FROM coach_training_attendance WHERE coach_id={$luluId} AND training_date='{$thuStr}'"
)->fetchColumn();
t_assert_eq(1, $cnt, 'row 1개 존재 (UNIQUE)');

t_assert_true(toggleAttendance($db, $luluId, $thuStr, false, $kelId), '체크 OFF → 변경');
t_assert_eq(false, toggleAttendance($db, $luluId, $thuStr, false, $kelId), '두번째 OFF → no-op');

$cnt = (int)$db->query(
    "SELECT COUNT(*) FROM coach_training_attendance WHERE coach_id={$luluId} AND training_date='{$thuStr}'"
)->fetchColumn();
t_assert_eq(0, $cnt, 'row 삭제됨');

t_section('toggleAttendance — 검증');
t_assert_throws(
    fn() => toggleAttendance($db, $luluId, '2026-04-29', true, $kelId), // Wednesday
    InvalidArgumentException::class,
    '수요일 일자 거부'
);
t_assert_throws(
    fn() => toggleAttendance($db, $luluId, '2026-13-01', true, $kelId),
    InvalidArgumentException::class,
    '잘못된 형식 거부'
);

t_section('listAttendanceHistory — 형식 + 출석율');
$now = new DateTimeImmutable('2026-05-01 09:00:00', new DateTimeZone('Asia/Seoul'));
// 시드: 직전 4회 = 04-30, 04-23, 04-16, 04-09 중 첫 두 개만 출석
$db->prepare("DELETE FROM coach_training_attendance WHERE coach_id=?")->execute([$luluId]);
toggleAttendance($db, $luluId, '2026-04-30', true, $kelId);
toggleAttendance($db, $luluId, '2026-04-23', true, $kelId);

$h = listAttendanceHistory($db, $luluId, $now);
t_assert_eq(4, count($h['recent']),  'recent 4개');
t_assert_eq(8, count($h['earlier']), 'earlier 8개');
t_assert_eq(2, $h['attended_count'], '출석 2회');
t_assert_eq(4, $h['total_count'],    '분모 4');
t_assert_eq(0.5, $h['attendance_rate'], '출석율 50%');
t_assert_eq('2026-04-30', $h['recent'][0]['date'], 'recent[0] 최신');
t_assert_eq(1, (int)$h['recent'][0]['attended'], 'recent[0] 출석');
t_assert_eq(0, (int)$h['recent'][2]['attended'], 'recent[2] 결석');
t_assert_eq('Kel', $h['recent'][0]['marked_by_name'], 'marked_by_name JOIN');

// 정리
$db->prepare("DELETE FROM coach_training_attendance WHERE coach_id=?")->execute([$luluId]);

t_section('toggleAttendance — logChange (메타만)');
toggleAttendance($db, $luluId, '2026-04-30', true, $kelId);
$logCnt = (int)$db->query(
    "SELECT COUNT(*) FROM change_logs
      WHERE target_type='training_attendance' AND action='mark_attended'"
)->fetchColumn();
t_assert_true($logCnt >= 1, 'mark_attended 로그 생성');
toggleAttendance($db, $luluId, '2026-04-30', false, $kelId);
$logCnt2 = (int)$db->query(
    "SELECT COUNT(*) FROM change_logs
      WHERE target_type='training_attendance' AND action='mark_absent'"
)->fetchColumn();
t_assert_true($logCnt2 >= 1, 'mark_absent 로그 생성');
```

- [ ] **Step 6.2: 테스트 실패 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -10
```
Expected: `Failed opening required '...coach_training_attendance.php'`.

- [ ] **Step 6.3: 함수 구현**

Create `public_html/api/coach_training_attendance.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coach_team_guard.php';
require_once __DIR__ . '/../includes/coach_training.php';

/**
 * training_date 검증: YYYY-MM-DD + checkdate + 요일=COACH_TRAINING_DOW.
 * @throws InvalidArgumentException
 */
function validateTrainingDate(string $date): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        throw new InvalidArgumentException('training_date 형식 오류 (YYYY-MM-DD)');
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new InvalidArgumentException('training_date 유효하지 않은 일자');
    }
    $dt = new DateTimeImmutable($date);
    if ((int)$dt->format('N') !== COACH_TRAINING_DOW) {
        throw new InvalidArgumentException('training_date는 교육 요일이어야 합니다');
    }
    return $date;
}

/**
 * 직전 N회 + 그 이전 M회 (총 12회) 출석 이력.
 * recent: 직전 4회 (출석율 분모)
 * earlier: 5~12번째
 * 권한 검증은 caller 책임.
 */
function listAttendanceHistory(PDO $db, int $coachId, DateTimeImmutable $nowKst): array
{
    $totalCount = COACH_TRAINING_RECENT_COUNT; // 4
    $allDates = recentTrainingDates($nowKst, 12); // DESC

    $placeholders = implode(',', array_fill(0, count($allDates), '?'));
    $stmt = $db->prepare("
        SELECT a.training_date, a.marked_at, c.coach_name AS marked_by_name
          FROM coach_training_attendance a
          JOIN coaches c ON c.id = a.marked_by
         WHERE a.coach_id = ?
           AND a.training_date IN ({$placeholders})
    ");
    $stmt->execute(array_merge([$coachId], $allDates));
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byDate[$r['training_date']] = $r;
    }

    $build = [];
    foreach ($allDates as $d) {
        $hit = $byDate[$d] ?? null;
        $build[] = [
            'date'           => $d,
            'attended'       => $hit ? 1 : 0,
            'marked_at'      => $hit['marked_at'] ?? null,
            'marked_by_name' => $hit['marked_by_name'] ?? null,
        ];
    }

    $recent  = array_slice($build, 0, $totalCount);
    $earlier = array_slice($build, $totalCount);
    $attendedCount = 0;
    foreach ($recent as $r) $attendedCount += $r['attended'];

    return [
        'recent'           => $recent,
        'earlier'          => $earlier,
        'attended_count'   => $attendedCount,
        'total_count'      => $totalCount,
        'attendance_rate'  => $totalCount > 0 ? round($attendedCount / $totalCount, 4) : 0.0,
    ];
}

/**
 * 출석 토글 (멱등). row 존재=출석. 권한 검증은 caller 책임.
 *
 * @return bool true if state changed
 * @throws InvalidArgumentException
 */
function toggleAttendance(
    PDO $db, int $coachId, string $trainingDate, bool $attended, int $markedBy
): bool {
    $trainingDate = validateTrainingDate($trainingDate);

    $sel = $db->prepare("
        SELECT id FROM coach_training_attendance
         WHERE coach_id = ? AND training_date = ?
    ");
    $sel->execute([$coachId, $trainingDate]);
    $existing = $sel->fetchColumn();

    if ($attended) {
        if ($existing) return false; // no-op
        try {
            $ins = $db->prepare("
                INSERT INTO coach_training_attendance (coach_id, training_date, marked_by)
                VALUES (?, ?, ?)
            ");
            $ins->execute([$coachId, $trainingDate, $markedBy]);
        } catch (PDOException $e) {
            // 1062 Duplicate entry — race 시 다른 트랜잭션이 INSERT 완료. 멱등 처리.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) return false;
            throw $e;
        }
        $newId = (int)$db->lastInsertId();
        logChange($db, 'training_attendance', $newId, 'mark_attended',
            null,
            ['coach_id' => $coachId, 'training_date' => $trainingDate],
            'coach', $markedBy);
        return true;
    } else {
        if (!$existing) return false; // no-op
        $del = $db->prepare("
            DELETE FROM coach_training_attendance
             WHERE coach_id = ? AND training_date = ?
        ");
        $del->execute([$coachId, $trainingDate]);
        if ($del->rowCount() === 0) return false; // race
        logChange($db, 'training_attendance', (int)$existing, 'mark_absent',
            ['coach_id' => $coachId, 'training_date' => $trainingDate],
            null,
            'coach', $markedBy);
        return true;
    }
}

// 라우터는 Task 7에서 추가
if (PHP_SAPI === 'cli' || defined('COACH_TRAINING_ATTENDANCE_LIB_ONLY')) return;
```

- [ ] **Step 6.4: 테스트 통과 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -25
```
Expected: 출석 toggle/history 신규 t_assert 모두 PASS.

- [ ] **Step 6.5: Commit**

```bash
git -C /root/pt-dev add \
  public_html/api/coach_training_attendance.php \
  tests/team_management_test.php
git -C /root/pt-dev commit -m "feat(team-mgmt): 출석 toggle/history 함수 (멱등 + DOW 검증)"
```

---

## Task 7: `coach_training_attendance.php` — 라우터

**Files:**
- Modify: `public_html/api/coach_training_attendance.php` 끝부분

라우터:
- `GET ?action=history&coach_id=X`: 코치-팀장(본인 팀원만)
- `POST ?action=toggle`: body `{coach_id, training_date, attended}`

- [ ] **Step 7.1: 라우터 추가**

Append to `public_html/api/coach_training_attendance.php`:

```php

header('Content-Type: application/json; charset=utf-8');
$user = requireCoach();
$db   = getDB();
$leaderId = (int)$user['id'];
$action = $_GET['action'] ?? '';

assertIsLeader($db, $leaderId);

switch ($action) {
    case 'history': {
        $coachId = (int)($_GET['coach_id'] ?? 0);
        if (!$coachId) jsonError('coach_id가 필요합니다');
        assertCoachIsMyMember($db, $leaderId, $coachId);

        $nowKst = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
        jsonSuccess(listAttendanceHistory($db, $coachId, $nowKst));
    }

    case 'toggle': {
        $input = getJsonInput();
        $coachId      = (int)($input['coach_id'] ?? 0);
        $trainingDate = (string)($input['training_date'] ?? '');
        $attended     = !empty($input['attended']);
        if (!$coachId) jsonError('coach_id가 필요합니다');

        assertCoachIsMyMember($db, $leaderId, $coachId);

        try {
            $changed = toggleAttendance($db, $coachId, $trainingDate, $attended, $leaderId);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        jsonSuccess(['changed' => $changed, 'attended' => $attended ? 1 : 0]);
    }

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 7.2: Syntax + 회귀 테스트**

Run:
```bash
cd /root/pt-dev && php -l public_html/api/coach_training_attendance.php && php tests/run_tests.php 2>&1 | tail -10
```
Expected: `No syntax errors detected`. 기존 테스트 모두 통과.

- [ ] **Step 7.3: Commit**

```bash
git -C /root/pt-dev add public_html/api/coach_training_attendance.php
git -C /root/pt-dev commit -m "feat(team-mgmt): coach_training_attendance 라우터 (history/toggle)"
```

---

## Task 8: `coach_self.php` — `team_overview` 액션 추가

**Files:**
- Modify: `public_html/api/coach_self.php` (switch에 case 추가)
- Modify: `tests/team_management_test.php` (append)

응답: `{ recent_dates: [...4 DESC], members: [{coach_id,coach_name,korean_name,is_self,attended_count,total_count,attendance_rate,meeting_notes_count}] }`

본인 첫 행 + coach_name ASC. 비-팀장이면 403.

- [ ] **Step 8.1: 실패 테스트 추가 (append)**

Append:

```php
t_section('team_overview 빌드 — 출석율 계산');
// (라우터 자체는 HTTP 의존이므로 함수형 helper를 호출하기 위해 함수만 검증)
// 함수가 없으니 build* helper를 require해야 한다.
require_once __DIR__ . '/../public_html/api/coach_self.php'; // build* helper 정의됨 (LIB_ONLY 가드는 라우터에 있음)

$db = getDB();
$kelId  = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Kel'")->fetchColumn();
$luluId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Lulu'")->fetchColumn();

// 시드 클린업
$db->prepare("DELETE FROM coach_training_attendance WHERE coach_id IN (?, ?)")
   ->execute([$kelId, $luluId]);

$now = new DateTimeImmutable('2026-05-01 09:00:00', new DateTimeZone('Asia/Seoul'));
// Lulu 4회 중 1회 출석
toggleAttendance($db, $luluId, '2026-04-30', true, $kelId);

$ov = buildTeamOverview($db, $kelId, $now);
t_assert_eq(4, count($ov['recent_dates']), 'recent_dates 4개');
t_assert_eq('2026-04-30', $ov['recent_dates'][0], 'recent_dates DESC');

$members = $ov['members'];
t_assert_true(count($members) >= 2, '본인 + 팀원 N>=2');
t_assert_eq(true, (bool)$members[0]['is_self'], '첫 행 본인');
t_assert_eq($kelId, (int)$members[0]['coach_id'], '첫 행 coach_id = 본인');

$lulu = array_values(array_filter($members, fn($m) => (int)$m['coach_id'] === $luluId))[0];
t_assert_eq(1, $lulu['attended_count'], 'Lulu 1회 출석');
t_assert_eq(4, $lulu['total_count'],    '분모 4');
t_assert_eq(0.25, $lulu['attendance_rate'], '출석율 25%');

// 클린업
$db->prepare("DELETE FROM coach_training_attendance WHERE coach_id IN (?, ?)")
   ->execute([$kelId, $luluId]);

t_section('team_overview — 비-팀장 호출 차단 (build 단계)');
t_assert_throws(
    fn() => buildTeamOverview($db, $luluId, $now), // Lulu는 비-팀장
    RuntimeException::class,
    'Lulu는 팀장 아님 → RuntimeException'
);
```

- [ ] **Step 8.2: 테스트 실패 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -10
```
Expected: `Call to undefined function buildTeamOverview()`.

- [ ] **Step 8.3: `coach_self.php`에 `buildTeamOverview` + 라우터 case 추가**

Read `public_html/api/coach_self.php` and modify:

(a) 파일 상단 require 다음에 (LIB_ONLY 패턴이 없으므로 함수 정의는 라우터 위 어디든 둘 수 있다 — 하지만 PT 관행은 라우터 한 파일이라 함수와 case가 같이 있다. 여기서는 buildTeamOverview를 함수로 정의하고 case에서 호출):

기존 파일에서 `switch ($action) {` 직전에 함수를 정의:

```php
require_once __DIR__ . '/../includes/coach_team_guard.php';
require_once __DIR__ . '/../includes/coach_training.php';

/**
 * 팀장의 우리 팀 overview (본인 + 팀원 명단 + 직전 4주 출석율 + 면담 카운트).
 * 권한 검증 포함: 비-팀장이면 RuntimeException.
 */
function buildTeamOverview(PDO $db, int $leaderId, DateTimeImmutable $nowKst): array
{
    if (!coachIsLeader($db, $leaderId)) {
        throw new RuntimeException('팀장 권한이 필요합니다');
    }

    $recentDates = recentTrainingDates($nowKst); // 직전 4개 DESC

    // 멤버 목록 (본인 포함)
    $stmt = $db->prepare("
        SELECT id AS coach_id, coach_name, korean_name
          FROM coaches
         WHERE team_leader_id = ?
         ORDER BY (id = ?) DESC, coach_name ASC
    ");
    $stmt->execute([$leaderId, $leaderId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$members) return ['recent_dates' => $recentDates, 'members' => []];

    $memberIds = array_map(fn($m) => (int)$m['coach_id'], $members);

    // 직전 4주 출석 카운트 (member별)
    $idsPh   = implode(',', array_fill(0, count($memberIds), '?'));
    $datesPh = implode(',', array_fill(0, count($recentDates), '?'));
    $att = $db->prepare("
        SELECT coach_id, COUNT(*) AS cnt
          FROM coach_training_attendance
         WHERE coach_id IN ({$idsPh})
           AND training_date IN ({$datesPh})
         GROUP BY coach_id
    ");
    $att->execute(array_merge($memberIds, $recentDates));
    $attMap = [];
    foreach ($att->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $attMap[(int)$r['coach_id']] = (int)$r['cnt'];
    }

    // 면담 카운트 (member별, 전체)
    $note = $db->prepare("
        SELECT coach_id, COUNT(*) AS cnt
          FROM coach_meeting_notes
         WHERE coach_id IN ({$idsPh})
         GROUP BY coach_id
    ");
    $note->execute($memberIds);
    $noteMap = [];
    foreach ($note->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $noteMap[(int)$r['coach_id']] = (int)$r['cnt'];
    }

    $total = COACH_TRAINING_RECENT_COUNT;
    foreach ($members as &$m) {
        $cid = (int)$m['coach_id'];
        $attended = $attMap[$cid] ?? 0;
        $m['is_self']             = $cid === $leaderId;
        $m['attended_count']      = $attended;
        $m['total_count']         = $total;
        $m['attendance_rate']     = $total > 0 ? round($attended / $total, 4) : 0.0;
        $m['meeting_notes_count'] = $noteMap[$cid] ?? 0;
    }
    unset($m);

    return ['recent_dates' => $recentDates, 'members' => $members];
}
```

그리고 기존 `switch ($action) {` 안의 `case 'get_info':` 뒤(default 앞)에 case 추가:

```php
    case 'team_overview': {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
        try {
            jsonSuccess(buildTeamOverview($db, $coachId, $now));
        } catch (RuntimeException $e) {
            jsonError($e->getMessage(), 403);
        }
    }
```

(코드 위치: 기존 case 'get_info' 의 `jsonSuccess($payload);` 다음 줄 — switch case는 break 없이 jsonSuccess가 exit 시킨다.)

- [ ] **Step 8.4: 테스트 통과 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -25
```
Expected: team_overview 신규 t_assert 모두 PASS.

- [ ] **Step 8.5: Commit**

```bash
git -C /root/pt-dev add \
  public_html/api/coach_self.php \
  tests/team_management_test.php
git -C /root/pt-dev commit -m "feat(team-mgmt): coach_self.php team_overview 액션 + buildTeamOverview"
```

---

## Task 9: 코치 SPA — 사이드바 메뉴 + 스크립트 등록 (PHP 가드)

**Files:**
- Modify: `public_html/coach/index.php`

비-팀장에게는 `<a>` 자체를 출력하지 않는다.

- [ ] **Step 9.1: PHP 가드 + 사이드바 + 스크립트 추가**

Edit `public_html/coach/index.php`:

(a) 상단 `$isLoggedIn = $user && $user['role'] === 'coach';` 직후에 팀장 여부 조회를 추가:

```php
$isLoggedIn = $user && $user['role'] === 'coach';
$isLeader = false;
if ($isLoggedIn) {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/coach_team_guard.php';
    $isLeader = coachIsLeader(getDB(), (int)$user['id']);
}
```

(b) 사이드바의 `<a href="#kakao-check"...>` 다음 줄에 조건부 메뉴 삽입:

```php
      <a href="#kakao-check" data-page="kakao-check">카톡방 입장 체크</a>
<?php if ($isLeader): ?>
      <a href="#team" data-page="team">팀원 관리</a>
<?php endif; ?>
      <a href="#my-info" data-page="my-info">내 정보</a>
```

(c) 스크립트 등록 — `<script src="/coach/js/pages/my-info.js"></script>` 다음 줄에:

```html
<?php if ($isLeader): ?>
<script src="/coach/js/pages/team-management.js"></script>
<?php endif; ?>
```

- [ ] **Step 9.2: 매뉴얼 검증 (브라우저)**

`https://dev-pt.soritune.com/coach/`로 접속:
- Lulu (kel팀 팀원) 로그인 → 사이드바에 "팀원 관리" 없음
- Kel (팀장) 로그인 → "팀원 관리" 노출

Expected: 위 두 행동 일치. 페이지 자체는 아직 모듈 없으므로 클릭 시 404 같은 empty-state. 그건 Task 10에서 채움.

- [ ] **Step 9.3: Commit**

```bash
git -C /root/pt-dev add public_html/coach/index.php
git -C /root/pt-dev commit -m "feat(team-mgmt): coach 사이드바 '팀원 관리' 메뉴 (팀장 전용 PHP 가드)"
```

---

## Task 10: `team-management.js` — 명단 페이지 (`#team`)

**Files:**
- Create: `public_html/coach/js/pages/team-management.js`

페이지 모듈 구조: `CoachApp.registerPage('team', { render(params) { ... } })`. `params[0]`이 있으면 상세 모드, 없으면 명단 모드.

이 task에서는 명단(파라미터 없는 케이스)만 구현하고, 상세 모드는 Task 11에서.

- [ ] **Step 10.1: 명단 페이지 구현**

Create `public_html/coach/js/pages/team-management.js`:

```js
/**
 * 코치 SPA "팀원 관리" 페이지
 * 라우트:
 *   #team           — 우리 팀원 명단 (팀장 본인 포함)
 *   #team/<coachId> — 팀원 상세 (Task 11)
 *
 * 권한: 팀장 전용. 라우터는 PHP 가드(coach/index.php)로 메뉴 자체가 비-팀장에게 출력되지 않으며,
 * 서버 API(coach_self.php?action=team_overview)도 비-팀장은 403.
 */
CoachApp.registerPage('team', {
  async render(params) {
    if (params && params.length && params[0]) {
      return this.renderDetail(parseInt(params[0], 10));
    }
    return this.renderList();
  },

  async renderList() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">팀원 관리</h1></div>
      <div id="teamListContent"><div class="loading">불러오는 중...</div></div>
    `;
    const res = await API.get('/api/coach_self.php?action=team_overview');
    if (!res.ok) {
      document.getElementById('teamListContent').innerHTML =
        `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const { members } = res.data;
    if (!members.length) {
      document.getElementById('teamListContent').innerHTML =
        `<div class="empty-state">아직 팀원이 없습니다</div>`;
      return;
    }

    const rows = members.map(m => {
      const pct = Math.round((m.attendance_rate || 0) * 100);
      const bar = this.attendanceBar(m.attended_count, m.total_count);
      const noteCol = m.meeting_notes_count > 0 ? m.meeting_notes_count : '-';
      const star = m.is_self ? ' <span style="color:var(--accent,#FF5E00)">★</span>' : '';
      return `
        <tr style="cursor:pointer" onclick="location.hash='#team/${m.coach_id}'">
          <td>${UI.esc(m.coach_name)}${star}</td>
          <td>${UI.esc(m.korean_name || '-')}</td>
          <td>${bar} ${m.attended_count}/${m.total_count} ${pct}%</td>
          <td>${noteCol}</td>
        </tr>
      `;
    }).join('');

    document.getElementById('teamListContent').innerHTML = `
      <div class="card" style="padding:0">
        <div class="data-table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>이름</th>
                <th>한글이름</th>
                <th style="min-width:160px">직전 4주 출석</th>
                <th>면담</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>
      <div style="margin-top:8px;color:var(--text-secondary);font-size:12px">
        ★ = 본인(팀장) · 행 클릭 → 상세
      </div>
    `;
  },

  /**
   * 4-슬롯 출석 막대. 인라인 SVG/문자가 아닌 div로 단순 표시.
   * 색: 4=success, 3=lime, 2=amber, 0~1=red.
   */
  attendanceBar(attended, total) {
    const ratio = total > 0 ? attended / total : 0;
    let color = '#7c7c7c';
    if (ratio >= 1) color = '#1ed760';
    else if (ratio >= 0.75) color = '#a3e635';
    else if (ratio >= 0.5) color = '#ffa42b';
    else if (ratio > 0) color = '#f3727f';
    else color = '#4d4d4d';

    const cells = [];
    for (let i = 0; i < total; i++) {
      const filled = i < attended;
      cells.push(`<span style="display:inline-block;width:12px;height:8px;margin-right:2px;background:${filled ? color : '#3a3a3a'};border-radius:2px"></span>`);
    }
    return `<span style="display:inline-block;vertical-align:middle">${cells.join('')}</span>`;
  },

  // Task 11에서 구현
  renderDetail(coachId) {
    document.getElementById('pageContent').innerHTML =
      `<div class="empty-state">상세 페이지 (구현 예정) — coach_id=${coachId}</div>`;
  },
});
```

- [ ] **Step 10.2: 매뉴얼 검증**

`https://dev-pt.soritune.com/coach/`에서 Kel(팀장) 로그인 → 사이드바 "팀원 관리" 클릭:
- 명단 표 11명(본인 + 팀원 10) 노출
- 출석율 컬럼 모두 0/4 (시드 데이터 없음)
- 면담 컬럼 모두 `-`
- 본인 행에 ★
- 본인 첫 행, 나머지 coach_name ASC

Expected: 정상 노출.

- [ ] **Step 10.3: Commit**

```bash
git -C /root/pt-dev add public_html/coach/js/pages/team-management.js
git -C /root/pt-dev commit -m "feat(team-mgmt): #team 명단 페이지 (출석율 컬럼 + 면담 카운트)"
```

---

## Task 11: 상세 페이지 — `#team/<id>` 면담 탭 (read)

**Files:**
- Modify: `public_html/coach/js/pages/team-management.js` (`renderDetail` + 보조 메서드)

이 task에서는 상세 페이지 셸 + 탭 전환 + **면담 read** 까지. 면담 작성/수정/삭제는 Task 12, 출석 탭은 Task 13.

- [ ] **Step 11.1: `renderDetail` + 면담 read 구현**

Edit `public_html/coach/js/pages/team-management.js` — `renderDetail` 메서드를 다음으로 교체하고, 새 메서드들을 같은 객체에 추가:

```js
  async renderDetail(coachId) {
    // shell
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header" style="display:flex;align-items:center;gap:12px">
        <a href="#team" style="color:var(--text-secondary);text-decoration:none">← 팀원 관리</a>
        <h1 class="page-title" id="teamDetailTitle">불러오는 중...</h1>
      </div>
      <div style="display:flex;gap:8px;margin-bottom:16px">
        <button class="btn btn-small" id="tabNotes">면담 기록</button>
        <button class="btn btn-small btn-outline" id="tabAttendance">코치 교육 출석</button>
      </div>
      <div id="teamDetailBody"><div class="loading">불러오는 중...</div></div>
    `;
    this._currentCoachId = coachId;
    this._activeTab = 'notes';

    // 코치 메타 (팀 overview 재사용)
    const ov = await API.get('/api/coach_self.php?action=team_overview');
    if (!ov.ok) {
      document.getElementById('teamDetailBody').innerHTML =
        `<div class="empty-state">${UI.esc(ov.message || '오류')}</div>`;
      return;
    }
    const m = ov.data.members.find(x => (x.coach_id|0) === (coachId|0));
    if (!m) {
      document.getElementById('teamDetailBody').innerHTML =
        `<div class="empty-state">팀원이 아닙니다</div>`;
      return;
    }
    document.getElementById('teamDetailTitle').textContent =
      `${m.coach_name}${m.korean_name ? ` (${m.korean_name})` : ''}`;

    document.getElementById('tabNotes').onclick      = () => this.switchTab('notes');
    document.getElementById('tabAttendance').onclick = () => this.switchTab('attendance');

    await this.renderNotesTab();
  },

  switchTab(tab) {
    this._activeTab = tab;
    const notesBtn = document.getElementById('tabNotes');
    const attBtn   = document.getElementById('tabAttendance');
    notesBtn.classList.toggle('btn-outline', tab !== 'notes');
    attBtn.classList.toggle('btn-outline',   tab !== 'attendance');
    if (tab === 'notes') this.renderNotesTab();
    else this.renderAttendanceTab();
  },

  async renderNotesTab() {
    const coachId = this._currentCoachId;
    const body = document.getElementById('teamDetailBody');
    body.innerHTML = `<div class="loading">불러오는 중...</div>`;

    const res = await API.get(`/api/coach_meeting_notes.php?action=list&coach_id=${coachId}`);
    if (!res.ok) {
      body.innerHTML = `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const notes = res.data.notes;
    const cards = notes.length === 0
      ? `<div class="empty-state">면담 기록 없음</div>`
      : notes.map(n => this.renderNoteCard(n)).join('');

    body.innerHTML = `
      <div style="margin-bottom:12px">
        <button class="btn btn-primary" id="newNoteBtn">+ 새 면담 기록</button>
      </div>
      <div id="notesList">${cards}</div>
    `;
    document.getElementById('newNoteBtn').onclick = () => this.openNoteModal(null);
  },

  renderNoteCard(n) {
    const editBtns = n.can_edit
      ? `<button class="btn btn-small" data-act="edit" data-id="${n.id}">수정</button>
         <button class="btn btn-small btn-outline" data-act="del" data-id="${n.id}">삭제</button>`
      : '';
    const author = n.can_edit
      ? ''
      : `<span style="color:var(--text-secondary);margin-left:8px">by ${UI.esc(n.created_by_name)}</span>`;
    return `
      <div class="card" style="padding:14px;margin-bottom:10px"
           data-note-id="${n.id}" data-meeting-date="${UI.esc(n.meeting_date)}">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div><strong>${UI.esc(n.meeting_date)}</strong>${author}</div>
          <div style="display:flex;gap:6px" onclick="CoachApp.pages.team.handleNoteAction(event)">
            ${editBtns}
          </div>
        </div>
        <div style="white-space:pre-wrap;font-size:14px">${UI.esc(n.notes)}</div>
      </div>
    `;
  },

  // Task 12에서 채움
  openNoteModal(note) { alert('TODO Task 12'); },
  handleNoteAction(ev) { ev.stopPropagation(); /* TODO Task 12 */ },

  // Task 13에서 채움
  async renderAttendanceTab() {
    document.getElementById('teamDetailBody').innerHTML =
      `<div class="empty-state">출석 탭 (구현 예정)</div>`;
  },
```

- [ ] **Step 11.2: 매뉴얼 검증**

Kel 로그인 → "팀원 관리" → Lulu 행 클릭:
- 상단 breadcrumb "← 팀원 관리"
- 제목 "Lulu (룰루)"
- [면담 기록][코치 교육 출석] 탭 버튼
- 빈 상태 "면담 기록 없음" + [+ 새 면담 기록] 버튼
- breadcrumb 클릭 → `#team` 명단 복귀

Expected: 위와 일치. 작성 모달은 alert 'TODO Task 12'로 떨어짐.

- [ ] **Step 11.3: Commit**

```bash
git -C /root/pt-dev add public_html/coach/js/pages/team-management.js
git -C /root/pt-dev commit -m "feat(team-mgmt): #team/<id> 상세 셸 + 면담 read 탭"
```

---

## Task 12: 면담 작성/수정/삭제 모달

**Files:**
- Modify: `public_html/coach/js/pages/team-management.js`

`openNoteModal`과 `handleNoteAction`을 채운다.

- [ ] **Step 12.1: 모달 + CRUD 구현**

Edit `public_html/coach/js/pages/team-management.js` — Task 11에서 stub으로 둔 두 메서드를 다음으로 교체:

```js
  openNoteModal(note) {
    const today = new Date();
    const kst = new Date(today.getTime() + 9*60*60*1000);
    const todayStr = kst.toISOString().slice(0,10);

    const date  = note ? note.meeting_date : todayStr;
    const body  = note ? note.notes : '';
    const isEdit = !!note;

    const overlay = UI.showModal(`
      <h2 style="font-size:18px;margin-bottom:12px">${isEdit ? '면담 기록 수정' : '새 면담 기록'}</h2>
      <div class="form-group">
        <label class="form-label">면담 일자</label>
        <input type="date" class="form-input" id="nmDate" value="${UI.esc(date)}">
      </div>
      <div class="form-group">
        <label class="form-label">메모</label>
        <textarea class="form-input" id="nmNotes" rows="8"
                  placeholder="면담 내용을 자유롭게 입력하세요">${UI.esc(body)}</textarea>
      </div>
      <div id="nmErr" class="login-error" style="display:none"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button class="btn btn-outline" id="nmCancel">취소</button>
        <button class="btn btn-primary" id="nmSave">${isEdit ? '저장' : '작성'}</button>
      </div>
    `);
    document.getElementById('nmCancel').onclick = () => UI.closeModal();
    document.getElementById('nmSave').onclick = async () => {
      const dateVal = document.getElementById('nmDate').value;
      const notesVal = document.getElementById('nmNotes').value;
      const err = document.getElementById('nmErr');
      err.style.display = 'none';

      if (!dateVal) { err.textContent='일자를 선택하세요'; err.style.display='block'; return; }
      if (!notesVal.trim()) { err.textContent='메모를 입력하세요'; err.style.display='block'; return; }

      let res;
      if (isEdit) {
        res = await API.post(
          `/api/coach_meeting_notes.php?action=update&id=${note.id}`,
          { meeting_date: dateVal, notes: notesVal }
        );
      } else {
        res = await API.post(
          `/api/coach_meeting_notes.php?action=create`,
          { coach_id: this._currentCoachId, meeting_date: dateVal, notes: notesVal }
        );
      }
      if (!res.ok) {
        err.textContent = res.message || '저장 실패';
        err.style.display = 'block';
        return;
      }
      UI.closeModal();
      await this.renderNotesTab();
    };
  },

  async handleNoteAction(ev) {
    ev.stopPropagation();
    const btn = ev.target.closest('button[data-act]');
    if (!btn) return;
    const id = parseInt(btn.dataset.id, 10);
    const act = btn.dataset.act;

    if (act === 'edit') {
      // 카드에서 현재 값 추출
      const card = btn.closest('[data-note-id]');
      const meetingDate = card.dataset.meetingDate;
      const notesText = card.querySelector('div[style*="pre-wrap"]').textContent;
      this.openNoteModal({ id, meeting_date: meetingDate, notes: notesText });
    } else if (act === 'del') {
      if (!UI.confirm('이 면담 기록을 삭제할까요?')) return;
      const res = await API.post(`/api/coach_meeting_notes.php?action=delete&id=${id}`);
      if (!res.ok) { alert(res.message || '삭제 실패'); return; }
      await this.renderNotesTab();
    }
  },
```

- [ ] **Step 12.2: 매뉴얼 검증**

Kel 로그인 → "팀원 관리" → Lulu 클릭:
1. [+ 새 면담 기록] → 모달 열림. 일자=오늘, 메모 입력 → [작성] → 카드 추가됨. 명단 복귀 시 면담 카운트 +1.
2. 카드 [수정] → 모달, 일자/메모 변경 → [저장] → 본문 변경 반영.
3. 카드 [삭제] → confirm → 카드 사라짐.
4. 빈 메모로 [작성] → 인라인 에러 "메모를 입력하세요".

Expected: 모두 일치.

- [ ] **Step 12.3: Commit**

```bash
git -C /root/pt-dev add public_html/coach/js/pages/team-management.js
git -C /root/pt-dev commit -m "feat(team-mgmt): 면담 작성/수정/삭제 모달"
```

---

## Task 13: 출석 탭 (낙관적 토글)

**Files:**
- Modify: `public_html/coach/js/pages/team-management.js`

`renderAttendanceTab`을 채운다.

- [ ] **Step 13.1: 출석 탭 구현**

Edit `public_html/coach/js/pages/team-management.js` — Task 11의 stub `renderAttendanceTab`을 다음으로 교체하고 보조 메서드 추가:

```js
  async renderAttendanceTab() {
    const coachId = this._currentCoachId;
    const body = document.getElementById('teamDetailBody');
    body.innerHTML = `<div class="loading">불러오는 중...</div>`;

    const res = await API.get(`/api/coach_training_attendance.php?action=history&coach_id=${coachId}`);
    if (!res.ok) {
      body.innerHTML = `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const { recent, earlier, attended_count, total_count, attendance_rate } = res.data;
    const pct = Math.round((attendance_rate || 0) * 100);

    body.innerHTML = `
      <div class="card" style="padding:16px;margin-bottom:16px">
        <div style="font-size:14px;color:var(--text-secondary);margin-bottom:6px">직전 4주</div>
        <div style="font-size:24px;font-weight:700">${attended_count}/${total_count} (${pct}%)</div>
      </div>
      <div class="card" style="padding:0;margin-bottom:12px">
        <div style="padding:8px 14px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--border,#4d4d4d)">분모 회차</div>
        <div id="attRecentList">${recent.map(r => this.renderAttendanceRow(r, true)).join('')}</div>
      </div>
      <div class="card" style="padding:0">
        <div style="padding:8px 14px;font-size:12px;color:var(--text-secondary);border-bottom:1px solid var(--border,#4d4d4d)">더 이전 회차</div>
        <div id="attEarlierList">${earlier.map(r => this.renderAttendanceRow(r, false)).join('')}</div>
      </div>
    `;
    body.querySelectorAll('[data-att-toggle]').forEach(cb => {
      cb.addEventListener('change', e => this.handleAttendanceToggle(e));
    });
  },

  renderAttendanceRow(row, isRecent) {
    const checked = row.attended ? 'checked' : '';
    const labelStyle = row.attended ? '' : 'color:var(--text-secondary)';
    return `
      <label style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border,#2a2a2a);cursor:pointer">
        <span style="${labelStyle}">${UI.esc(row.date)} (목)</span>
        <span>
          <input type="checkbox" data-att-toggle data-date="${UI.esc(row.date)}" ${checked}>
          <span style="margin-left:6px;${labelStyle}">${row.attended ? '출석' : '결석'}</span>
        </span>
      </label>
    `;
  },

  async handleAttendanceToggle(ev) {
    const cb = ev.target;
    const date = cb.dataset.date;
    const wantAttended = cb.checked;
    cb.disabled = true;

    const res = await API.post('/api/coach_training_attendance.php?action=toggle', {
      coach_id: this._currentCoachId,
      training_date: date,
      attended: wantAttended ? 1 : 0,
    });
    cb.disabled = false;
    if (!res.ok) {
      alert(res.message || '실패');
      cb.checked = !wantAttended; // rollback
      return;
    }
    // 헤드라인 + 라벨 갱신은 전체 다시 그리기로 단순화
    await this.renderAttendanceTab();
  },
```

- [ ] **Step 13.2: 매뉴얼 검증**

Kel 로그인 → "팀원 관리" → Lulu → [코치 교육 출석] 탭:
1. 직전 4회 목요일 노출 (`recent_dates` DESC), 출석율 0/4 (0%)
2. 가장 최근 목요일 체크 → 즉시 "출석" 라벨로 변경, 헤드라인 1/4 (25%) 갱신
3. 같은 체크박스 다시 클릭 → 0/4 (0%)로 복귀
4. "더 이전 회차" 8개도 표시되며 토글 가능
5. "팀원 관리" 명단 복귀 → Lulu 출석율 컬럼 갱신
6. 화요일 일자로 토글 시도 (DOM 조작) → "training_date는 교육 요일이어야 합니다" 에러 + 체크 롤백

Expected: 위와 일치.

- [ ] **Step 13.3: Commit**

```bash
git -C /root/pt-dev add public_html/coach/js/pages/team-management.js
git -C /root/pt-dev commit -m "feat(team-mgmt): 출석 탭 (직전 4 + 더 이전 8, 낙관적 토글)"
```

---

## Task 14: 어드민 코치 페이지 — [면담] 버튼 + read 모달

**Files:**
- Modify: `public_html/admin/js/pages/coaches.js`

기존 코치 표 액션 컬럼에 [면담] 버튼 추가 → 클릭 시 read-only 모달.

- [ ] **Step 14.1: 기존 표 구조 확인**

Read:
```bash
cd /root/pt-dev && grep -n "data-act\|액션\|action" public_html/admin/js/pages/coaches.js | head -20
```

목적: 기존 액션 버튼이 어떻게 렌더되는지 확인. 결과를 바탕으로 다음 step에서 [면담] 버튼을 그 옆에 끼워넣는다.

- [ ] **Step 14.2: [면담] 버튼 + 모달 추가**

`coaches.js`의 액션 컬럼 렌더링 부분(보통 `<td>` 안의 버튼 모음)에 [면담] 버튼을 추가하고, 같은 객체에 새 메서드 `openMeetingNotesModal(coachId, coachName)`을 추가한다.

표 행 렌더링에서 액션 컬럼에 다음 버튼 추가 (해당 위치는 Step 14.1 결과로 결정):

```js
            <button class="btn btn-small" onclick="AdminApp.pages.coaches.openMeetingNotesModal(${c.id}, '${UI.esc(c.coach_name).replace(/'/g, "\\'")}')">면담</button>
```

(에스케이프 안전: `coach_name`은 한글/영문/숫자/공백만 — 하지만 안전을 위해 single quote escape 명시.)

같은 객체에 추가:

```js
  async openMeetingNotesModal(coachId, coachName) {
    const overlay = UI.showModal(`
      <h2 style="font-size:18px;margin-bottom:12px">${UI.esc(coachName)} — 면담 기록</h2>
      <div id="amnBody"><div class="loading">불러오는 중...</div></div>
      <div style="display:flex;justify-content:flex-end;margin-top:12px">
        <button class="btn btn-outline" onclick="UI.closeModal()">닫기</button>
      </div>
    `);

    const res = await API.get(`/api/coach_meeting_notes.php?action=list&coach_id=${coachId}`);
    const body = document.getElementById('amnBody');
    if (!res.ok) {
      body.innerHTML = `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const notes = res.data.notes;
    if (!notes.length) {
      body.innerHTML = `<div class="empty-state">면담 기록 없음</div>`;
      return;
    }
    body.innerHTML = notes.map(n => `
      <div class="card" style="padding:14px;margin-bottom:10px">
        <div style="margin-bottom:8px">
          <strong>${UI.esc(n.meeting_date)}</strong>
          <span style="color:var(--text-secondary);margin-left:8px">by ${UI.esc(n.created_by_name)}</span>
        </div>
        <div style="white-space:pre-wrap;font-size:14px">${UI.esc(n.notes)}</div>
      </div>
    `).join('');
  },
```

- [ ] **Step 14.3: 매뉴얼 검증**

`https://dev-pt.soritune.com/admin/`에서 admin 로그인 → `#coaches`:
1. Lulu 행 [면담] → 모달 오픈, 카드 0개 또는 직전 task에서 작성한 카드 표시
2. 모달 카드에 작성자 이름 부기 ("by Kel"), 수정/삭제 버튼 없음
3. [닫기] → 모달 닫힘

Expected: 위와 일치.

- [ ] **Step 14.4: Commit**

```bash
git -C /root/pt-dev add public_html/admin/js/pages/coaches.js
git -C /root/pt-dev commit -m "feat(team-mgmt): admin 코치 페이지 [면담] read-only 모달"
```

---

## Task 15: 권한 통합 매뉴얼 검증 (DEV)

자동 테스트로 커버하지 못하는 권한 흐름을 매뉴얼로 확인. 각 시나리오 통과를 체크박스로 기록.

**환경:** `https://dev-pt.soritune.com`

- [ ] **시나리오 1: 비-팀장(Lulu) 메뉴 차단**
  - Lulu 로그인 → 사이드바에 "팀원 관리" **없음**
  - URL `https://dev-pt.soritune.com/coach/#team` 직접 입력 → "페이지를 찾을 수 없습니다" 또는 (모듈이 없으니) empty
  - URL `/api/coach_self.php?action=team_overview` 직접 GET → JSON `{ok:false, message:"팀장 권한이 필요합니다"}` 403

- [ ] **시나리오 2: 다른 팀장(Nana) 차단**
  - Nana 로그인 → 자기 팀(Hyun, Raina, ...)만 명단에 보임. Kel/Lulu 안 보임.
  - URL `#team/<lulu_id>` 직접 입력 → "팀원이 아닙니다" empty-state
  - `/api/coach_meeting_notes.php?action=list&coach_id=<lulu_id>` GET → 403

- [ ] **시나리오 3: 다른 팀장 면담 수정 차단**
  - Kel 로그인 → Lulu 면담 1건 작성 (id 메모)
  - Nana 로그인 → DevTools에서 `fetch('/api/coach_meeting_notes.php?action=update&id=<id>', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({meeting_date:'2026-05-01',notes:'탈취'})})` → 403
  - DB 본문 변경 없음

- [ ] **시나리오 4: 어드민 read-only**
  - 어드민 로그인 → `#coaches` Lulu [면담] → 시나리오 3에서 Kel이 쓴 카드 표시
  - 카드에 [수정][삭제] 버튼 **없음** (`can_edit=false`)
  - DevTools에서 `fetch('/api/coach_meeting_notes.php?action=delete&id=<id>', {method:'POST'})` → 403 ("코치만 가능합니다")

- [ ] **시나리오 5: 출석 토글 멱등 + 명단 갱신**
  - Kel → Lulu 상세 → [코치 교육 출석] → 가장 최근 목요일 체크 (1/4)
  - 같은 체크박스 다시 클릭 → 0/4
  - 다시 체크 → 1/4
  - "팀원 관리" 명단 복귀 → Lulu 행 출석율 1/4 (25%) 컬럼 갱신

- [ ] **시나리오 6: 면담 카운트 갱신**
  - 시나리오 3에서 작성된 면담이 명단 행 "면담" 컬럼에 1로 표시
  - 면담 삭제 → "-"로 변경

- [ ] **시나리오 7: PT 다크 테마 가독성**
  - 직접 4주 출석 막대 색상 명단에서 정상 보임
  - 어드민 모달 배경/텍스트 가독성 정상 (PT 다크 var 사용)

- [ ] **Step 15.1: 모든 시나리오 통과 확인 시 진행 (실패 시 fix-up commit 후 재검증)**

---

## Task 16: 자동 테스트 최종 PASS + dev push

- [ ] **Step 16.1: 전체 테스트 통과 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -25
```
Expected: 마지막 줄 `passed=NN failed=0`. 60+ assertion 신규 + 기존 테스트 모두 PASS.

- [ ] **Step 16.2: dev push**

Run:
```bash
git -C /root/pt-dev push origin dev
```

- [ ] **Step 16.3: 사용자에게 dev 검증 요청 + 운영 반영 대기**

다음 메시지를 사용자에게 전달:

```
DEV 적용 + 자동 테스트 + 매뉴얼 검증 모두 통과했습니다.

DEV: https://dev-pt.soritune.com/coach/  (Kel/Nana/Flora 로 검증 가능)
DEV 어드민: https://dev-pt.soritune.com/admin/

운영 반영하시려면 별도 요청 부탁드립니다.
```

⛔ **여기서 멈추고 사용자의 명시적 운영 반영 요청을 기다린다.**
PROD 단계는 별도 (이 plan 외)로 진행:
1. junior-dev에서 main merge dev → push origin main
2. pt-prod에서 git pull origin main
3. PROD DB에 두 마이그 적용

---

## Self-review 노트

이 plan을 작성한 후 spec 대조:

- [x] §3.1/3.2 두 테이블 — Task 1
- [x] §3.3 가상 회차 함수 — Task 2
- [x] §4.1 LIB_ONLY 가드 패턴 — Task 4/6 함수 분리, Task 5/7 라우터 분리
- [x] §4.2 team_overview 응답 형식 — Task 8
- [x] §4.2 면담 list/create/update/delete + can_edit — Task 4/5
- [x] §4.2 출석 history (recent 4 + earlier 8) + toggle — Task 6/7
- [x] §4.3 권한 행렬 — Task 5/7 라우터에서 가드 호출, Task 15 매뉴얼 시나리오로 검증
- [x] §4.4 검증 규칙 (notes 50000자, training_date DOW) — Task 4/6 함수에 명시
- [x] §4.5 가드 시그니처 (`assertIsLeader`/`assertCoachIsMyMember`) — Task 3 (+ 보조 `coachIsLeader`/`coachIsMyMember` boolean returner)
- [x] §4.6 race-free WHERE id=? AND created_by=? — Task 4 update/delete
- [x] §4.6 1062 처리 — Task 6 toggleAttendance
- [x] §5.1 사이드바 PHP 가드 — Task 9
- [x] §5.2 명단 페이지 (출석 막대 + 면담 컬럼 + ★ 본인) — Task 10
- [x] §5.3 상세 페이지 (탭 + 면담 카드 + 출석 행) — Task 11/12/13
- [x] §5.4 어드민 모달 (read-only, by 작성자) — Task 14
- [x] §5.6 XSS 가드 (UI.esc + pre-wrap) — Task 11/12/13/14에 모두 적용
- [x] §6.1 자동 테스트 60+ assertion 목표 — Task 2/3/4/6/8 누적
- [x] §6.1 logChange 검증 (메타만 저장) — Task 4 (`coach_meeting_notes`) + Task 6 (`coach_training_attendance`)
- [x] §6.2 매뉴얼 시나리오 — Task 15
- [x] §7.1 DEV 적용 순서 — Task 1, 16

빈 자리 / 모호함 점검:
- "directory of changes" check: 모든 파일 절대경로 명시.
- 메서드 이름 일관성: `coachIsLeader` / `coachIsMyMember` (boolean returner) + `assertIsLeader` / `assertCoachIsMyMember` (가드). spec §4.5는 assert만 명시했으나 테스트 가능성 위해 boolean returner도 export. 두 명칭 일관.
- `buildTeamOverview`는 spec에 직접 등장하지 않으나 §4.2 team_overview 액션 응답을 만들기 위한 내부 함수. 함수형 테스트 가능성 위해 export.

---

## Task 17: 명단 일괄 출석 체크 (일자별 컬럼 + inline toggle)

> **완료일**: 2026-05-04

**변경 요지**: 팀장이 팀원 명단에서 직접 모든 팀원의 직전 4 일자 출석을 토글할 수 있도록 `#team` 명단 표를 재설계. 기존 "직전 4주 출석" 단일 컬럼(시각 막대 + `n/4 NN%`)을 제거하고, 일자별 4개 체크박스 컬럼으로 분해. 상세 페이지의 출석 탭은 그대로 유지.

**영향받은 파일**:
- `public_html/api/coach_self.php` — `buildTeamOverview`: 출석 집계 쿼리를 row별(coachId×date)로 변경 + 각 멤버에 `attendance: [{date, attended}, ...4 entries DESC]` 배열 추가. `attMap`(COUNT) → `attBy`([coachId][date]=1) 로 교체.
- `public_html/coach/js/pages/team-management.js` — `renderList()` 전면 교체: `recent_dates` 헤더 4컬럼, 체크박스 셀, `handleBulkToggle()` 신규 메서드. `attendanceBar()` 제거(dead code).
- `tests/team_management_test.php` — `team_overview — members[].attendance 배열` 섹션 5 assertion 추가.
- `docs/superpowers/specs/2026-05-04-team-management-design.md` — §4.2 응답 예시에 `attendance` 배열 추가, §5.2 화면 1 표 구조 갱신(일자별 체크박스 + 클릭 분리 정책).

**회귀 테스트**: 219 PASS / 0 FAIL (기존 214 + 신규 5).
- Task 14 Step 14.1은 "기존 코드 확인" 단계로 다음 step의 정확한 삽입 위치를 결정. plan에 명시적 라인 번호를 못 박은 이유: `coaches.js`가 919 lines (2026-04-30 PROD)에서 변동 가능. 실행 시 `grep -n` 으로 위치를 확인하는 절차로 plan에 명시.

> **Task 17 → Task 18 전환 이유**: 일자별 4 컬럼 매트릭스는 컬럼 헤더가 매주 바뀌어 인지 부담 발생. 출석 입력을 별도 `#training-attendance` 페이지로 분리하고, 명단은 요약 모드로 복귀.

---

## Task 18: 코치 교육 출석 별도 페이지 + 명단 요약 복귀

> **완료일**: 2026-05-04

**변경 요지**:
- 사이드바에 "코치 교육 출석" 메뉴 추가 (팀장 전용 PHP 가드)
- 신규 `#training-attendance` 페이지: 회차 드롭다운(직전 4회, 가장 최근 default) + 팀원 한 줄씩 + 체크박스 1개. 체크 즉시 `toggle` API, 헤드라인 `출석 N/M명` 실시간 반영.
- `#team` 명단: 일자별 4 컬럼 매트릭스 제거 → 요약 모드 복귀 (출석율 % 막대 + 면담 카운트). `attendanceBar()` 부활, `handleBulkToggle()` 제거.
- Backend 변경 없음: `team_overview` 응답의 `attendance` 배열 그대로 재사용.

**영향받은 파일**:
- `public_html/coach/index.php` — 사이드바에 `#training-attendance` 메뉴 추가 + `training-attendance.js` 스크립트 등록 (둘 다 `$isLeader` 블록 내)
- `public_html/coach/js/pages/team-management.js` — `renderList()` 요약 모드 복귀: `attendanceBar()` 부활, 4 컬럼 체크박스 제거, `handleBulkToggle()` 제거
- `public_html/coach/js/pages/training-attendance.js` — 신규 파일: `CoachApp.registerPage('training-attendance', {...})`
- `docs/superpowers/specs/2026-05-04-team-management-design.md` — §5.1 사이드바 갱신, §5.2 요약 모드 복귀 + 매트릭스 제거 명시, §5.7 신설 (`#training-attendance` 페이지 명세)
- `docs/superpowers/plans/2026-05-04-team-management.md` — Task 18 섹션 추가

**회귀 테스트**: 219 PASS / 0 FAIL (backend 변경 없어 테스트 카운트 유지).
