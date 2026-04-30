# 코치 팀/카톡방 정보 추가 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PT 코치 테이블에 팀(self-FK) + 1:1 카톡방 링크 컬럼을 추가하고, 어드민 편집 UI와 코치 본인용 "내 정보" 페이지를 구현한다.

**Architecture:** `coaches.team_leader_id`(self-FK NULL) + `coaches.kakao_room_url`(NULL) 두 컬럼을 추가. 팀장은 `team_leader_id = self.id`로 표현하므로 별도 `teams` 테이블 불필요. 검증/카운트/cascade-차단 로직은 `includes/coach_team.php`에 순수 함수로 분리하여 재사용·테스트 가능하게 한다. 어드민 모달에 "팀장 여부 + 소속 팀 + 카톡방 URL" 입력을 추가하고, 코치 페이지에는 본인 정보 표시 + (팀장만) 팀원 명단 표를 보여주는 신규 페이지를 추가한다.

**Tech Stack:** PHP 8 + MySQL (PDO), 바닐라 JS SPA (App.registerPage 패턴), 자체 테스트 러너(`tests/_bootstrap.php`).

**Spec:** `docs/superpowers/specs/2026-04-30-coach-team-kakao-design.md`

---

## File Structure

**신규 파일**:
- `migrations/20260430_add_coach_team_and_kakao.sql` — ALTER + 시드 (팀 30명 + 카톡방 28개)
- `public_html/includes/coach_team.php` — 검증/카운트/차단 공용 함수 4개
- `public_html/api/coach_self.php` — 코치 본인 정보 API (`get_info` 액션)
- `public_html/coach/js/pages/my-info.js` — "내 정보" SPA 페이지
- `tests/coach_team_kakao_test.php` — 단위/통합 테스트

**수정 파일**:
- `schema.sql` — 마스터 스키마에 새 컬럼 반영
- `public_html/api/coaches.php` — list JOIN, create/update/delete에 공용 함수 호출
- `public_html/admin/js/pages/coaches.js` — `this.coaches` 캐시, 모달 입력 추가, 표 컬럼 추가
- `public_html/coach/index.php` — 사이드바 메뉴 + my-info 스크립트 등록

---

## Task 1: 마이그레이션 작성 + DEV DB 적용 + schema.sql 동기화

**Files:**
- Create: `migrations/20260430_add_coach_team_and_kakao.sql`
- Modify: `schema.sql:36` (coaches 테이블 정의 부분)

- [ ] **Step 1.1: 마이그레이션 SQL 작성**

Create `migrations/20260430_add_coach_team_and_kakao.sql`:

```sql
-- 코치 팀(self-FK) + 1:1 카톡방 링크 컬럼 추가
-- 시드: active 코치 30명을 3개 팀(Kel/Nana/Flora)에 배정 + 카톡방 URL 28개
-- (참고: MySQL에서 ALTER TABLE은 implicit commit. 트랜잭션 래퍼 무의미하므로 생략.
--  시드 UPDATE는 모두 멱등하므로 부분 실패 시 재실행 가능)

ALTER TABLE coaches
  ADD COLUMN team_leader_id INT NULL DEFAULT NULL AFTER evaluation,
  ADD COLUMN kakao_room_url VARCHAR(255) NULL DEFAULT NULL AFTER memo,
  ADD INDEX idx_team_leader (team_leader_id),
  ADD CONSTRAINT fk_coach_team_leader
      FOREIGN KEY (team_leader_id) REFERENCES coaches(id) ON DELETE SET NULL;

-- 1) 팀장 자기 자신을 가리키도록 설정 (3명)
UPDATE coaches SET team_leader_id = id WHERE coach_name IN ('Kel','Nana','Flora');

-- 2) Kel팀 팀원
UPDATE coaches SET team_leader_id = (SELECT id FROM (SELECT id FROM coaches WHERE coach_name='Kel') AS t)
  WHERE coach_name IN ('Lulu','Ella','Jay','Darren','Cera','Jacey','Ethan','Sen','Sophia');

-- 3) Nana팀 팀원
UPDATE coaches SET team_leader_id = (SELECT id FROM (SELECT id FROM coaches WHERE coach_name='Nana') AS t)
  WHERE coach_name IN ('Hyun','Raina','Bree','Kathy','Anne','Ej','Tia','Rin','Jenny');

-- 4) Flora팀 팀원
UPDATE coaches SET team_leader_id = (SELECT id FROM (SELECT id FROM coaches WHERE coach_name='Flora') AS t)
  WHERE coach_name IN ('Rachel','Julia','Frida','Jun','Salley','Hani','Tess','Hazel','Sophie');

-- 5) 카톡방 URL 시드 (28명)
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sz1en1ag' WHERE coach_name='Nana';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sOGKYyde' WHERE coach_name='Ella';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sdowrLEg' WHERE coach_name='Hani';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/s1wgF5sf' WHERE coach_name='Jun';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/me/raina'    WHERE coach_name='Raina';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sHQgUU1e' WHERE coach_name='Kel';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sbW4SsOd' WHERE coach_name='Jay';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sKf7CpRg' WHERE coach_name='Hyun';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sT1QVejh' WHERE coach_name='Bree';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sXIf8qse' WHERE coach_name='Rachel';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/s7ubBXli' WHERE coach_name='Julia';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sBcGGboi' WHERE coach_name='Ethan';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sJ9UQcVf' WHERE coach_name='Ej';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/me/soritune_hazel' WHERE coach_name='Hazel';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sr0qkNAh' WHERE coach_name='Kathy';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sI59jeSf' WHERE coach_name='Cera';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/shzl2FMd' WHERE coach_name='Salley';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sYhdGboi' WHERE coach_name='Tia';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sGMLOqEg' WHERE coach_name='Darren';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/s4vazNEg' WHERE coach_name='Lulu';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sDC69Wcf' WHERE coach_name='Jacey';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/soF5eNEg' WHERE coach_name='Anne';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/me/Coach_Tess' WHERE coach_name='Tess';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/skOGHboi' WHERE coach_name='Sophie';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sOfV4aoi' WHERE coach_name='Sen';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sKkVp1ag' WHERE coach_name='Flora';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sou9Cboi' WHERE coach_name='Rin';
UPDATE coaches SET kakao_room_url = 'https://open.kakao.com/o/sQ9CQ9ye' WHERE coach_name='Sophia';
```

- [ ] **Step 1.2: DEV DB에 적용**

Run:
```bash
mysql -h localhost -u SORITUNECOM_DEV_PT -p'Va4/7hdfj7oweLwMah3bHzXR' SORITUNECOM_DEV_PT \
  < /root/pt-dev/migrations/20260430_add_coach_team_and_kakao.sql
```

Expected: 종료 코드 0, 에러 메시지 없음.

- [ ] **Step 1.3: 시드 검증 SELECT**

Run:
```bash
mysql -h localhost -u SORITUNECOM_DEV_PT -p'Va4/7hdfj7oweLwMah3bHzXR' SORITUNECOM_DEV_PT -e "
SELECT
  l.coach_name AS team_leader,
  COUNT(*) AS member_count
FROM coaches c
JOIN coaches l ON l.id = c.team_leader_id
WHERE c.status='active'
GROUP BY l.id, l.coach_name
ORDER BY l.coach_name;
"
```

Expected output:
```
team_leader	member_count
Flora		10
Kel		10
Nana		10
```

추가 검증:
```bash
mysql -h localhost -u SORITUNECOM_DEV_PT -p'Va4/7hdfj7oweLwMah3bHzXR' SORITUNECOM_DEV_PT -e "
SELECT COUNT(*) AS with_kakao FROM coaches WHERE kakao_room_url IS NOT NULL;
SELECT coach_name FROM coaches WHERE status='active' AND kakao_room_url IS NULL ORDER BY coach_name;
"
```

Expected:
```
with_kakao
28
coach_name
Frida
Jenny
```

- [ ] **Step 1.4: schema.sql 마스터 스키마 업데이트**

Modify `schema.sql` — `CREATE TABLE coaches (...)` 블록 안에서 다음 두 컬럼을 명시적으로 추가하고 인덱스/FK 추가:

`role` 정의 라인 다음에:
```sql
  `evaluation` ENUM('pass','fail') DEFAULT NULL,
  `team_leader_id` INT DEFAULT NULL,
```
(원래 `role` 다음이 `evaluation`이므로, `evaluation` 다음에 `team_leader_id` 라인 추가)

`memo` 라인 다음에:
```sql
  `memo` TEXT,
  `kakao_room_url` VARCHAR(255) DEFAULT NULL,
```

`coaches` 테이블 정의 끝(`) ENGINE=...` 직전)에 인덱스/FK 추가:
```sql
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_team_leader` (`team_leader_id`),
  CONSTRAINT `fk_coach_team_leader` FOREIGN KEY (`team_leader_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 1.5: Commit**

```bash
cd /root/pt-dev && \
git add migrations/20260430_add_coach_team_and_kakao.sql schema.sql && \
git commit -m "$(cat <<'EOF'
feat(coach): team_leader_id + kakao_room_url 컬럼 + 시드

- coaches.team_leader_id INT NULL self-FK (ON DELETE SET NULL)
- coaches.kakao_room_url VARCHAR(255) NULL
- 시드: active 30명을 Kel/Nana/Flora 3개 팀 배정 + 28명 카톡방 URL
  - Jenny, Frida 카톡방은 NULL (사용자가 미제공)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: 공용 함수 — `normalizeKakaoRoomUrl()` (TDD)

**Files:**
- Create: `tests/coach_team_kakao_test.php`
- Create: `public_html/includes/coach_team.php`

- [ ] **Step 2.1: 실패하는 테스트 작성**

Create `tests/coach_team_kakao_test.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/coach_team.php';

t_section('normalizeKakaoRoomUrl — null/empty 정규화');
t_assert_eq(null, normalizeKakaoRoomUrl(null), 'null → null');
t_assert_eq(null, normalizeKakaoRoomUrl(''), '"" → null');
t_assert_eq(null, normalizeKakaoRoomUrl('   '), '공백만 → null');

t_section('normalizeKakaoRoomUrl — 정상 URL 통과');
t_assert_eq(
    'https://open.kakao.com/o/sz1en1ag',
    normalizeKakaoRoomUrl('https://open.kakao.com/o/sz1en1ag'),
    'open.kakao.com/o/...'
);
t_assert_eq(
    'https://open.kakao.com/me/raina',
    normalizeKakaoRoomUrl('https://open.kakao.com/me/raina'),
    'open.kakao.com/me/...'
);
t_assert_eq(
    'https://open.kakao.com/o/sBcGGboi',
    normalizeKakaoRoomUrl('  https://open.kakao.com/o/sBcGGboi  '),
    '앞뒤 공백은 trim'
);

t_section('normalizeKakaoRoomUrl — 잘못된 URL 거부');
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('http://open.kakao.com/o/abc'),
    InvalidArgumentException::class,
    'http:// 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('https://kakao.com/o/abc'),
    InvalidArgumentException::class,
    'open. 누락 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('https://open.kakao.com/x/abc'),
    InvalidArgumentException::class,
    '/o/ 또는 /me/ 외 path 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('https://open.kakao.com/o/<script>'),
    InvalidArgumentException::class,
    '특수문자 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('javascript:alert(1)'),
    InvalidArgumentException::class,
    'javascript: 스킴 거부'
);
```

- [ ] **Step 2.2: 테스트 실행 → FAIL 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1
```

Expected: 첫 줄에서 fatal error — `require_once`가 존재하지 않는 파일을 못 찾아 실패. 또는 require가 통과해도 `normalizeKakaoRoomUrl`이 정의되지 않아 fatal.

- [ ] **Step 2.3: `normalizeKakaoRoomUrl()` 구현**

Create `public_html/includes/coach_team.php`:

```php
<?php
declare(strict_types=1);

const KAKAO_ROOM_URL_REGEX = '/^https:\/\/open\.kakao\.com\/(o|me)\/[A-Za-z0-9_]+$/';

function normalizeKakaoRoomUrl(?string $raw): ?string
{
    if ($raw === null) return null;
    $trimmed = trim($raw);
    if ($trimmed === '') return null;
    if (!preg_match(KAKAO_ROOM_URL_REGEX, $trimmed)) {
        throw new InvalidArgumentException(
            '카톡방 링크 형식이 올바르지 않습니다 (https://open.kakao.com/o/... 또는 /me/...)'
        );
    }
    return $trimmed;
}
```

- [ ] **Step 2.4: 테스트 실행 → PASS 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1
```

Expected (해당 섹션):
```
=== normalizeKakaoRoomUrl — null/empty 정규화 ===
  PASS  null → null
  PASS  "" → null
  PASS  공백만 → null
=== normalizeKakaoRoomUrl — 정상 URL 통과 ===
  PASS  open.kakao.com/o/...
  PASS  open.kakao.com/me/...
  PASS  앞뒤 공백은 trim
=== normalizeKakaoRoomUrl — 잘못된 URL 거부 ===
  PASS  http:// 거부
  PASS  open. 누락 거부
  PASS  /o/ 또는 /me/ 외 path 거부
  PASS  특수문자 거부
  PASS  javascript: 스킴 거부
```

마지막 줄 `Total: ... Pass: ... Fail: 0`.

- [ ] **Step 2.5: Commit**

```bash
cd /root/pt-dev && \
git add public_html/includes/coach_team.php tests/coach_team_kakao_test.php && \
git commit -m "$(cat <<'EOF'
feat(coach-team): normalizeKakaoRoomUrl + 단위 테스트

- includes/coach_team.php 신규 (공용 검증 함수 모듈)
- 정규식: ^https://open\.kakao\.com/(o|me)/[A-Za-z0-9_]+$
- null/공백 → null, 잘못된 URL → InvalidArgumentException

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: 공용 함수 — `validateTeamLeaderId()`, `countTeamMembers()`, `assertCanModifyLeader()`

**Files:**
- Modify: `tests/coach_team_kakao_test.php` (테스트 추가)
- Modify: `public_html/includes/coach_team.php` (함수 추가)

- [ ] **Step 3.1: 실패하는 테스트 추가 — `validateTeamLeaderId`**

Append to `tests/coach_team_kakao_test.php`:

```php
require_once __DIR__ . '/../public_html/includes/db.php';

$db = getDB();

// Helper: 임시 코치 생성 (transaction 안에서만 사용)
function t_make_coach(PDO $db, array $opts = []): int {
    $opts = array_merge([
        'coach_name'    => 'TC_' . uniqid(),
        'login_id'      => 'tc_' . uniqid(),
        'status'        => 'active',
        'team_leader_id'=> null,
    ], $opts);
    $db->prepare("
        INSERT INTO coaches (login_id, password_hash, coach_name, status, team_leader_id)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $opts['login_id'],
        password_hash('x', PASSWORD_BCRYPT),
        $opts['coach_name'],
        $opts['status'],
        $opts['team_leader_id'],
    ]);
    return (int)$db->lastInsertId();
}

t_section('validateTeamLeaderId — 통과 케이스');

$db->beginTransaction();
$leader = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leader]);
$member = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leader]);

validateTeamLeaderId($db, $member, null);  // null 통과
t_assert_true(true, 'null leader id는 통과');

validateTeamLeaderId($db, $leader, $leader);  // self 통과
t_assert_true(true, 'self leader id는 통과');

validateTeamLeaderId($db, $member, $leader);  // 정상 active 팀장 통과
t_assert_true(true, '정상 active 팀장은 통과');
$db->rollBack();

t_section('validateTeamLeaderId — 거부 케이스');

$db->beginTransaction();
$inactiveLeader = t_make_coach($db, ['status' => 'inactive']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$inactiveLeader]);
$other = t_make_coach($db, ['status' => 'active']);
t_assert_throws(
    fn() => validateTeamLeaderId($db, $other, $inactiveLeader),
    InvalidArgumentException::class,
    'inactive 팀장 거부'
);
$db->rollBack();

$db->beginTransaction();
$nonLeader = t_make_coach($db, ['status' => 'active', 'team_leader_id' => null]);
$other = t_make_coach($db, ['status' => 'active']);
t_assert_throws(
    fn() => validateTeamLeaderId($db, $other, $nonLeader),
    InvalidArgumentException::class,
    '팀장이 아닌(team_leader_id=NULL) 코치 거부'
);
$db->rollBack();

$db->beginTransaction();
$leaderA = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leaderA]);
// 팀원 코치를 leaderId로 지정 시도
$memberOfA = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leaderA]);
$other = t_make_coach($db, ['status' => 'active']);
t_assert_throws(
    fn() => validateTeamLeaderId($db, $other, $memberOfA),
    InvalidArgumentException::class,
    '팀장이 아닌(다른 사람의 팀원) 코치 거부'
);
$db->rollBack();
```

- [ ] **Step 3.2: 테스트 실행 → FAIL 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -30
```

Expected: `validateTeamLeaderId` 호출에서 fatal — 함수 정의 안 됨.

- [ ] **Step 3.3: `validateTeamLeaderId()` 구현**

Append to `public_html/includes/coach_team.php`:

```php
function validateTeamLeaderId(PDO $db, int $coachId, ?int $leaderId): void
{
    if ($leaderId === null) return;
    if ($leaderId === $coachId) return;
    $stmt = $db->prepare("
        SELECT id FROM coaches
        WHERE id = ? AND team_leader_id = id AND status = 'active'
    ");
    $stmt->execute([$leaderId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('지정한 팀장이 유효하지 않습니다 (active 팀장만 선택 가능)');
    }
}
```

- [ ] **Step 3.4: 테스트 실행 → PASS 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -30
```

Expected: 새 섹션 모두 PASS, `Fail: 0`.

- [ ] **Step 3.5: `countTeamMembers` + `assertCanModifyLeader` 테스트 추가**

Append to `tests/coach_team_kakao_test.php`:

```php
t_section('countTeamMembers — 본인 제외');

$db->beginTransaction();
$leader = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leader]);
t_assert_eq(0, countTeamMembers($db, $leader), '본인만 있는 팀 = 0명');

$m1 = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leader]);
$m2 = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leader]);
t_assert_eq(2, countTeamMembers($db, $leader), '팀원 2명');
$db->rollBack();

t_section('assertCanModifyLeader — 팀원 0명이면 통과');

$db->beginTransaction();
$leader = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leader]);
foreach (['inactive', 'unset_leader', 'delete'] as $action) {
    assertCanModifyLeader($db, $leader, $action);
    t_assert_true(true, "팀원 0명 + action={$action} 통과");
}
$db->rollBack();

t_section('assertCanModifyLeader — 팀원 있으면 차단');

$db->beginTransaction();
$leader = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leader]);
$m = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leader]);
foreach (['inactive', 'unset_leader', 'delete'] as $action) {
    t_assert_throws(
        fn() => assertCanModifyLeader($db, $leader, $action),
        RuntimeException::class,
        "팀원 1명 + action={$action} 차단"
    );
}
$db->rollBack();
```

- [ ] **Step 3.6: 테스트 실행 → FAIL 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -20
```

Expected: fatal — `countTeamMembers` 또는 `assertCanModifyLeader` 미정의.

- [ ] **Step 3.7: 두 함수 구현**

Append to `public_html/includes/coach_team.php`:

```php
function countTeamMembers(PDO $db, int $leaderId): int
{
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM coaches
        WHERE team_leader_id = ? AND id != ?
    ");
    $stmt->execute([$leaderId, $leaderId]);
    return (int)$stmt->fetchColumn();
}

function assertCanModifyLeader(PDO $db, int $coachId, string $action): void
{
    $allowed = ['inactive', 'unset_leader', 'delete'];
    if (!in_array($action, $allowed, true)) {
        throw new InvalidArgumentException("Unknown action: {$action}");
    }
    $count = countTeamMembers($db, $coachId);
    if ($count > 0) {
        throw new RuntimeException(
            "이 팀에 팀원 {$count}명이 있습니다. 먼저 다른 팀장을 지정하거나 팀원을 미배정 처리하세요"
        );
    }
}
```

- [ ] **Step 3.8: 테스트 실행 → 모두 PASS 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -25
```

Expected: 새로 추가된 섹션 모두 PASS, `Fail: 0`.

- [ ] **Step 3.9: Commit**

```bash
cd /root/pt-dev && \
git add public_html/includes/coach_team.php tests/coach_team_kakao_test.php && \
git commit -m "$(cat <<'EOF'
feat(coach-team): validate/count/assert 함수 + 테스트

- validateTeamLeaderId: NULL/self 통과, 타인은 active 팀장 검증
- countTeamMembers: 본인 제외한 팀원 수
- assertCanModifyLeader: 팀원 N>0 시 RuntimeException
  (action: inactive/unset_leader/delete)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 시드 데이터 정합성 테스트

**Files:**
- Modify: `tests/coach_team_kakao_test.php`

- [ ] **Step 4.1: 시드 검증 섹션 추가**

Append to `tests/coach_team_kakao_test.php`:

```php
t_section('시드 데이터 정합성');

// 팀장 3명이 모두 self-ref
$leaders = $db->query("
    SELECT coach_name FROM coaches
    WHERE team_leader_id = id AND status = 'active'
    ORDER BY coach_name
")->fetchAll(PDO::FETCH_COLUMN);
t_assert_eq(['Flora', 'Kel', 'Nana'], $leaders, '팀장 3명 = Flora/Kel/Nana');

// 각 팀별 멤버 수 (본인 포함하면 10명씩)
$counts = $db->query("
    SELECT l.coach_name, COUNT(*) AS n
    FROM coaches c JOIN coaches l ON l.id = c.team_leader_id
    WHERE c.status='active'
    GROUP BY l.id, l.coach_name
    ORDER BY l.coach_name
")->fetchAll(PDO::FETCH_KEY_PAIR);
t_assert_eq(10, (int)$counts['Kel'], 'Kel팀 10명 (본인 포함)');
t_assert_eq(10, (int)$counts['Nana'], 'Nana팀 10명');
t_assert_eq(10, (int)$counts['Flora'], 'Flora팀 10명');

// active 코치 30명 모두 팀 배정
$unassigned = $db->query("
    SELECT COUNT(*) FROM coaches WHERE status='active' AND team_leader_id IS NULL
")->fetchColumn();
t_assert_eq(0, (int)$unassigned, 'active 미배정 코치 0명');

// 카톡방 28명
$withKakao = (int)$db->query("SELECT COUNT(*) FROM coaches WHERE kakao_room_url IS NOT NULL")->fetchColumn();
t_assert_eq(28, $withKakao, 'kakao_room_url 보유 28명');

// 카톡방 미설정 active = Frida, Jenny
$noKakao = $db->query("
    SELECT coach_name FROM coaches
    WHERE status='active' AND kakao_room_url IS NULL ORDER BY coach_name
")->fetchAll(PDO::FETCH_COLUMN);
t_assert_eq(['Frida', 'Jenny'], $noKakao, '카톡방 미설정은 Frida, Jenny');

// 모든 카톡방 URL이 정규식 통과
$urls = $db->query("SELECT kakao_room_url FROM coaches WHERE kakao_room_url IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$invalid = [];
foreach ($urls as $u) {
    try { normalizeKakaoRoomUrl($u); }
    catch (InvalidArgumentException $e) { $invalid[] = $u; }
}
t_assert_eq([], $invalid, '시드 카톡방 URL 모두 정규식 통과');
```

- [ ] **Step 4.2: 테스트 실행 → PASS 확인**

Run:
```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -20
```

Expected: 시드 정합성 섹션 모두 PASS, `Fail: 0`.

- [ ] **Step 4.3: Commit**

```bash
cd /root/pt-dev && \
git add tests/coach_team_kakao_test.php && \
git commit -m "$(cat <<'EOF'
test(coach-team): 시드 데이터 정합성 검증

- 팀장 3명 self-ref
- Kel/Nana/Flora 팀 각 10명
- active 미배정 0명
- 카톡방 28명 (Frida, Jenny 제외)
- 모든 시드 URL 정규식 통과

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: API list — `team_leader_name` + `team_member_count` JOIN

**Files:**
- Modify: `public_html/api/coaches.php:13-21`

- [ ] **Step 5.1: list 액션 SQL 변경**

In `public_html/api/coaches.php`, find:
```php
    case 'list':
        $stmt = $db->query("
            SELECT c.*,
              (SELECT COUNT(DISTINCT o.member_id) FROM orders o
               WHERE o.coach_id = c.id AND o.status = '진행중') AS current_count
            FROM coaches c
            ORDER BY c.status ASC, c.coach_name ASC
        ");
        jsonSuccess(['coaches' => $stmt->fetchAll()]);
```

Replace with:
```php
    case 'list':
        $stmt = $db->query("
            SELECT c.*,
              leader.coach_name AS team_leader_name,
              (SELECT COUNT(*) FROM coaches m
                WHERE m.team_leader_id = c.id AND m.id != c.id) AS team_member_count,
              (SELECT COUNT(DISTINCT o.member_id) FROM orders o
               WHERE o.coach_id = c.id AND o.status = '진행중') AS current_count
            FROM coaches c
            LEFT JOIN coaches leader ON leader.id = c.team_leader_id
            ORDER BY c.status ASC, c.coach_name ASC
        ");
        jsonSuccess(['coaches' => $stmt->fetchAll()]);
```

- [ ] **Step 5.2: 어드민 세션으로 list 호출 — curl 검증**

먼저 admin 로그인 세션이 필요. 브라우저에서 dev 사이트 어드민 로그인 후 쿠키를 가져오거나, 다음 시나리오로 직접 검증:

```bash
mysql -h localhost -u SORITUNECOM_DEV_PT -p'Va4/7hdfj7oweLwMah3bHzXR' SORITUNECOM_DEV_PT -e "
SELECT
  c.id, c.coach_name,
  leader.coach_name AS team_leader_name,
  (SELECT COUNT(*) FROM coaches m WHERE m.team_leader_id = c.id AND m.id != c.id) AS team_member_count
FROM coaches c
LEFT JOIN coaches leader ON leader.id = c.team_leader_id
WHERE c.coach_name IN ('Kel','Lulu','Frida')
ORDER BY c.coach_name;
"
```

Expected:
```
id  coach_name  team_leader_name  team_member_count
14  Frida       Flora             0
6   Kel         Kel               9
9   Lulu        Kel               0
```

(Kel의 team_member_count = 9 — 본인 외 팀원 9명. Lulu는 팀원이라 0. Frida는 팀원이라 0.)

- [ ] **Step 5.3: Commit**

```bash
cd /root/pt-dev && \
git add public_html/api/coaches.php && \
git commit -m "$(cat <<'EOF'
feat(api/coaches): list에 team_leader_name + team_member_count JOIN

- LEFT JOIN으로 team_leader_name (팀장 코치명)
- team_member_count: 본인 제외한 팀원 수 (cascade 정책과 동일 카운트)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: API create — 신규 필드 처리

**Files:**
- Modify: `public_html/api/coaches.php:1-9` (require 추가) + `:32-66` (case 'create')

- [ ] **Step 6.1: coach_team.php require 추가**

In `public_html/api/coaches.php`, find:
```php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
```

Add:
```php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coach_team.php';
```

- [ ] **Step 6.2: create 액션 변경**

Find `case 'create':` block (lines 32-66). Replace entire block with:

```php
    case 'create':
        $input = getJsonInput();
        $loginId = trim($input['login_id'] ?? '');
        $password = $input['password'] ?? '';
        $coachName = trim($input['coach_name'] ?? '');

        if (!$loginId || !$password || !$coachName) jsonError('필수 항목을 입력하세요');

        // 신규 필드 검증
        try {
            $kakaoUrl = normalizeKakaoRoomUrl($input['kakao_room_url'] ?? null);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        $isLeader = !empty($input['is_team_leader']);
        $teamLeaderIdInput = $isLeader ? null : (
            isset($input['team_leader_id']) && $input['team_leader_id'] !== ''
                ? (int)$input['team_leader_id'] : null
        );
        // self는 INSERT 후 알 수 있으므로 일단 입력값만 (타인 leader인 경우만 미리 검증 가능)
        if ($teamLeaderIdInput !== null) {
            try {
                validateTeamLeaderId($db, 0, $teamLeaderIdInput);
                // coachId=0은 self-체크 우회용. 타인 검증만 필요.
            } catch (InvalidArgumentException $e) {
                jsonError($e->getMessage());
            }
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO coaches
                (login_id, password_hash, coach_name, korean_name, birthdate, hired_on, role, evaluation,
                 team_leader_id, status, available, max_capacity, memo, kakao_room_url,
                 overseas, side_job, soriblock_basic, soriblock_advanced)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $loginId, $hash, $coachName,
                trim($input['korean_name'] ?? '') ?: null,
                !empty($input['birthdate']) ? $input['birthdate'] : null,
                !empty($input['hired_on']) ? $input['hired_on'] : null,
                !empty($input['role']) ? $input['role'] : null,
                !empty($input['evaluation']) ? $input['evaluation'] : null,
                $teamLeaderIdInput,                       // 일단 입력값으로 INSERT
                $input['status'] ?? 'active',
                (int)($input['available'] ?? 1),
                (int)($input['max_capacity'] ?? 0),
                $input['memo'] ?? null,
                $kakaoUrl,
                (int)!empty($input['overseas']),
                (int)!empty($input['side_job']),
                (int)!empty($input['soriblock_basic']),
                (int)!empty($input['soriblock_advanced']),
            ]);
            $newId = (int)$db->lastInsertId();

            // 본인이 팀장인 경우 self-ref 업데이트
            if ($isLeader) {
                $db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$newId]);
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->getCode() == 23000) jsonError('이미 사용 중인 로그인 ID입니다');
            throw $e;
        }
        jsonSuccess(['id' => $newId], '코치가 등록되었습니다');
```

- [ ] **Step 6.3: 수동 검증 — 시나리오별 INSERT/롤백**

DB 트랜잭션 안에서 직접 시나리오 검증 (curl 셋업 어려우므로 SQL로 동치 검증):

```bash
mysql -h localhost -u SORITUNECOM_DEV_PT -p'Va4/7hdfj7oweLwMah3bHzXR' SORITUNECOM_DEV_PT -e "
START TRANSACTION;
-- 시나리오 1: 신규 팀장
INSERT INTO coaches (login_id, password_hash, coach_name, status)
VALUES ('test_lead', 'x', 'TestLead', 'active');
SET @nid = LAST_INSERT_ID();
UPDATE coaches SET team_leader_id = id WHERE id = @nid;
SELECT id, coach_name, team_leader_id FROM coaches WHERE id = @nid;
ROLLBACK;
"
```

Expected: 1행 출력, `team_leader_id = id` (self-ref).

- [ ] **Step 6.4: Commit**

```bash
cd /root/pt-dev && \
git add public_html/api/coaches.php && \
git commit -m "$(cat <<'EOF'
feat(api/coaches): create에 team_leader/kakao_room_url 처리

- coach_team.php의 normalizeKakaoRoomUrl/validateTeamLeaderId 사용
- 단일 트랜잭션: INSERT 후 is_team_leader=true면 self-ref UPDATE
- 입력 정규화: 빈 문자열 → null, 잘못된 URL → 400

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: API update — 신규 필드 + cascade 차단

**Files:**
- Modify: `public_html/api/coaches.php:68-103` (case 'update')

- [ ] **Step 7.1: update 액션 변경**

Find `case 'update':` block. Replace entire block with:

```php
    case 'update':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $input = getJsonInput();

        // 현재 상태 조회 (cascade 차단 판정용)
        $cur = $db->prepare("SELECT id, status, team_leader_id FROM coaches WHERE id = ?");
        $cur->execute([$id]);
        $current = $cur->fetch();
        if (!$current) jsonError('코치를 찾을 수 없습니다', 404);
        $isCurrentlyLeader = ((int)$current['team_leader_id'] === (int)$current['id']);

        // is_team_leader / team_leader_id 의도 파악
        $hasLeaderField = array_key_exists('is_team_leader', $input)
                       || array_key_exists('team_leader_id', $input);
        $isLeaderAfter = !empty($input['is_team_leader']);
        $teamLeaderIdAfter = $isLeaderAfter ? $id : (
            isset($input['team_leader_id']) && $input['team_leader_id'] !== ''
                ? (int)$input['team_leader_id'] : null
        );

        // cascade 차단: 현재 팀장인데 (a) inactive 변경 시도 또는 (b) 팀장 해제
        if ($isCurrentlyLeader) {
            $statusAfter = $input['status'] ?? $current['status'];
            if ($statusAfter === 'inactive') {
                try { assertCanModifyLeader($db, $id, 'inactive'); }
                catch (RuntimeException $e) { jsonError($e->getMessage()); }
            }
            if ($hasLeaderField && $teamLeaderIdAfter !== $id) {
                try { assertCanModifyLeader($db, $id, 'unset_leader'); }
                catch (RuntimeException $e) { jsonError($e->getMessage()); }
            }
        }

        // team_leader_id 입력 검증 (타인 leader면 active 팀장인지 확인)
        if ($hasLeaderField && $teamLeaderIdAfter !== null && $teamLeaderIdAfter !== $id) {
            try { validateTeamLeaderId($db, $id, $teamLeaderIdAfter); }
            catch (InvalidArgumentException $e) { jsonError($e->getMessage()); }
        }

        // 카톡방 URL 정규화
        $kakaoProvided = array_key_exists('kakao_room_url', $input);
        $kakaoNormalized = null;
        if ($kakaoProvided) {
            try { $kakaoNormalized = normalizeKakaoRoomUrl($input['kakao_room_url']); }
            catch (InvalidArgumentException $e) { jsonError($e->getMessage()); }
        }

        $fields = [];
        $params = [];
        $boolFields = ['available','overseas','side_job','soriblock_basic','soriblock_advanced'];
        $nullableFields = ['korean_name','birthdate','hired_on','role','evaluation'];
        foreach (['coach_name','status','max_capacity','memo'] as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = $input[$f];
            }
        }
        foreach ($boolFields as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = (int)!empty($input[$f]);
            }
        }
        foreach ($nullableFields as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = ($input[$f] === '' || $input[$f] === null) ? null : $input[$f];
            }
        }
        if (!empty($input['password'])) {
            $fields[] = "password_hash = ?";
            $params[] = password_hash($input['password'], PASSWORD_BCRYPT);
        }
        if ($hasLeaderField) {
            $fields[] = "team_leader_id = ?";
            $params[] = $teamLeaderIdAfter;
        }
        if ($kakaoProvided) {
            $fields[] = "kakao_room_url = ?";
            $params[] = $kakaoNormalized;
        }
        if (empty($fields)) jsonError('변경할 항목이 없습니다');

        $params[] = $id;
        $db->prepare("UPDATE coaches SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        jsonSuccess([], '코치 정보가 수정되었습니다');
```

- [ ] **Step 7.2: SQL 동치 시나리오 검증**

```bash
mysql -h localhost -u SORITUNECOM_DEV_PT -p'Va4/7hdfj7oweLwMah3bHzXR' SORITUNECOM_DEV_PT -e "
-- 시나리오 1: 정상 update — Lulu의 카톡방 URL 변경 후 롤백
START TRANSACTION;
SELECT id, coach_name, kakao_room_url FROM coaches WHERE coach_name='Lulu';
UPDATE coaches SET kakao_room_url='https://open.kakao.com/o/CHANGED' WHERE coach_name='Lulu';
SELECT id, coach_name, kakao_room_url FROM coaches WHERE coach_name='Lulu';
ROLLBACK;
"
```

Expected: 변경 전/후 보임, ROLLBACK 후 원상 복귀.

PHP 함수 단위 cascade 테스트는 이미 Task 3에서 검증됨.

- [ ] **Step 7.3: Commit**

```bash
cd /root/pt-dev && \
git add public_html/api/coaches.php && \
git commit -m "$(cat <<'EOF'
feat(api/coaches): update에 신규 필드 + cascade 차단

- 현재 팀장의 inactive/팀장해제 시 assertCanModifyLeader로 차단
- 타인 팀장 지정 시 validateTeamLeaderId로 검증
- kakao_room_url normalize + null 허용
- 이전 동작(다른 필드 patch) 그대로 유지

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: API delete — 팀원 있는 팀장 차단 추가

**Files:**
- Modify: `public_html/api/coaches.php:105-115` (case 'delete')

- [ ] **Step 8.1: delete 액션 변경**

Find `case 'delete':` block. Replace with:

```php
    case 'delete':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');

        // 진행중 회원 차단 (기존)
        $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE coach_id = ? AND status = '진행중'");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            jsonError('현재 담당 회원이 있는 코치는 삭제할 수 없습니다');
        }

        // 팀원 있는 팀장 차단 (신규)
        $cur = $db->prepare("SELECT id, team_leader_id FROM coaches WHERE id = ?");
        $cur->execute([$id]);
        $current = $cur->fetch();
        if ($current && (int)$current['team_leader_id'] === (int)$current['id']) {
            try { assertCanModifyLeader($db, $id, 'delete'); }
            catch (RuntimeException $e) { jsonError($e->getMessage()); }
        }

        $db->prepare("DELETE FROM coaches WHERE id = ?")->execute([$id]);
        jsonSuccess([], '코치가 삭제되었습니다');
```

- [ ] **Step 8.2: Commit**

```bash
cd /root/pt-dev && \
git add public_html/api/coaches.php && \
git commit -m "$(cat <<'EOF'
feat(api/coaches): delete에 팀원 있는 팀장 차단

- 기존 진행중 회원 차단 유지
- 추가: 팀장 코치 + 팀원>0 → 차단

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: 어드민 모달 — 팀 그룹 추가 + `this.coaches` 캐시

**Files:**
- Modify: `public_html/admin/js/pages/coaches.js`

- [ ] **Step 9.1: `loadList`에 캐시 저장**

In `public_html/admin/js/pages/coaches.js`, find the `loadList()` method:
```js
  async loadList() {
    const res = await API.get('/api/coaches.php?action=list');
    if (!res.ok) return;
    const coaches = res.data.coaches;
```

Replace with:
```js
  async loadList() {
    const res = await API.get('/api/coaches.php?action=list');
    if (!res.ok) return;
    const coaches = res.data.coaches;
    this.coaches = coaches;  // 모달에서 팀장 옵션 생성용
```

- [ ] **Step 9.2: `showForm` — 코치 기본값에 신규 필드 추가**

Find:
```js
  async showForm(coachId = null) {
    let coach = {
      login_id: '', coach_name: '', korean_name: '', birthdate: '', hired_on: '',
      role: '', evaluation: '', status: 'active', available: 1, max_capacity: 0, memo: '',
      overseas: 0, side_job: 0, soriblock_basic: 0, soriblock_advanced: 0,
    };
```

Replace with:
```js
  async showForm(coachId = null) {
    let coach = {
      login_id: '', coach_name: '', korean_name: '', birthdate: '', hired_on: '',
      role: '', evaluation: '', status: 'active', available: 1, max_capacity: 0, memo: '',
      overseas: 0, side_job: 0, soriblock_basic: 0, soriblock_advanced: 0,
      team_leader_id: null, kakao_room_url: '',
    };
```

- [ ] **Step 9.3: 팀장 옵션 생성 + 모달 HTML에 팀 그룹 추가**

Find:
```js
    const isEdit = !!coachId;
    const roleOptions = this.ROLE_OPTIONS.map(r =>
      `<option value="${UI.esc(r)}" ${coach.role === r ? 'selected' : ''}>${UI.esc(r)}</option>`
    ).join('');
```

Replace with:
```js
    const isEdit = !!coachId;
    const roleOptions = this.ROLE_OPTIONS.map(r =>
      `<option value="${UI.esc(r)}" ${coach.role === r ? 'selected' : ''}>${UI.esc(r)}</option>`
    ).join('');

    const isLeader = isEdit && coach.team_leader_id != null
                     && Number(coach.team_leader_id) === Number(coach.id);
    const leaderOptions = (this.coaches || [])
      .filter(c => Number(c.team_leader_id) === Number(c.id) && c.status === 'active' && c.id !== coach.id)
      .map(c => `<option value="${c.id}" ${Number(coach.team_leader_id) === Number(c.id) ? 'selected' : ''}>${UI.esc(c.coach_name)}팀 (${UI.esc(c.coach_name)})</option>`)
      .join('');
```

- [ ] **Step 9.4: 모달 폼 HTML — 팀 그룹 추가 (속성 위에)**

Find the form HTML section that ends with `</div>` before "속성" label, and locate this part:
```js
          <div class="form-group">
            <label class="form-label">최대 담당 인원</label>
            <input class="form-input" type="number" name="max_capacity" value="${coach.max_capacity}" min="0">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">속성</label>
```

Replace with (insert team group between max_capacity and 속성):
```js
          <div class="form-group">
            <label class="form-label">최대 담당 인원</label>
            <input class="form-input" type="number" name="max_capacity" value="${coach.max_capacity}" min="0">
          </div>
          <div class="form-group">
            <label class="form-label">팀장 여부</label>
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;padding:8px 0">
              <input type="checkbox" name="is_team_leader" value="1" id="isTeamLeaderChk" ${isLeader ? 'checked' : ''}>
              팀장으로 지정 (본인 이름의 팀이 자동 생성)
            </label>
          </div>
          <div class="form-group">
            <label class="form-label">소속 팀</label>
            <select class="form-select" name="team_leader_id" id="teamLeaderSelect" ${isLeader ? 'disabled' : ''}>
              <option value="">(미배정)</option>
              ${leaderOptions}
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">속성</label>
```

- [ ] **Step 9.5: 체크박스 ↔ 드롭다운 상호작용**

Find the form submit listener registration:
```js
    document.getElementById('coachForm').addEventListener('submit', async e => {
```

Just before this line (after `UI.showModal(...)` block), add:
```js
    // 팀장 체크박스 ↔ 소속 팀 드롭다운 상호작용
    const chk = document.getElementById('isTeamLeaderChk');
    const sel = document.getElementById('teamLeaderSelect');
    if (chk && sel) {
      chk.addEventListener('change', () => {
        sel.disabled = chk.checked;
        if (chk.checked) sel.value = '';
      });
    }
```

- [ ] **Step 9.6: submit 직렬화 — `is_team_leader`, `team_leader_id`**

Find:
```js
      const fd = new FormData(form);
      const body = Object.fromEntries(fd);
      body.available = parseInt(body.available);
      body.max_capacity = parseInt(body.max_capacity);
      ['overseas','side_job','soriblock_basic','soriblock_advanced'].forEach(k => {
        body[k] = form.elements[k].checked ? 1 : 0;
      });
      if (isEdit && !body.password) delete body.password;
```

Replace with:
```js
      const fd = new FormData(form);
      const body = Object.fromEntries(fd);
      body.available = parseInt(body.available);
      body.max_capacity = parseInt(body.max_capacity);
      ['overseas','side_job','soriblock_basic','soriblock_advanced'].forEach(k => {
        body[k] = form.elements[k].checked ? 1 : 0;
      });
      body.is_team_leader = form.elements.is_team_leader.checked ? 1 : 0;
      body.team_leader_id = body.is_team_leader
        ? null
        : (form.elements.team_leader_id.value || null);
      if (isEdit && !body.password) delete body.password;
```

- [ ] **Step 9.7: 브라우저 수동 검증**

DEV 환경 (`https://dev-pt.soritune.com/admin/`) 어드민 로그인 후:

1. 코치관리 페이지 진입 → 표 정상 렌더 (변화 없음)
2. Kel 코치 "편집" 클릭 → 모달에 "팀장 여부" 체크박스 ✓ 체크 상태, "소속 팀" 드롭다운 disabled
3. Lulu 편집 → "팀장 여부" 미체크, "소속 팀" 드롭다운 = "Kel" 선택 상태
4. 미배정 코치 (없으면 신규 코치 추가로 테스트) → "(미배정)" 선택 + 활성
5. Lulu의 "팀장 여부" 체크 → 드롭다운 자동 disabled + 값 비어짐 → 저장 시도 시 (현재 Kel팀에 팀원 9명 있어서 "팀장 해제 차단"과 다른 시나리오: Lulu는 원래 팀장이 아니라 차단 X) → 저장 성공 (Lulu가 팀장으로 self-ref 변경됨) → 다시 풀어 원상 복귀
6. (중요) 다시 풀기: Lulu 편집 → 체크 해제 → 드롭다운에서 Kel 다시 선택 → 저장

UI에서 결과 확인 후, 다음 SQL로 데이터 원상 복귀 검증:
```bash
mysql -h localhost -u SORITUNECOM_DEV_PT -p'Va4/7hdfj7oweLwMah3bHzXR' SORITUNECOM_DEV_PT -e "
SELECT coach_name, team_leader_id FROM coaches WHERE coach_name IN ('Lulu','Kel');
"
```
Expected: Lulu = Kel의 id, Kel = Kel의 id.

- [ ] **Step 9.8: Commit**

```bash
cd /root/pt-dev && \
git add public_html/admin/js/pages/coaches.js && \
git commit -m "$(cat <<'EOF'
feat(admin/coaches): 모달에 팀장/소속팀 입력 + this.coaches 캐시

- loadList 응답을 this.coaches에 저장 → showForm에서 팀장 옵션 생성
- "팀장 여부" 체크박스 + "소속 팀" 드롭다운 (active 팀장만)
- 체크 시 드롭다운 disabled (소속은 self 자동)
- submit 직렬화: is_team_leader=1이면 team_leader_id=null로 보냄 (서버가 self.id로 채움)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: 어드민 모달 — 카톡방 링크 입력 + 클라이언트 검증

**Files:**
- Modify: `public_html/admin/js/pages/coaches.js`

- [ ] **Step 10.1: 메모 그룹 위에 카톡방 입력 추가**

In the form HTML, find:
```js
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo">${UI.esc(coach.memo || '')}</textarea>
        </div>
```

Replace with:
```js
        <div class="form-group">
          <label class="form-label">1:1PT 카톡방 링크</label>
          <input class="form-input" type="url" name="kakao_room_url"
                 value="${UI.esc(coach.kakao_room_url || '')}"
                 placeholder="https://open.kakao.com/o/...">
          <div id="kakaoUrlError" style="display:none;color:var(--text-negative);font-size:12px;margin-top:4px"></div>
        </div>
        <div class="form-group">
          <label class="form-label">메모</label>
          <textarea class="form-textarea" name="memo">${UI.esc(coach.memo || '')}</textarea>
        </div>
```

- [ ] **Step 10.2: submit 직렬화 + 클라이언트 검증**

Find:
```js
      body.is_team_leader = form.elements.is_team_leader.checked ? 1 : 0;
      body.team_leader_id = body.is_team_leader
        ? null
        : (form.elements.team_leader_id.value || null);
      if (isEdit && !body.password) delete body.password;
```

Replace with:
```js
      body.is_team_leader = form.elements.is_team_leader.checked ? 1 : 0;
      body.team_leader_id = body.is_team_leader
        ? null
        : (form.elements.team_leader_id.value || null);
      body.kakao_room_url = (body.kakao_room_url || '').trim() || null;

      // 클라이언트 검증 (서버와 동일 정규식)
      const errEl = document.getElementById('kakaoUrlError');
      errEl.style.display = 'none';
      if (body.kakao_room_url) {
        const re = /^https:\/\/open\.kakao\.com\/(o|me)\/[A-Za-z0-9_]+$/;
        if (!re.test(body.kakao_room_url)) {
          errEl.textContent = '카톡방 링크 형식이 올바르지 않습니다 (https://open.kakao.com/o/... 또는 /me/...)';
          errEl.style.display = 'block';
          return;
        }
      }
      if (isEdit && !body.password) delete body.password;
```

- [ ] **Step 10.3: 브라우저 수동 검증**

DEV 어드민에서:
1. Kel 편집 → 카톡방 링크 input에 기존 URL `https://open.kakao.com/o/sHQgUU1e` 보임
2. URL을 `bad-url`로 바꾸고 저장 → 인라인 빨간 에러 메시지 표시, 저장 안 됨
3. URL을 비우고 저장 → 성공 (NULL 허용)
4. 원래 URL로 복원 → 성공

DB 확인:
```bash
mysql -h localhost -u SORITUNECOM_DEV_PT -p'Va4/7hdfj7oweLwMah3bHzXR' SORITUNECOM_DEV_PT -e "
SELECT coach_name, kakao_room_url FROM coaches WHERE coach_name='Kel';
"
```
Expected: 원래 URL 값.

- [ ] **Step 10.4: Commit**

```bash
cd /root/pt-dev && \
git add public_html/admin/js/pages/coaches.js && \
git commit -m "$(cat <<'EOF'
feat(admin/coaches): 모달에 카톡방 링크 입력 + 클라이언트 검증

- 메모 위 새 행: type=url input + 인라인 에러 div
- 정규식 ^https://open\.kakao\.com/(o|me)/[A-Za-z0-9_]+$ (서버와 동일)
- 빈 값은 null로 정규화하여 전송

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: 어드민 목록 표 — "팀" 컬럼 추가

**Files:**
- Modify: `public_html/admin/js/pages/coaches.js` (loadList의 표 HTML)

- [ ] **Step 11.1: 표 헤더에 "팀" 컬럼 추가**

Find the `<thead>` HTML in `loadList`:
```js
            <tr>
              <th>이름</th>
              <th>한글이름</th>
              <th>생년월일</th>
              <th>입사일</th>
              <th>직급</th>
              <th>평가</th>
```

Replace with:
```js
            <tr>
              <th>이름</th>
              <th>한글이름</th>
              <th>생년월일</th>
              <th>입사일</th>
              <th>직급</th>
              <th>팀</th>
              <th>평가</th>
```

- [ ] **Step 11.2: 표 body에 "팀" 셀 추가**

Find:
```js
            ${coaches.map(c => `
              <tr>
                <td>${UI.esc(c.coach_name)}</td>
                <td>${UI.esc(c.korean_name || '')}</td>
                <td>${UI.esc(c.birthdate || '-')}</td>
                <td>${UI.esc(c.hired_on || '-')}</td>
                <td>${UI.esc(c.role || '-')}</td>
                <td>${evalBadge(c.evaluation)}</td>
```

Replace with:
```js
            ${coaches.map(c => {
              const isLead = c.team_leader_id != null && Number(c.team_leader_id) === Number(c.id);
              const teamCell = c.team_leader_id == null
                ? '-'
                : (isLead
                    ? `<span style="color:#FF5E00;font-weight:700">★ ${UI.esc(c.team_leader_name)}팀</span>`
                    : `${UI.esc(c.team_leader_name)}팀`);
              return `
              <tr>
                <td>${UI.esc(c.coach_name)}</td>
                <td>${UI.esc(c.korean_name || '')}</td>
                <td>${UI.esc(c.birthdate || '-')}</td>
                <td>${UI.esc(c.hired_on || '-')}</td>
                <td>${UI.esc(c.role || '-')}</td>
                <td>${teamCell}</td>
                <td>${evalBadge(c.evaluation)}</td>
```

- [ ] **Step 11.3: row 닫는 `}` 추가 (map 콜백 변경 대응)**

Find the row closing `</tr>` and the map's `).join('')`:
```js
                <td>
                  <button class="btn btn-small btn-secondary" onclick="App.pages.coaches.showForm(${c.id})">편집</button>
                </td>
              </tr>
            `).join('')}
```

Replace with:
```js
                <td>
                  <button class="btn btn-small btn-secondary" onclick="App.pages.coaches.showForm(${c.id})">편집</button>
                </td>
              </tr>
            `;
            }).join('')}
```

(주의: map 콜백을 화살표 함수 단일 표현식 → block body로 바꿨으므로 `return` + 닫는 `}` 필요)

- [ ] **Step 11.4: 브라우저 수동 검증**

DEV 어드민 코치관리 페이지:
- Kel 행: "팀" 셀에 `★ Kel팀` (오렌지 강조)
- Lulu 행: "팀" 셀에 `Kel팀` (일반)
- inactive 코치들: "팀" 셀에 `-`
- 시드 적용된 active 30명 모두 적절히 표시

- [ ] **Step 11.5: Commit**

```bash
cd /root/pt-dev && \
git add public_html/admin/js/pages/coaches.js && \
git commit -m "$(cat <<'EOF'
feat(admin/coaches): 목록 표에 '팀' 컬럼 (팀장 ★ 강조)

- 직급과 평가 사이에 '팀' 컬럼 삽입
- 팀장: ★ Kel팀 (Soritune Orange)
- 팀원: Kel팀 (일반)
- 미배정: -

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: `coach_self.php` 신규 API

**Files:**
- Create: `public_html/api/coach_self.php`

- [ ] **Step 12.1: API 파일 작성**

Create `public_html/api/coach_self.php`:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireCoach();
$coachId = (int)$user['id'];  // 세션의 id만 신뢰. URL/POST 파라미터는 일체 사용 안 함.
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_info':
        // 본인 정보
        $stmt = $db->prepare("
            SELECT id, coach_name, korean_name, kakao_room_url, team_leader_id
            FROM coaches WHERE id = ?
        ");
        $stmt->execute([$coachId]);
        $self = $stmt->fetch();
        if (!$self) jsonError('코치 정보를 찾을 수 없습니다', 404);

        $isLeader = ($self['team_leader_id'] !== null
                     && (int)$self['team_leader_id'] === (int)$self['id']);
        $team = null;
        $members = null;

        if ($self['team_leader_id'] !== null) {
            $tl = $db->prepare("SELECT id, coach_name FROM coaches WHERE id = ?");
            $tl->execute([(int)$self['team_leader_id']]);
            $leader = $tl->fetch();
            if ($leader) {
                $team = [
                    'name'        => $leader['coach_name'] . '팀',
                    'leader_name' => $leader['coach_name'],
                ];
            }
        }

        $payload = [
            'self' => [
                'coach_name'     => $self['coach_name'],
                'korean_name'    => $self['korean_name'],
                'kakao_room_url' => $self['kakao_room_url'],
            ],
            'team'      => $team,
            'is_leader' => $isLeader,
        ];

        if ($isLeader) {
            // 같은 팀 멤버 (본인 포함 — UI에서 본인 행도 같이 보여주기 위함)
            $ms = $db->prepare("
                SELECT coach_name, korean_name, kakao_room_url
                FROM coaches
                WHERE team_leader_id = ?
                ORDER BY (id = ?) DESC, coach_name ASC
            ");
            $ms->execute([$coachId, $coachId]);
            $payload['members'] = $ms->fetchAll(PDO::FETCH_ASSOC);
        }

        jsonSuccess($payload);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 12.2: 수동 curl 검증 (코치 세션)**

브라우저에서 dev 코치 페이지 (`https://dev-pt.soritune.com/coach/`)로 코치(예: Kel) 로그인 후 개발자 도구 콘솔에서:
```js
fetch('/api/coach_self.php?action=get_info').then(r => r.json()).then(console.log)
```

Expected (Kel = 팀장):
```json
{
  "ok": true,
  "data": {
    "self": {"coach_name": "Kel", "korean_name": "...", "kakao_room_url": "https://open.kakao.com/o/sHQgUU1e"},
    "team": {"name": "Kel팀", "leader_name": "Kel"},
    "is_leader": true,
    "members": [
      {"coach_name": "Kel", ...},  -- 본인 첫 행
      {"coach_name": "Cera", ...},
      ...
    ]
  }
}
```

Lulu(팀원) 로그인 후 동일 호출:
```json
{
  "ok": true,
  "data": {
    "self": {"coach_name": "Lulu", ...},
    "team": {"name": "Kel팀", "leader_name": "Kel"},
    "is_leader": false
  }
}
```
(`members` 키 없음 — 팀원에겐 다른 팀원 정보 노출 안 됨)

비-코치 세션(어드민)으로 호출 시:
```json
{"ok": false, "message": "코치 로그인이 필요합니다"}
```
(401 응답)

- [ ] **Step 12.3: Commit**

```bash
cd /root/pt-dev && \
git add public_html/api/coach_self.php && \
git commit -m "$(cat <<'EOF'
feat(api): coach_self.php — 코치 본인 정보 조회

- requireCoach() 가드 + 세션 $user['id']만 신뢰
- get_info: self/team/is_leader 항상 반환
- 팀장이면 같은 팀 members 배열 추가 (본인 첫 행)
- 팀원에게는 다른 팀원의 카톡방 노출 안 함

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: 코치 사이드바 + "내 정보" 페이지

**Files:**
- Modify: `public_html/coach/index.php`
- Create: `public_html/coach/js/pages/my-info.js`

- [ ] **Step 13.1: 사이드바 메뉴 + 스크립트 등록**

In `public_html/coach/index.php`, find:
```html
    <nav class="sidebar-nav">
      <a href="#my-members" data-page="my-members">내 회원</a>
      <a href="#kakao-check" data-page="kakao-check">카톡방 입장 체크</a>
    </nav>
```

Replace with:
```html
    <nav class="sidebar-nav">
      <a href="#my-members" data-page="my-members">내 회원</a>
      <a href="#kakao-check" data-page="kakao-check">카톡방 입장 체크</a>
      <a href="#my-info" data-page="my-info">내 정보</a>
    </nav>
```

Find:
```html
<script src="/coach/js/app.js"></script>
<script src="/coach/js/pages/my-members.js"></script>
<script src="/coach/js/pages/kakao-check.js"></script>
<script src="/coach/js/pages/member-chart.js"></script>
```

Replace with:
```html
<script src="/coach/js/app.js"></script>
<script src="/coach/js/pages/my-members.js"></script>
<script src="/coach/js/pages/kakao-check.js"></script>
<script src="/coach/js/pages/member-chart.js"></script>
<script src="/coach/js/pages/my-info.js"></script>
```

- [ ] **Step 13.2: my-info.js 작성**

Create `public_html/coach/js/pages/my-info.js`:

```js
/**
 * Coach "내 정보" 페이지
 * - 본인 정보 (이름, 한글이름, 카톡방 링크 + 복사)
 * - 소속 팀 (팀명/팀장)
 * - 팀장에게만: 같은 팀원 명단 표
 */
CoachApp.registerPage('my-info', {
  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">내 정보</h1></div>
      <div id="myInfoContent"><div class="loading">불러오는 중...</div></div>
    `;
    const res = await API.get('/api/coach_self.php?action=get_info');
    if (!res.ok) {
      document.getElementById('myInfoContent').innerHTML =
        `<div class="empty-state">${UI.esc(res.message || '오류')}</div>`;
      return;
    }
    const { self, team, is_leader, members } = res.data;
    const teamLine = team
      ? `${UI.esc(team.name)} <span style="color:var(--text-secondary)">(팀장: ${UI.esc(team.leader_name)})</span>`
      : `<span style="color:var(--text-secondary)">미배정</span>`;
    const kakaoCell = self.kakao_room_url
      ? `<a href="${UI.esc(self.kakao_room_url)}" target="_blank" rel="noopener">${UI.esc(self.kakao_room_url)}</a>
         <button class="btn btn-small btn-secondary" style="margin-left:8px"
                 onclick="CoachApp.pages['my-info'].copy('${UI.esc(self.kakao_room_url)}')">복사</button>`
      : `<span style="color:var(--text-secondary)">미설정</span>`;

    let html = `
      <div class="card" style="padding:16px;margin-bottom:16px">
        <h2 style="font-size:18px;margin-bottom:12px">내 정보</h2>
        <div style="display:grid;grid-template-columns:120px 1fr;gap:8px 16px;font-size:14px">
          <div style="color:var(--text-secondary)">이름</div>     <div>${UI.esc(self.coach_name)}</div>
          <div style="color:var(--text-secondary)">한글이름</div> <div>${UI.esc(self.korean_name || '-')}</div>
          <div style="color:var(--text-secondary)">소속 팀</div>  <div>${teamLine}</div>
          <div style="color:var(--text-secondary)">카톡방</div>   <div>${kakaoCell}</div>
        </div>
      </div>
    `;

    if (is_leader && Array.isArray(members)) {
      html += `
        <div class="card" style="padding:16px">
          <h2 style="font-size:18px;margin-bottom:12px">우리 팀 코치 (${members.length}명)</h2>
          <div class="data-table-wrapper">
            <table class="data-table">
              <thead>
                <tr><th>이름</th><th>한글이름</th><th>카톡방 링크</th><th></th></tr>
              </thead>
              <tbody>
                ${members.map(m => {
                  const url = m.kakao_room_url || '';
                  return `
                    <tr>
                      <td>${UI.esc(m.coach_name)}</td>
                      <td>${UI.esc(m.korean_name || '-')}</td>
                      <td>${url
                          ? `<a href="${UI.esc(url)}" target="_blank" rel="noopener">${UI.esc(url)}</a>`
                          : '<span style="color:var(--text-secondary)">미설정</span>'}</td>
                      <td>${url
                          ? `<button class="btn btn-small btn-secondary"
                                onclick="CoachApp.pages['my-info'].copy('${UI.esc(url)}')">복사</button>`
                          : ''}</td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          </div>
        </div>
      `;
    }

    document.getElementById('myInfoContent').innerHTML = html;
  },

  async copy(text) {
    try {
      await navigator.clipboard.writeText(text);
      alert('복사되었습니다');
    } catch {
      // fallback
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      alert('복사되었습니다');
    }
  },
});
```

- [ ] **Step 13.3: 브라우저 수동 검증**

DEV 코치 페이지에서:

1. **Kel(팀장) 로그인 → "내 정보" 클릭**:
   - 내 정보 카드: 이름 Kel, 소속 팀 "Kel팀 (팀장: Kel)", 카톡방 URL + 복사 버튼
   - 우리 팀 코치 표 (10명): Kel 첫 행, 9명 팀원
   - 카톡방 미설정 행 없음 (Kel팀은 모두 URL 있음)
   - 복사 버튼 클릭 → 알림 + 클립보드 확인

2. **Lulu(팀원) 로그인 → "내 정보" 클릭**:
   - 내 정보 카드만 표시 (소속 팀 = "Kel팀 (팀장: Kel)")
   - "우리 팀 코치" 카드 **없음** ✓ (권한 분리 검증)

3. **Frida(카톡방 NULL) 로그인 → "내 정보" 클릭**:
   - 카톡방: "미설정" 회색 텍스트, 복사 버튼 없음

4. **(보안) Lulu 로그인한 상태에서 콘솔로 다른 코치 id를 강제 시도**:
   ```js
   fetch('/api/coach_self.php?action=get_info&id=6&coach_id=6').then(r => r.json()).then(console.log)
   ```
   Expected: 응답에서 self.coach_name === "Lulu" (URL 파라미터 무시되고 세션 id로만 조회됨)

- [ ] **Step 13.4: Commit**

```bash
cd /root/pt-dev && \
git add public_html/coach/index.php public_html/coach/js/pages/my-info.js && \
git commit -m "$(cat <<'EOF'
feat(coach): '내 정보' 페이지 신규

- 사이드바에 메뉴 추가 + my-info.js 등록
- 모든 코치: 본인 정보 + 소속 팀 카드
- 팀장만: 같은 팀 코치 명단 표 (이름/한글/카톡방+복사)
- 팀원에겐 다른 팀원의 카톡방 절대 노출 안 됨 (Q9-A)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: 통합 검증 + dev push 안내

**Files:** (없음 — 검증만)

- [ ] **Step 14.1: 전체 테스트 실행**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -5
```

Expected: 마지막 줄 `Total: ... Pass: N  Fail: 0`. 기존 테스트(auto_status, kakao_check, phone_corruption)도 모두 통과해야 함.

만약 실패하면 디버깅하고 수정 후 별도 commit.

- [ ] **Step 14.2: 어드민 시나리오 매뉴얼 체크리스트**

DEV 어드민 (`https://dev-pt.soritune.com/admin/`) 코치관리에서:

- [ ] 표 헤더에 "팀" 컬럼 보임 (직급과 평가 사이)
- [ ] Kel 행: `★ Kel팀` 오렌지 표시
- [ ] Lulu 행: `Kel팀` 일반 표시
- [ ] Frida 행: `Flora팀` (카톡방은 - 표시 없음, 카톡방 컬럼은 없음)
- [ ] inactive 코치: 팀 셀 `-`
- [ ] 신규 코치 추가 → 팀장 옵션 보임 → 미배정으로 저장 → 표에 "-"
- [ ] Lulu 편집 → 카톡방 URL을 잘못 입력 → 인라인 에러 → 저장 안 됨
- [ ] Lulu 편집 → 팀장 체크 후 저장 → Lulu가 팀장이 됨 (`★ Lulu팀`) → 다시 풀어 원상 복귀
- [ ] Kel 편집 → 팀장 체크 해제 시도 → "팀원 9명" 차단 메시지 (저장 안 됨)
- [ ] Kel 편집 → status를 inactive로 변경 시도 → 동일 차단

- [ ] **Step 14.3: 코치 시나리오 매뉴얼 체크리스트**

DEV 코치 (`https://dev-pt.soritune.com/coach/`)에서:

- [ ] Kel 로그인 → "내 정보" → 본인 카드 + 우리 팀 코치 표 (10명, Kel 첫 행)
- [ ] Lulu 로그인 → "내 정보" → 본인 카드만, 우리 팀 표 없음
- [ ] Frida 로그인 → "내 정보" → 카톡방 "미설정"
- [ ] 카톡방 복사 버튼 → 클립보드 복사 확인

- [ ] **Step 14.4: 사용자에게 dev push 안내**

다음 메시지를 사용자에게 전달:

> "전체 14 task 구현 + 검증 완료. DEV 환경에서 동작 확인 완료.
> - 마이그레이션: DEV DB 적용 완료
> - 테스트: 전체 PASS
> - 어드민/코치 시나리오: 모두 통과
>
> dev 브랜치로 push 진행해도 될까요? push 후 운영 반영은 별도 확인 요청드립니다."

사용자 확인 받고 `git push origin dev` 실행. 운영 반영은 사용자가 명시적으로 요청한 후 별도 절차.

---

## 후속 작업 (이번 plan 범위 외)

- 코치 본인의 카톡방 링크 자가 편집 (Q에서 "추후 고민" 명시)
- 팀장의 팀원 데이터 관리 화면 (현재는 명단 표시까지만)
- 알림톡 시나리오 정의 및 변수 매핑 (시나리오가 실제로 필요할 때 별도 spec)
