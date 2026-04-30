# 카톡방 입장 체크 탭 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PT 코치/어드민이 자기 회원의 카톡방 입장 여부를 월(코호트) 단위로 체크하는 탭을 추가한다. 어드민은 전체 조회 + bulk cohort 이동까지 가능.

**Architecture:** orders 테이블에 4개 컬럼(cohort_month, kakao_room_joined, _at, _by) + 2개 인덱스 추가. 단일 API 파일(`/api/kakao_check.php`) 하나가 4개 액션(cohorts/list/toggle_join/set_cohort)을 처리. 코치/어드민 권한은 액션마다 `requireAnyAuth()` 후 role 분기. 프론트는 PT 기존 SPA 패턴(`CoachApp.registerPage` / `App.registerPage`) 재사용.

**Tech Stack:** PHP 8 (no framework), MySQL/PDO, vanilla JS SPA, `change_logs` 재사용으로 audit, IF NOT EXISTS 마이그레이션.

**Spec:** `docs/superpowers/specs/2026-04-29-kakao-room-check-design.md`

**작업 디렉토리:** `/var/www/html/_______site_SORITUNECOM_PT/` (PT 운영 사이트, DEV/PROD 분리 없음). PT는 main 단일 브랜치.

**중요 — 다른 작업과의 충돌 회피:**
PT 레포에는 다른 진행중 작업(매칭/리텐션/notify/status)의 unstaged/untracked 파일이 있을 수 있음. 모든 commit은 명시 파일 경로로만 staging (`git add <path>` 사용, `git add -A` / `git add .` 절대 금지).

---

## 파일 구조 개요

### 새로 생성
```
migrations/
  20260430_add_kakao_room_check.sql              # orders 컬럼 4개 + 인덱스 2개 (idempotent)
public_html/api/
  kakao_check.php                                # 4개 액션 라우터
public_html/coach/js/pages/
  kakao-check.js                                 # 코치 SPA 페이지
public_html/admin/js/pages/
  kakao-check.js                                 # 어드민 SPA 페이지 (코치 필터 + bulk bar)
tests/
  kakao_check_test.php                           # API 단위 테스트
```

### 수정
- `public_html/coach/index.php` — sidebar `<a>` 1줄 + `<script>` 1줄
- `public_html/admin/index.php` — sidebar `<a>` 1줄 + `<script>` 1줄
- `schema.sql` — orders 테이블 정의에 컬럼 4개 + 인덱스 2개 추가 (선언형 사본)

기존 테이블/컬럼 변경: `orders`에만 컬럼 4개, 인덱스 2개 추가. `change_logs` ENUM 변경 없음 (기존 'order' 재사용).

---

## 사전 준비 (실행 전 1회)

- [ ] **현재 git 상태 확인 — 다른 작업의 변경 사항 식별**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git status --short
git log --oneline -3
```

Expected: branch=main, HEAD에 `196b4e2 docs(spec): 카톡방 입장 체크 탭 설계` (스펙 커밋)이 보여야 함. unstaged/untracked 파일이 있어도 무시 — 본 작업의 commit은 명시 파일 경로로만 staging.

- [ ] **DB 접속 가능 확인**

Run:
```bash
sudo -u apache php -r 'require "/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/db.php"; var_dump(getDB()->query("SELECT 1")->fetch());'
```

Expected: `array(2) { [0]=> string(1) "1" ["1"]=> string(1) "1" }` 비슷한 출력.

- [ ] **orders 테이블 현재 컬럼 스냅샷 (마이그 전후 비교용)**

Run:
```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT -e "SHOW COLUMNS FROM orders" | grep -E "cohort_month|kakao_room"
```

Expected: 출력 없음 (아직 컬럼 없는 상태). 만약 무언가 나오면 즉시 STOP하고 사용자에게 문의 — 마이그레이션이 이미 적용되었거나 다른 작업과 충돌.

---

## Task 1: 마이그레이션 SQL 작성 + 적용

**Files:**
- Create: `migrations/20260430_add_kakao_room_check.sql`

- [ ] **Step 1: 마이그 SQL 파일 작성**

`migrations/20260430_add_kakao_room_check.sql` 생성, 내용:

```sql
-- 2026-04-30: 카톡방 입장 체크 — orders 테이블 컬럼 4개 + 인덱스 2개 추가
-- Spec: docs/superpowers/specs/2026-04-29-kakao-room-check-design.md
--
-- 적용:
--   mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT < migrations/20260430_add_kakao_room_check.sql
--
-- 멱등성: ALTER ... ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS 사용 (MySQL 8+).
-- 롤백: 수동 (DROP COLUMN / DROP INDEX). 자동 롤백 스크립트 없음.

SET NAMES utf8mb4;

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `cohort_month` CHAR(7) DEFAULT NULL
    COMMENT 'YYYY-MM. NULL이면 자동(DATE_FORMAT(start_date,"%Y-%m")). 명시값은 admin override.',
  ADD COLUMN IF NOT EXISTS `kakao_room_joined` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `kakao_room_joined_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `kakao_room_joined_by` INT DEFAULT NULL
    COMMENT 'coach.id 또는 admin.id (actor 구분은 change_logs로). FK 없음 — 직 삭제 시 무관.',
  ADD INDEX IF NOT EXISTS `idx_cohort_month` (`cohort_month`),
  ADD INDEX IF NOT EXISTS `idx_kakao_room` (`kakao_room_joined`);
```

- [ ] **Step 2: 마이그 적용**

Run:
```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT < /var/www/html/_______site_SORITUNECOM_PT/migrations/20260430_add_kakao_room_check.sql
```

Expected: 출력 없음 (성공). 에러 시 STOP하고 사용자에게 문의.

- [ ] **Step 3: 적용 검증 — 컬럼 4개 존재**

Run:
```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT -e "SHOW COLUMNS FROM orders" | grep -E "cohort_month|kakao_room"
```

Expected (4행):
```
cohort_month            char(7)        YES        NULL
kakao_room_joined       tinyint(1)     NO         0
kakao_room_joined_at    datetime       YES        NULL
kakao_room_joined_by    int            YES        NULL
```

- [ ] **Step 4: 적용 검증 — 인덱스 2개 존재**

Run:
```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT -e "SHOW INDEX FROM orders" | grep -E "idx_cohort_month|idx_kakao_room"
```

Expected (2행):
```
orders   1   idx_cohort_month   1   cohort_month        ...
orders   1   idx_kakao_room     1   kakao_room_joined   ...
```

- [ ] **Step 5: 멱등성 검증 — 두 번째 실행도 에러 없음**

Run:
```bash
mysql --defaults-file=/root/.my.cnf SORITUNECOM_PT < /var/www/html/_______site_SORITUNECOM_PT/migrations/20260430_add_kakao_room_check.sql && echo OK
```

Expected: `OK` (warning 메시지가 나와도 성공으로 종료).

- [ ] **Step 6: schema.sql 선언형 사본 갱신**

`schema.sql`에서 `CREATE TABLE \`orders\`` 블록을 찾아, `status` 컬럼 정의 다음에 4개 컬럼을 추가. 같은 파일의 `KEY` 정의 끝에 인덱스 2개 추가.

확인 명령 (편집 위치 찾기용):
```bash
grep -n "CREATE TABLE \`orders\`\|^  \`status\`\|^  KEY " /var/www/html/_______site_SORITUNECOM_PT/schema.sql | head -20
```

`Edit` 툴로 orders 테이블 블록을 수정. 정확한 컬럼/인덱스 정의:

컬럼 추가 (status 컬럼 직후 적절한 위치):
```sql
  `cohort_month` CHAR(7) DEFAULT NULL,
  `kakao_room_joined` TINYINT(1) NOT NULL DEFAULT 0,
  `kakao_room_joined_at` DATETIME DEFAULT NULL,
  `kakao_room_joined_by` INT DEFAULT NULL,
```

인덱스 추가 (orders 테이블의 마지막 KEY 정의 뒤):
```sql
  KEY `idx_cohort_month` (`cohort_month`),
  KEY `idx_kakao_room` (`kakao_room_joined`),
```

확인:
```bash
grep -A1 "cohort_month\|idx_kakao_room" /var/www/html/_______site_SORITUNECOM_PT/schema.sql | head -15
```

Expected: 추가한 라인이 모두 출력.

- [ ] **Step 7: 커밋**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add migrations/20260430_add_kakao_room_check.sql schema.sql
git commit -m "feat(kakao-check): orders 컬럼 4개 + 인덱스 2개 마이그레이션"
```

Expected: 1 file changed (mig) + 1 file changed (schema). 다른 untracked는 staging되지 않음.

---

## Task 2: API 라우터 스켈레톤

**Files:**
- Create: `public_html/api/kakao_check.php`

이 태스크는 4개 액션의 케이스 라우터만 만들고 각 케이스는 `jsonError('TODO', 501)` 반환. 다음 태스크에서 케이스 하나씩 채운다.

- [ ] **Step 1: API 파일 작성**

`public_html/api/kakao_check.php` 생성:

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'cohorts':
        // GET ?action=cohorts[&coach_id=N]  — 데이터 있는 cohort 월 목록
        jsonError('TODO: implement cohorts', 501);

    case 'list':
        // GET ?action=list&cohort=YYYY-MM[&product=...&include_joined=0|1&coach_id=N]
        jsonError('TODO: implement list', 501);

    case 'toggle_join':
        // POST ?action=toggle_join  body={order_id, joined}
        jsonError('TODO: implement toggle_join', 501);

    case 'set_cohort':
        // POST ?action=set_cohort  body={order_ids:[], cohort_month:"YYYY-MM"|null}  — admin only
        jsonError('TODO: implement set_cohort', 501);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

- [ ] **Step 2: 스켈레톤 동작 검증**

Run:
```bash
sudo -u apache php -r '
require "/var/www/html/_______site_SORITUNECOM_PT/public_html/includes/helpers.php";
$_GET["action"] = "unknown";
ob_start();
try { include "/var/www/html/_______site_SORITUNECOM_PT/public_html/api/kakao_check.php"; } catch (Exception $e) {}
echo ob_get_clean();
'
```

(권한 체크 때문에 PHP CLI로는 401이 먼저 떨어진다. 스켈레톤 파일이 syntax 에러 없이 require 되는지만 확인.)

Run (syntax 검사):
```bash
php -l /var/www/html/_______site_SORITUNECOM_PT/public_html/api/kakao_check.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: 커밋**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/kakao_check.php
git commit -m "feat(kakao-check): API 라우터 스켈레톤"
```

---

## Task 3: 테스트 파일 스켈레톤 + smoke

**Files:**
- Create: `tests/kakao_check_test.php`

PT 테스트 패턴(`tests/_bootstrap.php`의 `t_section`/`t_assert_eq`/`t_make_order`/`t_summary`)을 그대로 따라간다. `t_make_order` fixture가 트랜잭션 안에서 member+order 만들고 caller가 ROLLBACK으로 정리.

- [ ] **Step 1: 테스트 파일 스켈레톤 작성**

`tests/kakao_check_test.php` 생성:

```php
<?php
declare(strict_types=1);

t_section('kakao_check smoke');
t_assert_eq(2, 1 + 1, '1+1 == 2');

$db = getDB();

// 이후 태스크들이 여기에 cohorts / list / toggle_join / set_cohort 섹션을 추가한다.
```

- [ ] **Step 2: 러너 실행 — 다른 테스트와 함께 smoke 통과 확인**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected:
```
>>> auto_status_transition_test.php
... (기존 테스트 PASS들)
>>> kakao_check_test.php
=== kakao_check smoke ===
  PASS  1+1 == 2
----
Total: N  Pass: N  Fail: 0
```

기존 테스트가 깨지면 STOP — 마이그레이션이 기존 fixture/order 트랜잭션 동작을 망가뜨렸다는 신호 (`t_make_order`가 새 NOT NULL 컬럼 default를 쓰는지 검증 필요). 진단 후 수정.

- [ ] **Step 3: 커밋**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add tests/kakao_check_test.php
git commit -m "test(kakao-check): 테스트 파일 스켈레톤 + smoke"
```

---

## Task 4: API `cohorts` 액션 — TDD

**Files:**
- Modify: `public_html/api/kakao_check.php` (cohorts case 채우기)
- Modify: `tests/kakao_check_test.php` (테스트 추가)

스펙 §4.1: 코치는 자기 order만, admin은 전체. status IN ('진행중', '매칭완료'). cohort = COALESCE(cohort_month, DATE_FORMAT(start_date, '%Y-%m')). DISTINCT, ORDER BY cohort.

본 태스크의 API는 직접 호출이 까다로우므로(웹 진입점 + 세션) **서비스 함수로 분리**해서 테스트 가능하게 한다:
- `kakao_check.php` 안에 `function kakaoCheckCohorts(PDO $db, ?int $coachId): array` 정의 → 액션은 이걸 호출.

- [ ] **Step 1: 실패 테스트 작성 — coach scope**

`tests/kakao_check_test.php`에 추가:

```php
require_once __DIR__ . '/../public_html/api/kakao_check.php'; // 함수만 로드 (라우터 동작 없음)

t_section('cohorts — coach scope');

$db->beginTransaction();
$activeCoach = (int)$db->query("SELECT id FROM coaches WHERE status='active' LIMIT 1")->fetchColumn();
$otherCoach = (int)$db->query("SELECT id FROM coaches WHERE status='active' AND id != {$activeCoach} LIMIT 1")->fetchColumn();

$o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-15', 'end_date' => '2026-07-14']);
$o2 = t_make_order($db, ['coach_id' => $otherCoach, 'status' => '진행중', 'start_date' => '2026-05-01', 'end_date' => '2026-07-31']);
$o3 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '종료', 'start_date' => '2026-03-01', 'end_date' => '2026-04-01']); // 제외 대상

$cohorts = kakaoCheckCohorts($db, $activeCoach);
t_assert_eq(['2026-04'], $cohorts, 'coach scope: 본인 진행중 order만 cohort에 등장');

$db->rollBack();
```

`kakao_check.php`를 라우터 진입점에서 함수만 추출 가능한 구조로 바꿔야 한다는 의미. 그 변경은 다음 단계에서.

- [ ] **Step 2: 라우터 파일 분리 — 함수 정의 + 진입점 분리**

`public_html/api/kakao_check.php`를 다음과 같이 재작성 — 함수 4개를 파일 상단에 정의하고, 라우터는 하단에 둔다. **단, 라우터 부분은 `if (PHP_SAPI !== 'cli' && !defined('KAKAO_CHECK_LIB_ONLY'))` 가드로 감싸 require 시 동작하지 않게 한다:**

```php
<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * 데이터 있는 cohort 월 목록 (status IN '진행중','매칭완료')
 *
 * @param int|null $coachId  null이면 전체 (admin), 정수면 해당 코치만
 * @return string[]  ['2026-04', '2026-05', ...]
 */
function kakaoCheckCohorts(PDO $db, ?int $coachId): array
{
    $where = ["o.status IN ('진행중', '매칭완료')"];
    $params = [];
    if ($coachId !== null) {
        $where[] = "o.coach_id = ?";
        $params[] = $coachId;
    }
    $sql = "
        SELECT DISTINCT COALESCE(cohort_month, DATE_FORMAT(start_date, '%Y-%m')) AS cohort
        FROM orders o
        WHERE " . implode(' AND ', $where) . "
        ORDER BY cohort
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// --- 라우터 진입점 (lib only 모드에서는 스킵) ---
if (defined('KAKAO_CHECK_LIB_ONLY')) return;

header('Content-Type: application/json; charset=utf-8');
$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'cohorts':
        $coachId = null;
        if ($user['role'] === 'coach') {
            $coachId = (int)$user['id'];
        } elseif ($user['role'] === 'admin' && !empty($_GET['coach_id'])) {
            $coachId = (int)$_GET['coach_id'];
        }
        jsonSuccess(['cohorts' => kakaoCheckCohorts($db, $coachId)]);

    case 'list':
        jsonError('TODO: implement list', 501);

    case 'toggle_join':
        jsonError('TODO: implement toggle_join', 501);

    case 'set_cohort':
        jsonError('TODO: implement set_cohort', 501);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
```

테스트 파일도 라우터 실행을 막기 위해 require 전에 `define('KAKAO_CHECK_LIB_ONLY', true);`를 추가:

`tests/kakao_check_test.php` 상단에 추가 (이미 추가된 require 라인 직전):
```php
define('KAKAO_CHECK_LIB_ONLY', true);
require_once __DIR__ . '/../public_html/api/kakao_check.php';
```

- [ ] **Step 3: 테스트 실행 — coach scope PASS 확인**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected: `cohorts — coach scope` 섹션의 assertion PASS.

만약 test 환경에 active 코치가 2명 이상 없으면 `$otherCoach`가 0이 되어 deadlock 가능 — 그 경우 위 fixture에서 `$otherCoach`가 0인지 체크해 SKIP 처리하는 가드 추가:
```php
if ($otherCoach === 0) {
    echo "  SKIP  coach scope (active 코치 2명 미만)\n";
} else {
    // 위 t_make_order + assertion
}
$db->rollBack();
```

- [ ] **Step 4: 추가 테스트 — admin scope (coach_id=null)**

`tests/kakao_check_test.php`에 추가:
```php
t_section('cohorts — admin scope');

$db->beginTransaction();
$o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-06-15', 'end_date' => '2026-09-14']);
$o2 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '매칭완료', 'start_date' => '2026-07-01', 'end_date' => '2026-09-30']);

$cohorts = kakaoCheckCohorts($db, null); // admin = 전체
t_assert_true(in_array('2026-06', $cohorts, true), 'admin scope: 2026-06 포함');
t_assert_true(in_array('2026-07', $cohorts, true), 'admin scope: 2026-07 포함');

$db->rollBack();
```

- [ ] **Step 5: 추가 테스트 — cohort_month override 우선**

`tests/kakao_check_test.php`에 추가:
```php
t_section('cohorts — cohort_month override 우선');

$db->beginTransaction();
$o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-28', 'end_date' => '2026-07-27']);
$db->prepare("UPDATE orders SET cohort_month='2026-05' WHERE id=?")->execute([$o]);

$cohorts = kakaoCheckCohorts($db, $activeCoach);
t_assert_true(in_array('2026-05', $cohorts, true), 'override 값이 effective_cohort에 반영');
t_assert_true(!in_array('2026-04', $cohorts, true), 'override가 있으면 자동 분류는 사라짐');

$db->rollBack();
```

- [ ] **Step 6: 테스트 실행 — 전체 PASS 확인**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected: kakao_check 섹션의 모든 assertion PASS, Fail: 0.

- [ ] **Step 7: 커밋**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/kakao_check.php tests/kakao_check_test.php
git commit -m "feat(kakao-check): cohorts API + 테스트"
```

---

## Task 5: API `list` 액션 — TDD

**Files:**
- Modify: `public_html/api/kakao_check.php` (list 케이스 + 함수 추가)
- Modify: `tests/kakao_check_test.php` (테스트 추가)

스펙 §4.2:
- 입력: `cohort=YYYY-MM` 필수, `product`/`include_joined`/`coach_id` 옵션
- 응답: `{ orders: [...], products: [...] }`
- `display_status`: 매칭완료 → "진행예정" 매핑 (행 단위 단순 CASE)
- `products`: 같은 cohort/coach scope, **product 필터는 무시** (드롭다운 옵션 전체 보여야 함)
- 정렬: `start_date ASC, name ASC`

- [ ] **Step 1: 실패 테스트 작성 — 기본 list**

`tests/kakao_check_test.php`에 추가:
```php
t_section('list — 기본 list (include_joined=0)');

$db->beginTransaction();
$o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09', 'product_name' => 'Speaking 3개월']);
$o2 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-20', 'end_date' => '2026-07-19', 'product_name' => 'Listening 3개월']);
$o3 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-15', 'end_date' => '2026-07-14', 'product_name' => 'Speaking 3개월']);
$db->prepare("UPDATE orders SET kakao_room_joined=1, kakao_room_joined_at=NOW() WHERE id=?")->execute([$o3]);

$result = kakaoCheckList($db, [
    'cohort' => '2026-04',
    'coach_id' => $activeCoach,
    'include_joined' => false,
    'product' => null,
]);

t_assert_eq(2, count($result['orders']), 'include_joined=false면 체크된 행 제외 → 2건');
t_assert_eq($o1, (int)$result['orders'][0]['order_id'], '정렬: start_date ASC → o1 첫번째');
t_assert_eq(2, count($result['products']), 'products: 체크된 것 포함 distinct 2종');
t_assert_true(in_array('Speaking 3개월', $result['products'], true), 'products에 Speaking 포함');
t_assert_true(in_array('Listening 3개월', $result['products'], true), 'products에 Listening 포함');

$db->rollBack();
```

- [ ] **Step 2: `kakaoCheckList` 함수 구현**

`public_html/api/kakao_check.php`에 `kakaoCheckCohorts` 다음에 추가:

```php
/**
 * 특정 cohort의 order 리스트 + product distinct 목록.
 *
 * @param array{cohort:string, coach_id:?int, include_joined:bool, product:?string} $opts
 * @return array{orders:array, products:string[]}
 */
function kakaoCheckList(PDO $db, array $opts): array
{
    $cohort = $opts['cohort'];
    $coachId = $opts['coach_id'] ?? null;
    $includeJoined = !empty($opts['include_joined']);
    $product = $opts['product'] ?? null;

    // ---- products 리스트 (product 필터 무시, scope만 적용) ----
    $pWhere = [
        "o.status IN ('진행중', '매칭완료')",
        "COALESCE(o.cohort_month, DATE_FORMAT(o.start_date, '%Y-%m')) = ?",
    ];
    $pParams = [$cohort];
    if ($coachId !== null) {
        $pWhere[] = "o.coach_id = ?";
        $pParams[] = $coachId;
    }
    if (!$includeJoined) {
        $pWhere[] = "o.kakao_room_joined = 0";
    }
    $pSql = "
        SELECT DISTINCT o.product_name
        FROM orders o
        WHERE " . implode(' AND ', $pWhere) . "
          AND o.product_name IS NOT NULL AND o.product_name != ''
        ORDER BY o.product_name
    ";
    $stmt = $db->prepare($pSql);
    $stmt->execute($pParams);
    $products = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // ---- orders 리스트 ----
    $oWhere = $pWhere;
    $oParams = $pParams;
    if ($product !== null && $product !== '') {
        $oWhere[] = "o.product_name = ?";
        $oParams[] = $product;
    }
    $oSql = "
        SELECT
          o.id AS order_id,
          o.member_id,
          m.name,
          m.phone,
          m.email,
          o.product_name,
          o.start_date,
          o.end_date,
          o.status,
          CASE WHEN o.status = '매칭완료' THEN '진행예정' ELSE o.status END AS display_status,
          o.cohort_month AS cohort_month_override,
          COALESCE(o.cohort_month, DATE_FORMAT(o.start_date, '%Y-%m')) AS effective_cohort,
          o.kakao_room_joined,
          o.kakao_room_joined_at,
          o.coach_id,
          c.coach_name
        FROM orders o
        JOIN members m ON m.id = o.member_id
        LEFT JOIN coaches c ON c.id = o.coach_id
        WHERE " . implode(' AND ', $oWhere) . "
        ORDER BY o.start_date ASC, m.name ASC
    ";
    $stmt = $db->prepare($oSql);
    $stmt->execute($oParams);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['orders' => $orders, 'products' => $products];
}
```

- [ ] **Step 3: 라우터 list 케이스 채우기**

`public_html/api/kakao_check.php`의 `case 'list':` 부분 교체:

```php
    case 'list':
        $cohort = trim($_GET['cohort'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $cohort)) {
            jsonError('cohort 파라미터가 필요합니다 (YYYY-MM)');
        }
        $coachId = null;
        if ($user['role'] === 'coach') {
            $coachId = (int)$user['id'];
        } elseif ($user['role'] === 'admin' && !empty($_GET['coach_id'])) {
            $coachId = (int)$_GET['coach_id'];
        }
        $result = kakaoCheckList($db, [
            'cohort' => $cohort,
            'coach_id' => $coachId,
            'include_joined' => !empty($_GET['include_joined']) && $_GET['include_joined'] !== '0',
            'product' => trim($_GET['product'] ?? '') ?: null,
        ]);
        jsonSuccess($result);
```

- [ ] **Step 4: 테스트 실행 — 기본 list PASS**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected: list 섹션 모두 PASS.

- [ ] **Step 5: 추가 테스트 — include_joined=true, override, product 필터**

`tests/kakao_check_test.php`에 추가:
```php
t_section('list — include_joined=true 시 체크된 행 등장');

$db->beginTransaction();
$o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
$o2 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-15', 'end_date' => '2026-07-14']);
$db->prepare("UPDATE orders SET kakao_room_joined=1 WHERE id=?")->execute([$o2]);

$result = kakaoCheckList($db, ['cohort' => '2026-04', 'coach_id' => $activeCoach, 'include_joined' => true, 'product' => null]);
$ids = array_map(fn($r) => (int)$r['order_id'], $result['orders']);
t_assert_true(in_array($o1, $ids, true), 'include_joined=true: o1 (체크안됨) 포함');
t_assert_true(in_array($o2, $ids, true), 'include_joined=true: o2 (체크됨) 포함');

$db->rollBack();

t_section('list — cohort_month override가 effective_cohort 반영');

$db->beginTransaction();
$o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-28', 'end_date' => '2026-07-27']);
$db->prepare("UPDATE orders SET cohort_month='2026-05' WHERE id=?")->execute([$o]);

$april = kakaoCheckList($db, ['cohort' => '2026-04', 'coach_id' => $activeCoach, 'include_joined' => false, 'product' => null]);
$may = kakaoCheckList($db, ['cohort' => '2026-05', 'coach_id' => $activeCoach, 'include_joined' => false, 'product' => null]);
$aprilIds = array_map(fn($r) => (int)$r['order_id'], $april['orders']);
$mayIds = array_map(fn($r) => (int)$r['order_id'], $may['orders']);
t_assert_true(!in_array($o, $aprilIds, true), 'override된 order는 4월에서 사라짐');
t_assert_true(in_array($o, $mayIds, true), 'override된 order는 5월에 등장');

$db->rollBack();

t_section('list — product 필터');

$db->beginTransaction();
$os = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09', 'product_name' => 'Speaking']);
$ol = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-15', 'end_date' => '2026-07-14', 'product_name' => 'Listening']);

$result = kakaoCheckList($db, ['cohort' => '2026-04', 'coach_id' => $activeCoach, 'include_joined' => false, 'product' => 'Speaking']);
$ids = array_map(fn($r) => (int)$r['order_id'], $result['orders']);
t_assert_true(in_array($os, $ids, true), 'Speaking 필터: os 포함');
t_assert_true(!in_array($ol, $ids, true), 'Speaking 필터: ol 제외');
t_assert_eq(2, count($result['products']), 'products는 product 필터 무시 — 여전히 2종');

$db->rollBack();
```

- [ ] **Step 6: 테스트 실행 — 전체 PASS**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected: 모든 list 테스트 PASS.

- [ ] **Step 7: 커밋**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/kakao_check.php tests/kakao_check_test.php
git commit -m "feat(kakao-check): list API + 테스트"
```

---

## Task 6: API `toggle_join` 액션 — TDD

**Files:**
- Modify: `public_html/api/kakao_check.php` (toggle_join 케이스 + 함수 추가)
- Modify: `tests/kakao_check_test.php` (테스트 추가)

스펙 §4.3:
- 코치: 본인 order만, 그 외 403
- admin: 전부 허용
- joined=true → joined=1, joined_at=NOW(), joined_by=user.id
- joined=false → joined=0, joined_at=NULL, joined_by=NULL
- idempotent (no-op도 200)
- audit: `change_logs(target_type='order', action='kakao_room_join'|'kakao_room_unjoin', actor_type=user.role, actor_id=user.id, old/new JSON)`

권한 체크는 라우터에서. 함수는 권한 체크 통과한 후의 update + audit만 담당.

- [ ] **Step 1: 실패 테스트 — 기본 toggle (체크 ON)**

`tests/kakao_check_test.php`에 추가:
```php
t_section('toggle_join — 기본 ON');

$db->beginTransaction();
$o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
$adminId = (int)$db->query("SELECT id FROM admins LIMIT 1")->fetchColumn();

kakaoCheckToggle($db, $o, true, 'admin', $adminId);

$row = $db->query("SELECT kakao_room_joined, kakao_room_joined_at, kakao_room_joined_by FROM orders WHERE id={$o}")->fetch();
t_assert_eq(1, (int)$row['kakao_room_joined'], 'joined=1');
t_assert_true($row['kakao_room_joined_at'] !== null, 'joined_at NOT NULL');
t_assert_eq($adminId, (int)$row['kakao_room_joined_by'], 'joined_by = adminId');

$log = $db->query("SELECT action, actor_type, actor_id, old_value, new_value FROM change_logs WHERE target_type='order' AND target_id={$o} ORDER BY id DESC LIMIT 1")->fetch();
t_assert_eq('kakao_room_join', $log['action'], 'log action = kakao_room_join');
t_assert_eq('admin', $log['actor_type'], 'log actor_type = admin');
t_assert_eq($adminId, (int)$log['actor_id'], 'log actor_id = adminId');

$db->rollBack();
```

- [ ] **Step 2: `kakaoCheckToggle` 구현**

`public_html/api/kakao_check.php`에 추가:

```php
/**
 * order의 kakao_room_joined 토글. 권한 체크는 caller 책임.
 *
 * @param bool   $joined     true=ON / false=OFF
 * @param string $actorType  'admin' | 'coach'
 * @param int    $actorId    user.id
 * @return bool  실제로 값이 바뀌었는지 (false면 no-op)
 */
function kakaoCheckToggle(PDO $db, int $orderId, bool $joined, string $actorType, int $actorId): bool
{
    $current = $db->prepare("SELECT kakao_room_joined FROM orders WHERE id = ?");
    $current->execute([$orderId]);
    $row = $current->fetch();
    if (!$row) {
        return false;
    }
    $oldVal = (int)$row['kakao_room_joined'];
    $newVal = $joined ? 1 : 0;
    if ($oldVal === $newVal) {
        return false; // idempotent no-op
    }
    if ($joined) {
        $db->prepare("
            UPDATE orders
               SET kakao_room_joined = 1,
                   kakao_room_joined_at = NOW(),
                   kakao_room_joined_by = ?
             WHERE id = ?
        ")->execute([$actorId, $orderId]);
        $action = 'kakao_room_join';
    } else {
        $db->prepare("
            UPDATE orders
               SET kakao_room_joined = 0,
                   kakao_room_joined_at = NULL,
                   kakao_room_joined_by = NULL
             WHERE id = ?
        ")->execute([$orderId]);
        $action = 'kakao_room_unjoin';
    }
    logChange($db, 'order', $orderId, $action,
        ['kakao_room_joined' => $oldVal],
        ['kakao_room_joined' => $newVal],
        $actorType, $actorId);
    return true;
}
```

- [ ] **Step 3: 라우터 toggle_join 케이스 채우기**

`public_html/api/kakao_check.php`의 `case 'toggle_join':` 교체:

```php
    case 'toggle_join':
        $input = getJsonInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $joined = !empty($input['joined']);
        if (!$orderId) jsonError('order_id가 필요합니다');

        // 코치 권한: 본인 order만 (admin은 통과)
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT coach_id FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $row = $stmt->fetch();
            if (!$row) jsonError('order를 찾을 수 없습니다', 404);
            if ((int)$row['coach_id'] !== (int)$user['id']) {
                jsonError('권한이 없습니다', 403);
            }
        }

        kakaoCheckToggle($db, $orderId, $joined, $user['role'], (int)$user['id']);
        jsonSuccess(['joined' => $joined ? 1 : 0]);
```

- [ ] **Step 4: 테스트 실행 — 기본 ON PASS**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected: `toggle_join — 기본 ON` PASS.

- [ ] **Step 5: 추가 테스트 — OFF, idempotent, change_logs 미생성(no-op)**

`tests/kakao_check_test.php`에 추가:
```php
t_section('toggle_join — OFF + idempotent');

$db->beginTransaction();
$o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
$db->prepare("UPDATE orders SET kakao_room_joined=1, kakao_room_joined_at=NOW(), kakao_room_joined_by=999 WHERE id=?")->execute([$o]);

// OFF로 토글
$changed = kakaoCheckToggle($db, $o, false, 'coach', $activeCoach);
t_assert_true($changed, 'OFF 토글 — 값 바뀜');
$row = $db->query("SELECT kakao_room_joined, kakao_room_joined_at, kakao_room_joined_by FROM orders WHERE id={$o}")->fetch();
t_assert_eq(0, (int)$row['kakao_room_joined'], 'joined=0');
t_assert_true($row['kakao_room_joined_at'] === null, 'joined_at = NULL');
t_assert_true($row['kakao_room_joined_by'] === null, 'joined_by = NULL');

// 같은 값으로 다시 호출 → no-op
$logCountBefore = (int)$db->query("SELECT COUNT(*) FROM change_logs WHERE target_type='order' AND target_id={$o}")->fetchColumn();
$changed2 = kakaoCheckToggle($db, $o, false, 'coach', $activeCoach);
$logCountAfter = (int)$db->query("SELECT COUNT(*) FROM change_logs WHERE target_type='order' AND target_id={$o}")->fetchColumn();
t_assert_eq(false, $changed2, 'idempotent: 같은 값 재호출 false');
t_assert_eq($logCountBefore, $logCountAfter, 'idempotent: change_logs 추가 없음');

$db->rollBack();
```

- [ ] **Step 6: 라우터 권한 분기는 단위 테스트 곤란 → 수동 검증 메모만**

라우터의 코치 403 / admin 통과 분기는 PHP CLI에서 세션을 흉내내기 어려우므로, Task 17(수동 smoke)에서 브라우저로 확인. 본 태스크에서는 함수 단위 테스트만.

- [ ] **Step 7: 테스트 실행 — 전체 PASS**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected: 모두 PASS.

- [ ] **Step 8: 커밋**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/kakao_check.php tests/kakao_check_test.php
git commit -m "feat(kakao-check): toggle_join API + 테스트"
```

---

## Task 7: API `set_cohort` 액션 — TDD

**Files:**
- Modify: `public_html/api/kakao_check.php` (set_cohort 케이스 + 함수 추가)
- Modify: `tests/kakao_check_test.php` (테스트 추가)

스펙 §4.4:
- admin only (코치 403)
- `cohort_month`이 null이거나 `^\d{4}-\d{2}$`
- 트랜잭션으로 일괄 UPDATE
- 각 order당 audit: `change_logs(target_type='order', action='cohort_month_set', old/new)`
- `cohort_month=null` = override 해제

- [ ] **Step 1: 실패 테스트 — bulk override 적용**

`tests/kakao_check_test.php`에 추가:
```php
t_section('set_cohort — bulk override');

$db->beginTransaction();
$o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-28', 'end_date' => '2026-07-27']);
$o2 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-29', 'end_date' => '2026-07-28']);

$updated = kakaoCheckSetCohort($db, [$o1, $o2], '2026-05', $adminId);
t_assert_eq(2, $updated, 'updated 카운트 = 2');

$rows = $db->query("SELECT cohort_month FROM orders WHERE id IN ({$o1},{$o2}) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
t_assert_eq(['2026-05', '2026-05'], $rows, '두 행 모두 cohort_month=2026-05');

$logCount = (int)$db->query("SELECT COUNT(*) FROM change_logs WHERE target_type='order' AND target_id IN ({$o1},{$o2}) AND action='cohort_month_set'")->fetchColumn();
t_assert_eq(2, $logCount, 'change_logs 2건 (각 order당 1건)');

$db->rollBack();
```

- [ ] **Step 2: `kakaoCheckSetCohort` 구현**

`public_html/api/kakao_check.php`에 추가:

```php
/**
 * 여러 order의 cohort_month를 일괄 설정 (또는 NULL 복원). admin only.
 * 트랜잭션으로 묶어 모두 성공 또는 모두 실패.
 *
 * @param int[]       $orderIds
 * @param string|null $cohortMonth  'YYYY-MM' 또는 null(override 해제)
 * @param int         $adminId
 * @return int  실제 UPDATE된 행 수 (변경 없는 행은 제외)
 */
function kakaoCheckSetCohort(PDO $db, array $orderIds, ?string $cohortMonth, int $adminId): int
{
    if (empty($orderIds)) return 0;
    if ($cohortMonth !== null && !preg_match('/^\d{4}-\d{2}$/', $cohortMonth)) {
        throw new InvalidArgumentException('cohort_month 형식 오류 (YYYY-MM)');
    }

    $db->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sel = $db->prepare("SELECT id, cohort_month FROM orders WHERE id IN ({$placeholders})");
        $sel->execute($orderIds);
        $existing = $sel->fetchAll(PDO::FETCH_KEY_PAIR);

        $updated = 0;
        $upd = $db->prepare("UPDATE orders SET cohort_month = ? WHERE id = ?");

        foreach ($orderIds as $oid) {
            if (!array_key_exists($oid, $existing)) continue;
            $oldVal = $existing[$oid]; // string 'YYYY-MM' 또는 null
            if ($oldVal === $cohortMonth) continue; // no-op
            $upd->execute([$cohortMonth, $oid]);
            logChange($db, 'order', (int)$oid, 'cohort_month_set',
                ['cohort_month' => $oldVal],
                ['cohort_month' => $cohortMonth],
                'admin', $adminId);
            $updated++;
        }

        $db->commit();
        return $updated;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
```

**주의:** 본 함수는 자체 트랜잭션을 시작하므로, **테스트에서 외부 beginTransaction 안에서 호출하면 nested transaction 에러**. PT의 PDO는 nested 미지원 가능.

해결: 테스트가 외부 트랜잭션을 안 쓰고, 대신 SAVEPOINT 또는 fixture 직접 정리. 가장 간단한 방법은 **`$db->inTransaction()` 체크해 이미 트랜잭션이면 자체 BEGIN/COMMIT 스킵**하도록 함수를 수정.

`kakaoCheckSetCohort`의 begin/commit 부분을 다음으로 교체:
```php
    $ownTxn = !$db->inTransaction();
    if ($ownTxn) $db->beginTransaction();
    try {
        // ... 기존 로직 ...
        if ($ownTxn) $db->commit();
        return $updated;
    } catch (Throwable $e) {
        if ($ownTxn && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
```

이렇게 하면 caller(라우터)가 트랜잭션 없을 때 자체 시작, 테스트 caller가 트랜잭션 안에 있을 때 그 트랜잭션을 그대로 사용.

- [ ] **Step 3: 라우터 set_cohort 케이스 채우기**

`public_html/api/kakao_check.php`의 `case 'set_cohort':` 교체:

```php
    case 'set_cohort':
        if ($user['role'] !== 'admin') jsonError('관리자만 가능합니다', 403);

        $input = getJsonInput();
        $orderIds = $input['order_ids'] ?? [];
        $cohortMonth = $input['cohort_month'] ?? null;

        if (!is_array($orderIds) || empty($orderIds)) jsonError('order_ids가 필요합니다');
        $orderIds = array_map('intval', $orderIds);

        if ($cohortMonth !== null && !preg_match('/^\d{4}-\d{2}$/', $cohortMonth)) {
            jsonError('cohort_month 형식 오류 (YYYY-MM)');
        }

        try {
            $updated = kakaoCheckSetCohort($db, $orderIds, $cohortMonth, (int)$user['id']);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        jsonSuccess(['updated' => $updated]);
```

- [ ] **Step 4: 테스트 실행 — bulk override PASS**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected: `set_cohort — bulk override` PASS.

- [ ] **Step 5: 추가 테스트 — NULL 복원 + no-op**

`tests/kakao_check_test.php`에 추가:
```php
t_section('set_cohort — NULL 복원');

$db->beginTransaction();
$o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-28', 'end_date' => '2026-07-27']);
$db->prepare("UPDATE orders SET cohort_month='2026-05' WHERE id=?")->execute([$o]);

$updated = kakaoCheckSetCohort($db, [$o], null, $adminId);
t_assert_eq(1, $updated, 'NULL 복원 1건');
$cm = $db->query("SELECT cohort_month FROM orders WHERE id={$o}")->fetchColumn();
t_assert_true($cm === null, 'cohort_month = NULL');

$log = $db->query("SELECT new_value FROM change_logs WHERE target_type='order' AND target_id={$o} AND action='cohort_month_set' ORDER BY id DESC LIMIT 1")->fetch();
t_assert_eq('{"cohort_month":null}', $log['new_value'], 'log new_value JSON null');

$db->rollBack();

t_section('set_cohort — no-op (같은 값)');

$db->beginTransaction();
$o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-28', 'end_date' => '2026-07-27']);
$db->prepare("UPDATE orders SET cohort_month='2026-05' WHERE id=?")->execute([$o]);
$logCountBefore = (int)$db->query("SELECT COUNT(*) FROM change_logs WHERE target_type='order' AND target_id={$o}")->fetchColumn();

$updated = kakaoCheckSetCohort($db, [$o], '2026-05', $adminId);
t_assert_eq(0, $updated, 'no-op: updated=0');
$logCountAfter = (int)$db->query("SELECT COUNT(*) FROM change_logs WHERE target_type='order' AND target_id={$o}")->fetchColumn();
t_assert_eq($logCountBefore, $logCountAfter, 'no-op: change_logs 추가 없음');

$db->rollBack();
```

- [ ] **Step 6: 테스트 실행 — 전체 PASS**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected: kakao_check 섹션 모든 assertion PASS, Fail: 0.

- [ ] **Step 7: 커밋**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/api/kakao_check.php tests/kakao_check_test.php
git commit -m "feat(kakao-check): set_cohort API + 테스트"
```

---

## Task 8: 코치 SPA 페이지 — render + cohorts 로딩 + 기본 월 선택

**Files:**
- Create: `public_html/coach/js/pages/kakao-check.js`

스펙 §5.1. 기본 월 규칙: 오늘(KST) 기준 현재 월 데이터 있으면 거기, 없으면 가장 가까운 미래, 그것도 없으면 가장 최근 과거. cohorts 0건이면 빈 상태.

- [ ] **Step 1: 페이지 파일 생성 — 스켈레톤 + cohorts 로딩**

`public_html/coach/js/pages/kakao-check.js` 생성:

```javascript
/**
 * 카톡방 입장 체크 (코치 사이드)
 * 본인 회원의 카톡방 입장을 월 단위로 체크.
 */
CoachApp.registerPage('kakao-check', {
  STATUSES: ['진행중', '진행예정'],
  cohorts: [],
  selectedCohort: null,
  selectedStatuses: new Set(['진행중', '진행예정']),
  selectedProduct: '',
  includeJoined: false,

  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">카톡방 입장 체크</h1></div>
      <div id="cohortTabs" class="filters"></div>
      <div id="kakaoFilters"></div>
      <div id="kakaoList"><div class="loading">불러오는 중...</div></div>
    `;
    await this.loadCohorts();
  },

  async loadCohorts() {
    const res = await API.get('/api/kakao_check.php?action=cohorts');
    if (!res.ok) {
      document.getElementById('kakaoList').innerHTML = `<div class="empty-state">${UI.esc(res.message)}</div>`;
      return;
    }
    this.cohorts = res.data.cohorts || [];
    if (this.cohorts.length === 0) {
      document.getElementById('cohortTabs').innerHTML = '';
      document.getElementById('kakaoFilters').innerHTML = '';
      document.getElementById('kakaoList').innerHTML = '<div class="empty-state">현재 카톡방 입장 체크 대상이 없습니다</div>';
      return;
    }
    this.selectedCohort = this.pickDefaultCohort(this.cohorts);
    this.renderCohortTabs();
    this.renderFilters();
    await this.loadList();
  },

  pickDefaultCohort(cohorts) {
    const now = new Date();
    const ksOffset = 9 * 60 * 60 * 1000;
    const kst = new Date(now.getTime() + (ksOffset - now.getTimezoneOffset() * 60000));
    const cur = kst.getFullYear() + '-' + String(kst.getMonth() + 1).padStart(2, '0');
    if (cohorts.includes(cur)) return cur;
    const future = cohorts.filter(c => c > cur).sort();
    if (future.length) return future[0];
    const past = cohorts.filter(c => c < cur).sort().reverse();
    return past[0]; // cohorts.length > 0이 보장됨
  },

  renderCohortTabs() {
    const html = this.cohorts.map(c => `
      <button class="filter-pill ${c === this.selectedCohort ? 'active' : ''}"
              onclick="CoachApp.pages['kakao-check'].selectCohort('${c}')">${c}</button>
    `).join('');
    document.getElementById('cohortTabs').innerHTML = html;
  },

  selectCohort(cohort) {
    this.selectedCohort = cohort;
    this.selectedProduct = '';
    this.renderCohortTabs();
    this.renderFilters();
    this.loadList();
  },

  renderFilters() {
    document.getElementById('kakaoFilters').innerHTML = `
      <div class="filters">
        ${this.STATUSES.map(s => `
          <button class="filter-pill ${this.selectedStatuses.has(s) ? 'active' : ''}"
                  data-status="${s}"
                  onclick="CoachApp.pages['kakao-check'].toggleStatus('${s}')">${s}</button>
        `).join('')}
        <select class="filter-pill" id="kakaoProductFilter"
                onchange="CoachApp.pages['kakao-check'].setProduct(this.value)">
          <option value="">전체 상품</option>
        </select>
        <label style="margin-left:auto; display:inline-flex; align-items:center; gap:6px;">
          <input type="checkbox" ${this.includeJoined ? 'checked' : ''}
                 onchange="CoachApp.pages['kakao-check'].toggleIncludeJoined(this.checked)">
          체크 완료도 보기
        </label>
      </div>
    `;
  },

  toggleStatus(s) {
    if (this.selectedStatuses.has(s)) {
      if (this.selectedStatuses.size === 1) return; // 최소 1개 가드
      this.selectedStatuses.delete(s);
    } else {
      this.selectedStatuses.add(s);
    }
    this.renderFilters();
    this.loadList();
  },

  setProduct(value) {
    this.selectedProduct = value;
    this.loadList();
  },

  toggleIncludeJoined(checked) {
    this.includeJoined = checked;
    this.loadList();
  },

  async loadList() {
    const container = document.getElementById('kakaoList');
    container.innerHTML = '<div class="loading">불러오는 중...</div>';

    const params = new URLSearchParams({
      action: 'list',
      cohort: this.selectedCohort,
      include_joined: this.includeJoined ? '1' : '0',
    });
    if (this.selectedProduct) params.set('product', this.selectedProduct);

    const res = await API.get(`/api/kakao_check.php?${params}`);
    if (!res.ok) { container.innerHTML = `<div class="empty-state">${UI.esc(res.message)}</div>`; return; }

    // products 드롭다운 갱신 (월 변경시 다른 셋)
    const sel = document.getElementById('kakaoProductFilter');
    if (sel) {
      sel.innerHTML = '<option value="">전체 상품</option>' +
        res.data.products.map(p =>
          `<option value="${UI.esc(p)}" ${p === this.selectedProduct ? 'selected' : ''}>${UI.esc(p)}</option>`
        ).join('');
    }

    // status 클라이언트 사이드 필터
    const orders = (res.data.orders || []).filter(o => this.selectedStatuses.has(o.display_status));
    if (orders.length === 0) {
      container.innerHTML = '<div class="empty-state">조건에 맞는 회원이 없습니다</div>';
      return;
    }

    container.innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:32px"></th>
            <th>이름</th>
            <th>전화번호</th>
            <th>이메일</th>
            <th>상품</th>
            <th>시작일</th>
            <th>상태</th>
          </tr>
        </thead>
        <tbody>
          ${orders.map(o => this._row(o)).join('')}
        </tbody>
      </table>
      <div style="margin-top:8px; color:var(--text-secondary); font-size:13px;">
        ${orders.length}명
      </div>
    `;
  },

  _row(o) {
    const checked = parseInt(o.kakao_room_joined, 10) === 1;
    return `
      <tr id="kakao-row-${o.order_id}" style="${checked ? 'opacity:0.55' : ''}">
        <td><input type="checkbox" ${checked ? 'checked' : ''}
                   onclick="CoachApp.pages['kakao-check'].toggleJoin(${o.order_id}, this.checked)"></td>
        <td>${UI.esc(o.name)}</td>
        <td style="color:var(--text-secondary)">${UI.esc(o.phone) || '-'}</td>
        <td style="color:var(--text-secondary)">${UI.esc(o.email) || '-'}</td>
        <td>${UI.esc(o.product_name)}</td>
        <td>${UI.formatDate(o.start_date)}</td>
        <td>${UI.statusBadge(o.display_status)}</td>
      </tr>
    `;
  },

  async toggleJoin(orderId, joined) {
    const row = document.getElementById(`kakao-row-${orderId}`);
    const res = await API.post('/api/kakao_check.php?action=toggle_join', { order_id: orderId, joined });
    if (!res.ok) {
      alert(res.message || '실패');
      // 체크박스 상태 원복
      if (row) row.querySelector('input[type=checkbox]').checked = !joined;
      return;
    }
    // include_joined=false면 fade out 후 행 제거
    if (!this.includeJoined && joined) {
      if (row) {
        row.style.transition = 'opacity 0.3s';
        row.style.opacity = '0';
        setTimeout(() => this.loadList(), 320);
      }
    } else {
      // 그 외엔 단순히 행 스타일 갱신
      if (row) row.style.opacity = joined ? '0.55' : '1';
    }
  },
});
```

- [ ] **Step 2: 코치 사이드바에 메뉴 + script 등록**

`public_html/coach/index.php` 편집. `<a href="#my-members" data-page="my-members">내 회원</a>` 다음 줄에 추가:

```html
      <a href="#kakao-check" data-page="kakao-check">카톡방 입장 체크</a>
```

같은 파일에서 `<script src="/coach/js/pages/my-members.js"></script>` 다음 줄에 추가:

```html
<script src="/coach/js/pages/kakao-check.js"></script>
```

- [ ] **Step 3: 브라우저 최소 smoke (수동)**

사용자에게 코치 계정으로 https://pt.soritune.com/coach/ 로그인 → 사이드바에 "카톡방 입장 체크" 메뉴 보이는지 / 클릭 시 페이지 렌더링 / cohort 탭 보이는지 확인 요청.

DEV가 없는 사이트라 PROD에 직접 영향. 본 태스크의 commit/push는 수동 smoke OK 받은 후 진행. 만약 사용자 확인 전이면 일단 commit만 하고 push는 마지막 태스크에서 일괄.

- [ ] **Step 4: 커밋**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/coach/js/pages/kakao-check.js public_html/coach/index.php
git commit -m "feat(kakao-check): 코치 사이드 페이지 + 사이드바 등록"
```

---

## Task 9: 어드민 SPA 페이지 — 코치 필터 + bulk action bar

**Files:**
- Create: `public_html/admin/js/pages/kakao-check.js`

스펙 §5.2. 코치 화면 베이스 + 추가 기능:
- 상단 코치 필터 드롭다운 (`/api/coaches.php?action=list`)
- 행 좌측 select 체크박스 (입장 체크박스와 별개)
- sticky bulk action bar — 목적지 월 선택 + [적용] / [원래대로(자동)]

- [ ] **Step 1: 어드민 페이지 파일 작성**

`public_html/admin/js/pages/kakao-check.js` 생성:

```javascript
/**
 * 카톡방 입장 체크 (어드민 사이드)
 * 코치 페이지 베이스 + 코치 필터 + 다중 선택 + bulk cohort override.
 */
App.registerPage('kakao-check', {
  STATUSES: ['진행중', '진행예정'],
  cohorts: [],
  coaches: [],
  selectedCohort: null,
  selectedCoachId: '',
  selectedStatuses: new Set(['진행중', '진행예정']),
  selectedProduct: '',
  includeJoined: false,
  selectedOrderIds: new Set(),
  bulkCohort: '',

  async render() {
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header"><h1 class="page-title">카톡방 입장 체크</h1></div>
      <div class="filters">
        <select class="filter-pill" id="adminCoachFilter"
                onchange="App.pages['kakao-check'].setCoach(this.value)">
          <option value="">전체 코치</option>
        </select>
      </div>
      <div id="cohortTabs" class="filters"></div>
      <div id="kakaoFilters"></div>
      <div id="kakaoList"><div class="loading">불러오는 중...</div></div>
      <div id="bulkActionBar"></div>
    `;
    await Promise.all([this.loadCoaches(), this.loadCohorts()]);
  },

  async loadCoaches() {
    const res = await API.get('/api/coaches.php?action=list');
    if (!res.ok) return;
    this.coaches = (res.data.coaches || []).filter(c => c.status === 'active');
    const sel = document.getElementById('adminCoachFilter');
    if (sel) {
      sel.innerHTML = '<option value="">전체 코치</option>' +
        this.coaches.map(c => `<option value="${c.id}">${UI.esc(c.coach_name)}</option>`).join('');
    }
  },

  async setCoach(value) {
    this.selectedCoachId = value;
    this.selectedOrderIds.clear();
    await this.loadCohorts();
  },

  async loadCohorts() {
    const params = new URLSearchParams({ action: 'cohorts' });
    if (this.selectedCoachId) params.set('coach_id', this.selectedCoachId);
    const res = await API.get(`/api/kakao_check.php?${params}`);
    if (!res.ok) {
      document.getElementById('kakaoList').innerHTML = `<div class="empty-state">${UI.esc(res.message)}</div>`;
      return;
    }
    this.cohorts = res.data.cohorts || [];
    if (this.cohorts.length === 0) {
      document.getElementById('cohortTabs').innerHTML = '';
      document.getElementById('kakaoFilters').innerHTML = '';
      document.getElementById('kakaoList').innerHTML = '<div class="empty-state">조건에 맞는 데이터가 없습니다</div>';
      this.renderBulkBar();
      return;
    }
    if (!this.cohorts.includes(this.selectedCohort)) {
      this.selectedCohort = this.pickDefaultCohort(this.cohorts);
    }
    this.renderCohortTabs();
    this.renderFilters();
    await this.loadList();
  },

  pickDefaultCohort(cohorts) {
    const now = new Date();
    const ksOffset = 9 * 60 * 60 * 1000;
    const kst = new Date(now.getTime() + (ksOffset - now.getTimezoneOffset() * 60000));
    const cur = kst.getFullYear() + '-' + String(kst.getMonth() + 1).padStart(2, '0');
    if (cohorts.includes(cur)) return cur;
    const future = cohorts.filter(c => c > cur).sort();
    if (future.length) return future[0];
    const past = cohorts.filter(c => c < cur).sort().reverse();
    return past[0];
  },

  renderCohortTabs() {
    const html = this.cohorts.map(c => `
      <button class="filter-pill ${c === this.selectedCohort ? 'active' : ''}"
              onclick="App.pages['kakao-check'].selectCohort('${c}')">${c}</button>
    `).join('');
    document.getElementById('cohortTabs').innerHTML = html;
  },

  selectCohort(cohort) {
    this.selectedCohort = cohort;
    this.selectedProduct = '';
    this.selectedOrderIds.clear();
    this.renderCohortTabs();
    this.renderFilters();
    this.loadList();
  },

  renderFilters() {
    document.getElementById('kakaoFilters').innerHTML = `
      <div class="filters">
        ${this.STATUSES.map(s => `
          <button class="filter-pill ${this.selectedStatuses.has(s) ? 'active' : ''}"
                  data-status="${s}"
                  onclick="App.pages['kakao-check'].toggleStatus('${s}')">${s}</button>
        `).join('')}
        <select class="filter-pill" id="kakaoProductFilter"
                onchange="App.pages['kakao-check'].setProduct(this.value)">
          <option value="">전체 상품</option>
        </select>
        <label style="margin-left:auto; display:inline-flex; align-items:center; gap:6px;">
          <input type="checkbox" ${this.includeJoined ? 'checked' : ''}
                 onchange="App.pages['kakao-check'].toggleIncludeJoined(this.checked)">
          체크 완료도 보기
        </label>
      </div>
    `;
  },

  toggleStatus(s) {
    if (this.selectedStatuses.has(s)) {
      if (this.selectedStatuses.size === 1) return;
      this.selectedStatuses.delete(s);
    } else {
      this.selectedStatuses.add(s);
    }
    this.renderFilters();
    this.loadList();
  },

  setProduct(value) {
    this.selectedProduct = value;
    this.loadList();
  },

  toggleIncludeJoined(checked) {
    this.includeJoined = checked;
    this.loadList();
  },

  async loadList() {
    const container = document.getElementById('kakaoList');
    container.innerHTML = '<div class="loading">불러오는 중...</div>';

    const params = new URLSearchParams({
      action: 'list',
      cohort: this.selectedCohort,
      include_joined: this.includeJoined ? '1' : '0',
    });
    if (this.selectedCoachId) params.set('coach_id', this.selectedCoachId);
    if (this.selectedProduct) params.set('product', this.selectedProduct);

    const res = await API.get(`/api/kakao_check.php?${params}`);
    if (!res.ok) { container.innerHTML = `<div class="empty-state">${UI.esc(res.message)}</div>`; return; }

    const sel = document.getElementById('kakaoProductFilter');
    if (sel) {
      sel.innerHTML = '<option value="">전체 상품</option>' +
        res.data.products.map(p =>
          `<option value="${UI.esc(p)}" ${p === this.selectedProduct ? 'selected' : ''}>${UI.esc(p)}</option>`
        ).join('');
    }

    const orders = (res.data.orders || []).filter(o => this.selectedStatuses.has(o.display_status));
    if (orders.length === 0) {
      container.innerHTML = '<div class="empty-state">조건에 맞는 회원이 없습니다</div>';
      this.renderBulkBar();
      return;
    }

    container.innerHTML = `
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:32px"><input type="checkbox" id="kakaoSelectAll"
                onclick="App.pages['kakao-check'].toggleSelectAll(this.checked)"></th>
            <th style="width:32px"></th>
            <th>이름</th>
            <th>전화번호</th>
            <th>이메일</th>
            <th>상품</th>
            <th>코치</th>
            <th>시작일</th>
            <th>상태</th>
            <th>코호트</th>
          </tr>
        </thead>
        <tbody>
          ${orders.map(o => this._row(o)).join('')}
        </tbody>
      </table>
      <div style="margin-top:8px; color:var(--text-secondary); font-size:13px;">${orders.length}명</div>
    `;
    this.renderBulkBar();
  },

  _row(o) {
    const checked = parseInt(o.kakao_room_joined, 10) === 1;
    const selected = this.selectedOrderIds.has(o.order_id);
    const overrideMark = o.cohort_month_override ? ' <span style="color:#888;font-size:11px;">(override)</span>' : '';
    return `
      <tr id="kakao-row-${o.order_id}" style="${checked ? 'opacity:0.55' : ''}">
        <td><input type="checkbox" ${selected ? 'checked' : ''}
                   onclick="App.pages['kakao-check'].toggleSelect(${o.order_id}, this.checked)"></td>
        <td><input type="checkbox" ${checked ? 'checked' : ''}
                   onclick="App.pages['kakao-check'].toggleJoin(${o.order_id}, this.checked)"></td>
        <td>${UI.esc(o.name)}</td>
        <td style="color:var(--text-secondary)">${UI.esc(o.phone) || '-'}</td>
        <td style="color:var(--text-secondary)">${UI.esc(o.email) || '-'}</td>
        <td>${UI.esc(o.product_name)}</td>
        <td>${UI.esc(o.coach_name) || '-'}</td>
        <td>${UI.formatDate(o.start_date)}</td>
        <td>${UI.statusBadge(o.display_status)}</td>
        <td>${UI.esc(o.effective_cohort)}${overrideMark}</td>
      </tr>
    `;
  },

  toggleSelect(orderId, checked) {
    if (checked) this.selectedOrderIds.add(orderId);
    else this.selectedOrderIds.delete(orderId);
    this.renderBulkBar();
  },

  toggleSelectAll(checked) {
    document.querySelectorAll('#kakaoList tbody tr').forEach(tr => {
      const cb = tr.querySelector('td:first-child input[type=checkbox]');
      if (!cb) return;
      cb.checked = checked;
      const id = parseInt(tr.id.replace('kakao-row-', ''), 10);
      if (checked) this.selectedOrderIds.add(id);
      else this.selectedOrderIds.delete(id);
    });
    this.renderBulkBar();
  },

  renderBulkBar() {
    const bar = document.getElementById('bulkActionBar');
    if (!bar) return;
    const n = this.selectedOrderIds.size;
    if (n === 0) { bar.innerHTML = ''; return; }
    const cohortOptions = this.cohorts.map(c =>
      `<option value="${c}" ${c === this.bulkCohort ? 'selected' : ''}>${c}</option>`
    ).join('');
    bar.innerHTML = `
      <div style="position:sticky; bottom:0; background:#fff; border-top:1px solid #ddd; padding:12px;
                  display:flex; align-items:center; gap:12px; box-shadow:0 -2px 6px rgba(0,0,0,0.06);">
        <strong>${n}건 선택됨</strong>
        <select class="filter-pill" onchange="App.pages['kakao-check'].setBulkCohort(this.value)">
          <option value="">목적지 월 선택…</option>
          ${cohortOptions}
        </select>
        <button class="btn btn-primary btn-small" onclick="App.pages['kakao-check'].applyBulk()">적용</button>
        <button class="btn btn-outline btn-small" onclick="App.pages['kakao-check'].applyRestore()">원래대로(자동)</button>
        <button class="btn btn-outline btn-small" style="margin-left:auto;"
                onclick="App.pages['kakao-check'].clearSelection()">선택 해제</button>
      </div>
    `;
  },

  setBulkCohort(value) {
    this.bulkCohort = value;
  },

  clearSelection() {
    this.selectedOrderIds.clear();
    this.loadList();
  },

  async applyBulk() {
    if (!this.bulkCohort) { alert('목적지 월을 선택하세요'); return; }
    if (!confirm(`${this.selectedOrderIds.size}건을 ${this.bulkCohort} 코호트로 이동합니다`)) return;
    await this._setCohort(this.bulkCohort);
  },

  async applyRestore() {
    if (!confirm(`${this.selectedOrderIds.size}건의 cohort override를 해제 (자동 분류로 복원)합니다`)) return;
    await this._setCohort(null);
  },

  async _setCohort(cohortMonth) {
    const orderIds = Array.from(this.selectedOrderIds);
    const res = await API.post('/api/kakao_check.php?action=set_cohort', {
      order_ids: orderIds,
      cohort_month: cohortMonth,
    });
    if (!res.ok) { alert(res.message || '실패'); return; }
    this.selectedOrderIds.clear();
    this.bulkCohort = '';
    await this.loadCohorts();
  },

  async toggleJoin(orderId, joined) {
    const row = document.getElementById(`kakao-row-${orderId}`);
    const res = await API.post('/api/kakao_check.php?action=toggle_join', { order_id: orderId, joined });
    if (!res.ok) {
      alert(res.message || '실패');
      if (row) {
        // 입장 체크박스 (두 번째 td)만 원복
        const cb = row.querySelector('td:nth-child(2) input[type=checkbox]');
        if (cb) cb.checked = !joined;
      }
      return;
    }
    if (!this.includeJoined && joined) {
      if (row) {
        row.style.transition = 'opacity 0.3s';
        row.style.opacity = '0';
        setTimeout(() => this.loadList(), 320);
      }
    } else {
      if (row) row.style.opacity = joined ? '0.55' : '1';
    }
  },
});
```

- [ ] **Step 2: 어드민 사이드바에 메뉴 + script 등록**

`public_html/admin/index.php` 편집. `<a href="#notify" data-page="notify">알림톡</a>` 다음 줄에 추가:

```html
      <a href="#kakao-check" data-page="kakao-check">카톡방 입장 체크</a>
```

같은 파일에서 `<script src="/admin/js/pages/notify.js"></script>` 다음 줄에 추가:

```html
<script src="/admin/js/pages/kakao-check.js"></script>
```

- [ ] **Step 3: 어드민 브라우저 smoke (수동)**

사용자에게 어드민 계정으로 https://pt.soritune.com/admin/ 로그인 → 사이드바 메뉴 클릭 → 페이지 렌더링 / 코치 필터 / cohort 탭 / 행 select / bulk bar 등장 확인 요청.

- [ ] **Step 4: 커밋**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add public_html/admin/js/pages/kakao-check.js public_html/admin/index.php
git commit -m "feat(kakao-check): 어드민 사이드 페이지 + 사이드바 등록"
```

---

## Task 10: 통합 수동 시나리오 + 사용자 검증 요청

본 태스크는 코드 변경 없음. 사용자에게 운영 사이트(PT) 직접 검증을 요청한다.

- [ ] **Step 1: 검증 시나리오 메모를 사용자에게 제시**

다음 시나리오를 사용자에게 안내하고 통과 여부 확인 요청:

**시나리오 A — 코치 사이드 기본 흐름:**
1. 코치 로그인 → 사이드바 "카톡방 입장 체크" 클릭
2. 가로 cohort 탭 (오늘 기준 현재 월이 디폴트 active) 보임
3. 진행중/진행예정 pill, 상품 드롭다운, "체크 완료도 보기" 토글 보임
4. 행에 체크박스 + 회원 정보 보임
5. 체크박스 클릭 → 행 fade out → 사라짐
6. "체크 완료도 보기" ON → 사라진 행이 회색 + 체크된 상태로 등장
7. OFF로 다시 토글 → 정상 색 + 체크박스 빈 상태로 돌아옴

**시나리오 B — 어드민 cohort override + bulk:**
1. 어드민 로그인 → "카톡방 입장 체크" 메뉴
2. 코치 필터 = "전체 코치" 상태에서 cohort 탭 보임
3. 행 좌측 select 체크박스 클릭 → 하단에 sticky bulk bar 등장
4. 다중 선택 → bulk bar에 "N건 선택됨"
5. 목적지 월 드롭다운에서 다른 월 선택 → "적용" → confirm → 성공 토스트 / cohort 탭 재렌더 / 옮긴 행은 다른 cohort 탭에서 등장
6. 옮긴 행 select → "원래대로(자동)" → confirm → 다시 자동 분류로 복원

**시나리오 C — 권한 분리:**
1. 코치 A 로그인 → 자기 회원만 보임 (다른 코치 회원 안 보임)
2. 어드민 코치 필터로 A 선택 → A 회원만 보임

**시나리오 D — 이번 spec의 모티베이션 케이스:**
1. 어드민 4월 탭에서 4월 28일 시작 order 1건 select
2. bulk bar 목적지 월 = 5월 → 적용
3. 4월 탭에서 사라지고 5월 탭 → effective_cohort 옆에 "(override)" 표시
4. 코치도 자기 5월 탭에서 그 회원이 보임

- [ ] **Step 2: 사용자 OK 받으면 다음 태스크로**

사용자가 "이상 없음" 확인하면 진행. 이슈 발견 시 해당 태스크로 돌아가 디버깅.

---

## Task 11: 메모리 업데이트 + 최종 push

- [ ] **Step 1: git status 점검**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git status --short
git log --oneline -10
```

Expected: 본 작업 commit들이 보이고, 다른 작업의 unstaged/untracked 변경 그대로 보존됨.

- [ ] **Step 2: 사용자에게 push 승인 요청**

PT는 main 단일 브랜치 + 운영 직접. 사용자에게 다음 메시지 전달:

> kakao-check 작업 모든 commit 준비 완료. main에 N개 commit 쌓여있음. push 진행해도 될까요?

- [ ] **Step 3: 사용자 OK 받으면 push**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git push origin main
```

Expected: 성공.

- [ ] **Step 4: 메모리 업데이트**

`/root/.claude/projects/-root/memory/project_pt_kakao_check_wip.md` 파일을 완료 상태로 갱신:
- name: "PT 카톡방 입장 체크 탭" 유지
- description: "2026-04-30 PROD 배포 완료" 등으로 갱신
- 본문에 완료 요약 (마이그레이션 적용 + API 4 액션 + 코치/어드민 SPA + 테스트 N건 PASS)
- 파일 자체를 `project_pt_kakao_check_completed.md`로 rename하고 `MEMORY.md`의 인덱스 라인도 갱신.

---

## Self-review 체크리스트 (계획 작성자가 본인 검증)

- ✅ 스펙 §1 결정사항 7개 모두 plan에 반영 (cohort 단일/auto+override/체크 영속/bulk override/가로 pill 탭/체크완료 숨김/admin 코치 필터)
- ✅ 스펙 §3 마이그 컬럼 4개 + 인덱스 2개 → Task 1
- ✅ 스펙 §4 API 4개 액션 → Task 4–7 (각 TDD)
- ✅ 스펙 §5.1 코치 UI → Task 8
- ✅ 스펙 §5.2 어드민 UI → Task 9
- ✅ 스펙 §6 edge case 1–8 → 시나리오 검증 (Task 10) + 함수 동작에 반영
- ✅ 스펙 §7 검증/테스트 → kakao_check_test.php (Task 4–7)
- ✅ 스펙 §10 변경 영향 범위 — 모든 추가/수정 파일 plan에 명시
- ✅ TDD: 각 API 액션은 실패 테스트 → 구현 → PASS → 커밋 사이클
- ✅ 권한: requireAnyAuth + role 분기 (코치 자기 order만, set_cohort admin only)
- ✅ Audit: change_logs target_type='order' (ENUM 변경 불필요), action= kakao_room_join/unjoin/cohort_month_set
- ✅ 트랜잭션: set_cohort는 자체 BEGIN/COMMIT + nested 가드 (테스트 호환)
- ✅ Idempotency: 마이그(IF NOT EXISTS), toggle_join (no-op), set_cohort (no-op skip)
- ✅ Frequent commits: 각 태스크 끝에 명시 파일만 staging해서 commit
- ✅ "다른 작업과 충돌 회피" 가드: `git add -A` 금지 명시
- ✅ PT는 main 단일 브랜치 + DEV 없음 → push는 사용자 승인 후 마지막 태스크에서

플레이스홀더 검사: "TODO" 표기는 Task 2 스켈레톤(의도된 단계적 구현)에만 존재. 다른 곳엔 없음.
