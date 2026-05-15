# PT 회원 차트 시스템 (시트→웹) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 뉴소리튜닝 코치들이 구글 시트로 운영 중인 회원 차트(매칭별 일별 코칭 로그 + 진도/개선율 자동 계산)를 PT 자체 웹서비스로 흡수한다. 매칭 캘린더(월별×상품별 single source) + `order_sessions` 확장 + 자동 계산 + 회원 차트 페이지 통합 + 시트 CSV 마이그레이션 + 변경 감사.

**Architecture:** PT 기존 스택(PHP 8 + MariaDB + vanilla JS SPA) 그대로. 신규 헬퍼는 `public_html/includes/` 평탄 배치, API는 `/api/` 평탄 배치, 페이지는 `admin/js/pages/`·`coach/js/pages/`. 기존 `member-chart.js` (admin/coach 양쪽 존재) 에 **신규 섹션 add** — 새 페이지 만들지 않는다. 권한은 `auth.php` + 신규 `coach_can_access_member()` 가드. 감사는 `change_logs` ENUM 확장 후 기존 패턴 재사용. 자동 계산은 query-time 산출(캐싱 X). 마이그는 PT 기존 `migration_logs` + 신규 `coaching_log_migration_preview` staging.

**Tech Stack:** PHP 8 (PDO), Vanilla JS SPA, MariaDB 10.5, 자체 PHP 테스트 러너 (`tests/run_tests.php`).

**Spec:** `docs/superpowers/specs/2026-05-11-pt-member-chart-design.md`

**Working dir:** `/root/pt-dev` (DEV_PT, dev 브랜치). 운영 반영은 사용자 명시 요청 시에만.

---

## Conventions / Useful References

**테스트 실행**
```bash
cd /root/pt-dev && php tests/run_tests.php
```

**기존 헬퍼**
- `tests/_bootstrap.php` — `t_section`, `t_assert_eq`, `t_assert_true`, `t_assert_throws`
- `public_html/includes/db.php` — `getDb(): PDO`
- `public_html/includes/helpers.php` — `jsonSuccess`, `jsonError`, `getJsonInput`
- `public_html/includes/auth.php` — `requireAdmin()`, `requireCoach()`, `getCurrentUser()`
- `public_html/includes/matching_engine.php` — 회원-코치 매칭 lookup
- `public_html/includes/coach_team_guard.php` — 코치 팀 접근 가드 패턴 참조

**기존 SPA 패턴**
- `public_html/admin/js/app.js` — `App.registerPage(name, {render(params)})`, `API.get/post/put/del`, `UI.esc`, modal helpers
- `public_html/coach/js/app.js` — `CoachApp.registerPage(...)` (이름만 다름, 동일 패턴)
- 페이지 URL: `#page/param1/param2`

**기존 API 응답 패턴**
- 성공: `{ok: true, data: {...}}`
- 실패: `{ok: false, message: '...', code: 'OPTIONAL_CODE'}` + HTTP 401/403/404/400/500

**DEV DB 마이그 실행**
```bash
cd /root/pt-dev && source .db_credentials && \
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  < migrations/20260511_add_coaching_chart.sql
```

**기존 테스트 기준선:** 모든 `*_test.php` PASS (변경 후 회귀 0 + 신규 +N).

**Git push 게이트:** 모든 task 끝나고 DEV smoke 통과 시 `git push origin dev` 까지만. PROD 머지는 사용자 명시 요청 후.

---

## File Structure

### Create

**Migrations / SQL**
- `migrations/20260511_add_coaching_chart.sql` — 신규 테이블 2개 + change_logs ENUM 확장 + order_sessions 확장 + migration_preview

**PHP includes**
- `public_html/includes/coaching_metrics.php` — `CoachingMetrics::for_order/for_member/for_cohort`
- `public_html/includes/coaching_calendar.php` — `CoachingCalendar::create/update/delete/get_for_order/generate_pattern/set_dates`
- `public_html/includes/coaching_log.php` — `CoachingLog::create/update/delete/bulk_update/list_for_order`
- `public_html/includes/coaching_log_migration.php` — `CoachingLogMigration::parse/validate/import`
- `public_html/includes/member_chart_access.php` — `coach_can_access_member(int, int): bool`, `require_member_chart_access(int)`

**API**
- `public_html/api/coaching_calendar.php` — admin only CRUD + pattern_preview
- `public_html/api/coaching_log.php` — admin || coach_can_access_member CRUD + bulk
- `public_html/api/coaching_log_migration.php` — admin only: upload, preview, import
- `public_html/api/member_chart.php` — admin || coach_can_access_member: 회원 차트 통합 data fetch (member + orders + tests + calendar + sessions + metrics)

**JS pages (admin)**
- `public_html/admin/js/pages/coaching-calendar.js`
- `public_html/admin/js/pages/coaching-log-migration.js`

**JS shared (admin + coach 공통)**
- `public_html/assets/js/coaching-chart.js` — 메트릭 카드 + 일별 로그 테이블 + 회차 modal + bulk edit 한 곳에 (admin/coach 양쪽에서 `<script>` 로 include 후 같은 함수 호출)

**Tests**
- `tests/coaching_metrics_test.php`
- `tests/coaching_calendar_test.php`
- `tests/coaching_log_test.php`
- `tests/coaching_log_migration_test.php`
- `tests/member_chart_access_test.php`

### Modify

- `public_html/admin/index.php` — 사이드바에 "코칭 캘린더" / "코칭 로그 마이그" 두 메뉴 항목 추가
- `public_html/admin/js/app.js` — 새 페이지 2개 등록
- `public_html/admin/js/pages/member-chart.js` — 신규 섹션(메트릭 카드 + 캘린더 위젯 + 일별 로그 탭) 추가
- `public_html/coach/js/pages/member-chart.js` — 동일 섹션 추가
- `public_html/admin/index.php`, `public_html/coach/index.php` — `<script src="/assets/js/coaching-chart.js">` 라인 추가

---

## Task 1: 마이그레이션 SQL + DEV 적용

**Files:**
- Create: `migrations/20260511_add_coaching_chart.sql`

신규 테이블 2개 + ENUM 확장 + `order_sessions` 확장 + staging 테이블. 단일 마이그 트랜잭션.

- [ ] **Step 1: Create migration file**

```sql
-- 2026-05-11: 회원 차트 시스템 — 매칭 캘린더 + 일별 코칭 로그 확장
-- Spec: docs/superpowers/specs/2026-05-11-pt-member-chart-design.md

START TRANSACTION;

-- 1) 매칭 캘린더 (월별 × 상품별, single source)
CREATE TABLE IF NOT EXISTS `coaching_calendars` (
  `id`            INT PRIMARY KEY AUTO_INCREMENT,
  `cohort_month`  CHAR(7)      NOT NULL,
  `product_name`  VARCHAR(200) NOT NULL,
  `session_count` INT          NOT NULL,
  `notes`         VARCHAR(500),
  `created_by`    INT NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT current_timestamp(),
  `updated_at`    DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uk_cohort_product` (`cohort_month`, `product_name`),
  KEY `idx_cohort` (`cohort_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) 캘린더 일자 (1:N)
CREATE TABLE IF NOT EXISTS `coaching_calendar_dates` (
  `id`             INT PRIMARY KEY AUTO_INCREMENT,
  `calendar_id`    INT NOT NULL,
  `session_number` INT NOT NULL,
  `scheduled_date` DATE NOT NULL,
  UNIQUE KEY `uk_calendar_session` (`calendar_id`, `session_number`),
  KEY `idx_date` (`scheduled_date`),
  FOREIGN KEY (`calendar_id`) REFERENCES `coaching_calendars`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) order_sessions 확장
ALTER TABLE `order_sessions`
  ADD COLUMN `calendar_id`  INT       NULL AFTER `order_id`,
  ADD COLUMN `progress`     TEXT      NULL AFTER `memo`,
  ADD COLUMN `issue`        TEXT      NULL AFTER `progress`,
  ADD COLUMN `solution`     TEXT      NULL AFTER `issue`,
  ADD COLUMN `improved`     TINYINT(1) NOT NULL DEFAULT 0 AFTER `solution`,
  ADD COLUMN `improved_at`  DATETIME  NULL AFTER `improved`,
  ADD COLUMN `updated_by`   INT       NULL AFTER `improved_at`,
  ADD COLUMN `updated_at`   DATETIME  NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  ADD KEY `idx_order_completed` (`order_id`, `completed_at`),
  ADD KEY `idx_order_improved`  (`order_id`, `improved`),
  ADD CONSTRAINT `fk_session_calendar` FOREIGN KEY (`calendar_id`) REFERENCES `coaching_calendars`(`id`) ON DELETE SET NULL;

-- 4) change_logs.target_type ENUM 확장
ALTER TABLE `change_logs`
  MODIFY COLUMN `target_type` ENUM(
    'member','order','coach_assignment','merge','retention_allocation',
    'meeting_note','training_attendance',
    'coaching_calendar','coaching_calendar_date','order_session'
  ) NOT NULL;

-- 5) 마이그 staging 테이블
CREATE TABLE IF NOT EXISTS `coaching_log_migration_preview` (
  `id`             INT PRIMARY KEY AUTO_INCREMENT,
  `batch_id`       VARCHAR(50) NOT NULL,
  `source_row`     INT NOT NULL,
  `soritune_id`    VARCHAR(50),
  `cohort_month`   CHAR(7),
  `product_name`   VARCHAR(200),
  `session_number` INT,
  `scheduled_date` DATE,
  `completed_at`   DATETIME,
  `progress`       TEXT,
  `issue`          TEXT,
  `solution`       TEXT,
  `improved`       TINYINT(1) DEFAULT 0,
  `sheet_progress_rate`    DECIMAL(5,2) NULL,
  `sheet_improvement_rate` DECIMAL(5,2) NULL,
  `match_status`   ENUM('matched','member_not_found','order_not_found',
                        'duplicate','date_invalid','calendar_missing','imported') NOT NULL,
  `target_order_id` INT NULL,
  `error_detail`    VARCHAR(500),
  `created_at`      DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY `idx_batch`        (`batch_id`),
  KEY `idx_batch_status` (`batch_id`, `match_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
```

- [ ] **Step 2: Apply to DEV DB**

```bash
cd /root/pt-dev && source .db_credentials && \
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  < migrations/20260511_add_coaching_chart.sql
```

Expected: no errors, prompt returns.

- [ ] **Step 3: Verify schema**

```bash
mysql -u SORITUNECOM_DEV_PT -pVa4/7hdfj7oweLwMah3bHzXR SORITUNECOM_DEV_PT \
  -e "SHOW TABLES LIKE 'coaching%'; DESCRIBE order_sessions; SHOW COLUMNS FROM change_logs WHERE Field='target_type';"
```

Expected: `coaching_calendars`, `coaching_calendar_dates`, `coaching_log_migration_preview` present. `order_sessions` has new columns. `change_logs.target_type` includes 3 new values.

- [ ] **Step 4: Commit**

```bash
git add migrations/20260511_add_coaching_chart.sql
git commit -m "feat(pt): 회원 차트 마이그레이션 — 매칭 캘린더 + order_sessions 확장 + staging"
```

---

## Task 2: 코치 권한 가드 헬퍼

**Files:**
- Create: `public_html/includes/member_chart_access.php`
- Create: `tests/member_chart_access_test.php`

코치가 자기 담당 회원에만 접근 가능. 다른 회원 시 응답 404 (존재 자체 노출 방지).

- [ ] **Step 1: Write failing test**

`tests/member_chart_access_test.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../public_html/includes/member_chart_access.php';

t_section('member_chart_access');

// fixtures: coach 100 담당 회원 200, 코치 100 미담당 회원 300
$pdo = getDb();
// 테스트는 기존 PT 시드 데이터에 의존 — 실제 fixture 는 PT 운영 DB 카피
// 여기서는 매칭 엔진 mock 또는 실제 매칭 row 사용
$coach_id = (int)$pdo->query("SELECT id FROM coaches WHERE is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$assigned_member = (int)$pdo->query("
    SELECT DISTINCT o.member_id FROM orders o
    WHERE o.coach_id={$coach_id} AND o.status IN ('진행중','매칭완료') LIMIT 1
")->fetchColumn();
$random_member = (int)$pdo->query("
    SELECT m.id FROM members m
    WHERE m.id NOT IN (SELECT member_id FROM orders WHERE coach_id={$coach_id})
    ORDER BY m.id LIMIT 1
")->fetchColumn();

t_assert_true(
    coach_can_access_member($coach_id, $assigned_member),
    'coach can access own assigned member'
);
t_assert_eq(
    false,
    coach_can_access_member($coach_id, $random_member),
    'coach cannot access non-assigned member'
);
t_assert_eq(
    false,
    coach_can_access_member($coach_id, 99999999),
    'coach cannot access non-existent member'
);
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
cd /root/pt-dev && php tests/member_chart_access_test.php
```

Expected: error "coach_can_access_member not defined" 또는 require_once fail.

- [ ] **Step 3: Implement helper**

`public_html/includes/member_chart_access.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/**
 * 코치가 회원 차트에 접근 가능한지 확인.
 * 기준: 그 회원이 코치의 담당 order(진행중/매칭완료/연기 등 active 상태) 에 있어야 함.
 */
function coach_can_access_member(int $coach_id, int $member_id): bool {
    if ($coach_id <= 0 || $member_id <= 0) return false;
    $pdo = getDb();
    $stmt = $pdo->prepare("
        SELECT 1 FROM orders
        WHERE coach_id = :coach_id
          AND member_id = :member_id
          AND status NOT IN ('환불','중단')
        LIMIT 1
    ");
    $stmt->execute([':coach_id'=>$coach_id, ':member_id'=>$member_id]);
    return (bool)$stmt->fetchColumn();
}

/**
 * API 가드: 코치면 자기 회원만, 관리자면 모두. 실패 시 404 응답 + exit.
 */
function require_member_chart_access(int $member_id): array {
    require_once __DIR__ . '/auth.php';
    $user = getCurrentUser();
    if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'로그인이 필요합니다.']); exit; }

    if ($user['role'] === 'admin') return $user;
    if ($user['role'] === 'coach') {
        if (coach_can_access_member((int)$user['id'], $member_id)) return $user;
    }
    http_response_code(404);
    echo json_encode(['ok'=>false,'message'=>'회원을 찾을 수 없습니다.']);
    exit;
}
```

- [ ] **Step 4: Run test — expect PASS**

```bash
php tests/member_chart_access_test.php
```

Expected: 3 PASS, 0 FAIL.

- [ ] **Step 5: Commit**

```bash
git add public_html/includes/member_chart_access.php tests/member_chart_access_test.php
git commit -m "feat(pt): coach_can_access_member 가드 헬퍼 + 단위 테스트"
```

---

## Task 3: CoachingMetrics 헬퍼

**Files:**
- Create: `public_html/includes/coaching_metrics.php`
- Create: `tests/coaching_metrics_test.php`

진도율 / 개선율 계산. spec §3 식과 정확히 일치.

- [ ] **Step 1: Write failing test**

`tests/coaching_metrics_test.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../public_html/includes/coaching_metrics.php';

t_section('CoachingMetrics::for_order');

$pdo = getDb();

// fixture: 캘린더 + order + sessions 직접 insert
$pdo->beginTransaction();
$pdo->exec("INSERT INTO coaching_calendars (cohort_month, product_name, session_count, created_by)
            VALUES ('2099-12','TEST_PRODUCT_X',5,1)");
$cal = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO coaching_calendar_dates (calendar_id, session_number, scheduled_date) VALUES
    ($cal,1,'2099-12-01'),($cal,2,'2099-12-02'),($cal,3,'2099-12-03'),($cal,4,'2099-12-04'),($cal,5,'2099-12-05')");

// 테스트용 member, order
$pdo->exec("INSERT INTO members (soritune_id, name) VALUES ('TEST_X', 'Test User X')");
$member_id = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO orders (member_id, product_name, product_type, start_date, end_date, status, cohort_month)
            VALUES ($member_id,'TEST_PRODUCT_X','count','2099-12-01','2099-12-31','진행중','2099-12')");
$order_id = (int)$pdo->lastInsertId();

// 5개 세션: 3개 완료, 2개 미완료. 솔루션 4개, improved 2개.
$pdo->exec("INSERT INTO order_sessions
  (order_id, calendar_id, session_number, completed_at, progress, issue, solution, improved) VALUES
  ($order_id,$cal,1,'2099-12-01 10:00','p1','i1','s1',1),
  ($order_id,$cal,2,'2099-12-02 10:00','p2','i2','s2',0),
  ($order_id,$cal,3,'2099-12-03 10:00','p3','i3','s3',1),
  ($order_id,$cal,4,NULL,NULL,NULL,'s4',0),
  ($order_id,$cal,5,NULL,NULL,NULL,NULL,0)
");

$m = CoachingMetrics::for_order($order_id);

t_assert_eq(3, $m['done'],           'done count');
t_assert_eq(5, $m['total'],          'total count');
t_assert_eq(0.60, round($m['progress_rate'],2), 'progress_rate = 3/5 = 0.60');
t_assert_eq(2, $m['improved'],       'improved count');
t_assert_eq(4, $m['solution_total'], 'solution_total count');
t_assert_eq(0.50, round($m['improvement_rate'],2), 'improvement_rate = 2/4 = 0.50');

// 솔루션 0개일 때 (분모 0)
$pdo->exec("UPDATE order_sessions SET solution=NULL, improved=0 WHERE order_id=$order_id");
$m2 = CoachingMetrics::for_order($order_id);
t_assert_eq(0.0, $m2['improvement_rate'], 'improvement_rate=0 when no solutions');

$pdo->rollBack();
```

- [ ] **Step 2: Run — expect FAIL**

```bash
php tests/coaching_metrics_test.php
```

Expected: "CoachingMetrics not defined".

- [ ] **Step 3: Implement helper**

`public_html/includes/coaching_metrics.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

class CoachingMetrics {
    public static function for_order(int $order_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("
            SELECT
              COUNT(CASE WHEN os.completed_at IS NOT NULL THEN 1 END) AS done,
              COALESCE(cc.session_count, 0) AS total,
              COUNT(CASE WHEN os.improved = 1 THEN 1 END) AS improved,
              COUNT(CASE WHEN os.solution IS NOT NULL AND os.solution <> '' THEN 1 END) AS solution_total
            FROM order_sessions os
            LEFT JOIN coaching_calendars cc ON cc.id = os.calendar_id
            WHERE os.order_id = :order_id
            GROUP BY cc.session_count
        ");
        $stmt->execute([':order_id' => $order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['done'=>0,'total'=>0,'progress_rate'=>0.0,'improved'=>0,'solution_total'=>0,'improvement_rate'=>0.0];
        }
        $done = (int)$row['done']; $total = (int)$row['total'];
        $improved = (int)$row['improved']; $sol = (int)$row['solution_total'];
        return [
            'done' => $done,
            'total' => $total,
            'progress_rate' => $total > 0 ? round($done / $total, 4) : 0.0,
            'improved' => $improved,
            'solution_total' => $sol,
            'improvement_rate' => $sol > 0 ? round($improved / $sol, 4) : 0.0,
        ];
    }

    public static function for_member(int $member_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE member_id=:m AND status NOT IN ('환불','중단')");
        $stmt->execute([':m' => $member_id]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $agg = ['done'=>0,'total'=>0,'improved'=>0,'solution_total'=>0];
        foreach ($ids as $id) {
            $m = self::for_order((int)$id);
            $agg['done'] += $m['done'];
            $agg['total'] += $m['total'];
            $agg['improved'] += $m['improved'];
            $agg['solution_total'] += $m['solution_total'];
        }
        $agg['progress_rate'] = $agg['total'] > 0 ? round($agg['done'] / $agg['total'], 4) : 0.0;
        $agg['improvement_rate'] = $agg['solution_total'] > 0 ? round($agg['improved'] / $agg['solution_total'], 4) : 0.0;
        return $agg;
    }
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
php tests/coaching_metrics_test.php
```

Expected: 7 PASS, 0 FAIL.

- [ ] **Step 5: Commit**

```bash
git add public_html/includes/coaching_metrics.php tests/coaching_metrics_test.php
git commit -m "feat(pt): CoachingMetrics 헬퍼 (진도율/개선율) + 단위 테스트"
```

---

## Task 4: CoachingCalendar 헬퍼 + 자동 패턴 생성

**Files:**
- Create: `public_html/includes/coaching_calendar.php`
- Create: `tests/coaching_calendar_test.php`

캘린더 CRUD + 자동 패턴 후보 생성 (평일 5회 / 주 3회 / 주 2회 등).

- [ ] **Step 1: Write failing test (자동 패턴 + CRUD)**

`tests/coaching_calendar_test.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../public_html/includes/coaching_calendar.php';

t_section('CoachingCalendar::generate_pattern');

// 평일 5회 패턴, 5일치
$dates = CoachingCalendar::generate_pattern('2026-05-04', 5, 'weekday5');
t_assert_eq(['2026-05-04','2026-05-05','2026-05-06','2026-05-07','2026-05-08'], $dates, 'weekday5 from Monday');

// 주말 만나면 skip
$dates2 = CoachingCalendar::generate_pattern('2026-05-08', 5, 'weekday5');
t_assert_eq(['2026-05-08','2026-05-11','2026-05-12','2026-05-13','2026-05-14'], $dates2, 'weekday5 skip weekend');

// 주 3회 (월수금)
$dates3 = CoachingCalendar::generate_pattern('2026-05-04', 3, 'mwf');
t_assert_eq(['2026-05-04','2026-05-06','2026-05-08'], $dates3, 'mwf');

// 주 2회 (화목)
$dates4 = CoachingCalendar::generate_pattern('2026-05-05', 4, 'tt');
t_assert_eq(['2026-05-05','2026-05-07','2026-05-12','2026-05-14'], $dates4, 'tt');

t_section('CoachingCalendar::create + set_dates');

$pdo = getDb();
$pdo->beginTransaction();

$cal = CoachingCalendar::create([
    'cohort_month' => '2099-12',
    'product_name' => 'TEST_CAL_X',
    'session_count' => 3,
    'created_by' => 1,
]);
t_assert_true($cal > 0, 'create returns id');

CoachingCalendar::set_dates($cal, ['2099-12-01','2099-12-02','2099-12-03']);
$row = $pdo->query("SELECT COUNT(*) FROM coaching_calendar_dates WHERE calendar_id=$cal")->fetchColumn();
t_assert_eq(3, (int)$row, '3 dates set');

// session_count vs dates 개수 mismatch 검증
t_assert_throws(
    fn() => CoachingCalendar::set_dates($cal, ['2099-12-01','2099-12-02']),
    InvalidArgumentException::class,
    'mismatched date count throws'
);

t_section('CoachingCalendar::get_for_order');

$pdo->exec("INSERT INTO members (soritune_id, name) VALUES ('TEST_CY','y')");
$mid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO orders (member_id, product_name, product_type, start_date, end_date, status, cohort_month)
            VALUES ($mid,'TEST_CAL_X','count','2099-12-01','2099-12-31','진행중','2099-12')");
$oid = (int)$pdo->lastInsertId();

$found = CoachingCalendar::get_for_order($oid);
t_assert_eq($cal, $found['id'], 'lookup by cohort_month+product_name');

$pdo->rollBack();
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement helper**

`public_html/includes/coaching_calendar.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

class CoachingCalendar {
    /**
     * 자동 패턴 후보 날짜 N개 생성.
     * patterns: weekday5 (월~금), mwf (월수금), tt (화목), every_day, weekend
     */
    public static function generate_pattern(string $start, int $count, string $pattern): array {
        $allowed_dow = match ($pattern) {
            'weekday5' => [1,2,3,4,5],
            'mwf'      => [1,3,5],
            'tt'       => [2,4],
            'every_day' => [0,1,2,3,4,5,6],
            'weekend'  => [0,6],
            default => throw new InvalidArgumentException("unknown pattern: $pattern"),
        };
        $out = [];
        $d = new DateTimeImmutable($start);
        $safety = 0;
        while (count($out) < $count) {
            if (in_array((int)$d->format('w'), $allowed_dow, true)) {
                $out[] = $d->format('Y-m-d');
            }
            $d = $d->modify('+1 day');
            if (++$safety > 1000) throw new RuntimeException('pattern overflow');
        }
        return $out;
    }

    public static function create(array $data): int {
        $pdo = getDb();
        $stmt = $pdo->prepare("INSERT INTO coaching_calendars
            (cohort_month, product_name, session_count, notes, created_by)
            VALUES (:cm, :pn, :sc, :nt, :cb)");
        $stmt->execute([
            ':cm' => $data['cohort_month'],
            ':pn' => $data['product_name'],
            ':sc' => (int)$data['session_count'],
            ':nt' => $data['notes'] ?? null,
            ':cb' => (int)$data['created_by'],
        ]);
        $id = (int)$pdo->lastInsertId();
        self::log_change('coaching_calendar', $id, 'create', null, $data, (int)$data['created_by']);
        return $id;
    }

    public static function update(int $id, array $data, int $actor): void {
        $pdo = getDb();
        $before = $pdo->query("SELECT * FROM coaching_calendars WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new RuntimeException("calendar not found");
        $stmt = $pdo->prepare("UPDATE coaching_calendars
            SET session_count=:sc, notes=:nt WHERE id=:id");
        $stmt->execute([':sc'=>(int)$data['session_count'], ':nt'=>$data['notes']??null, ':id'=>$id]);
        self::log_change('coaching_calendar', $id, 'update', $before, $data, $actor);
    }

    public static function delete(int $id, int $actor): void {
        $pdo = getDb();
        $before = $pdo->query("SELECT * FROM coaching_calendars WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        if (!$before) return;
        $pdo->prepare("DELETE FROM coaching_calendars WHERE id=:id")->execute([':id'=>$id]);
        self::log_change('coaching_calendar', $id, 'delete', $before, null, $actor);
    }

    public static function set_dates(int $calendar_id, array $dates): void {
        $pdo = getDb();
        $cal = $pdo->query("SELECT session_count FROM coaching_calendars WHERE id=$calendar_id")->fetch(PDO::FETCH_ASSOC);
        if (!$cal) throw new RuntimeException("calendar not found");
        if (count($dates) !== (int)$cal['session_count']) {
            throw new InvalidArgumentException("date count " . count($dates) . " != session_count " . $cal['session_count']);
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM coaching_calendar_dates WHERE calendar_id=$calendar_id");
            $stmt = $pdo->prepare("INSERT INTO coaching_calendar_dates (calendar_id, session_number, scheduled_date) VALUES (:c,:n,:d)");
            foreach ($dates as $i => $date) {
                $stmt->execute([':c'=>$calendar_id, ':n'=>$i+1, ':d'=>$date]);
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public static function get_for_order(int $order_id): ?array {
        $pdo = getDb();
        $stmt = $pdo->prepare("
            SELECT cc.* FROM coaching_calendars cc
            JOIN orders o ON o.product_name = cc.product_name AND o.cohort_month = cc.cohort_month
            WHERE o.id = :oid LIMIT 1
        ");
        $stmt->execute([':oid' => $order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function get_dates(int $calendar_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT session_number, scheduled_date FROM coaching_calendar_dates
                               WHERE calendar_id=:c ORDER BY session_number");
        $stmt->execute([':c' => $calendar_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function log_change(string $target_type, int $target_id, string $action, ?array $before, ?array $after, int $actor_id): void {
        $pdo = getDb();
        $stmt = $pdo->prepare("INSERT INTO change_logs (actor_type, actor_id, target_type, target_id, action, before_json, after_json)
                               VALUES ('admin', :aid, :tt, :tid, :ac, :b, :a)");
        $stmt->execute([
            ':aid' => $actor_id,
            ':tt'  => $target_type,
            ':tid' => $target_id,
            ':ac'  => $action,
            ':b'   => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            ':a'   => $after  ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
```

- [ ] **Step 4: Inspect change_logs schema for column names**

```bash
mysql -u SORITUNECOM_DEV_PT -pVa4/7hdfj7oweLwMah3bHzXR SORITUNECOM_DEV_PT -e "DESCRIBE change_logs"
```

If column names differ from `before_json`/`after_json`/`action` etc., adjust `log_change()` to match. (PT 표준은 보통 `before_data`, `after_data`, `action` 또는 유사 — 실제 컬럼명 확인 후 정정.)

- [ ] **Step 5: Run — expect PASS**

```bash
php tests/coaching_calendar_test.php
```

Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add public_html/includes/coaching_calendar.php tests/coaching_calendar_test.php
git commit -m "feat(pt): CoachingCalendar 헬퍼 (CRUD + 자동 패턴) + 단위 테스트"
```

---

## Task 5: CoachingLog 헬퍼

**Files:**
- Create: `public_html/includes/coaching_log.php`
- Create: `tests/coaching_log_test.php`

`order_sessions` CRUD + bulk update + 자동 calendar_id 매칭.

- [ ] **Step 1: Write failing test**

`tests/coaching_log_test.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../public_html/includes/coaching_log.php';
require_once __DIR__ . '/../public_html/includes/coaching_calendar.php';

t_section('CoachingLog::create_for_order — auto link calendar');

$pdo = getDb();
$pdo->beginTransaction();

// fixture: calendar
$pdo->exec("INSERT INTO coaching_calendars (cohort_month, product_name, session_count, created_by)
            VALUES ('2099-11','LOG_TEST_X',3,1)");
$cal = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO coaching_calendar_dates (calendar_id, session_number, scheduled_date) VALUES
    ($cal,1,'2099-11-01'),($cal,2,'2099-11-02'),($cal,3,'2099-11-03')");

$pdo->exec("INSERT INTO members (soritune_id, name) VALUES ('LOG_X','y')");
$mid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO orders (member_id, product_name, product_type, start_date, end_date, status, cohort_month)
            VALUES ($mid,'LOG_TEST_X','count','2099-11-01','2099-11-30','진행중','2099-11')");
$oid = (int)$pdo->lastInsertId();

$sid = CoachingLog::create_for_order($oid, [
    'session_number' => 1,
    'progress' => 'P1',
    'issue' => 'I1',
    'solution' => 'S1',
    'improved' => 1,
    'completed_at' => '2099-11-01 10:00:00',
], 1);
t_assert_true($sid > 0, 'create returns id');

$row = $pdo->query("SELECT calendar_id, progress, improved FROM order_sessions WHERE id=$sid")->fetch(PDO::FETCH_ASSOC);
t_assert_eq($cal, (int)$row['calendar_id'], 'calendar_id auto-linked');
t_assert_eq('P1', $row['progress'], 'progress saved');
t_assert_eq(1, (int)$row['improved'], 'improved saved');

t_section('CoachingLog::update');

CoachingLog::update($sid, ['progress'=>'P1-edited','improved'=>0], 1);
$row2 = $pdo->query("SELECT progress, improved FROM order_sessions WHERE id=$sid")->fetch(PDO::FETCH_ASSOC);
t_assert_eq('P1-edited', $row2['progress'], 'update progress');
t_assert_eq(0, (int)$row2['improved'], 'update improved');

t_section('CoachingLog::bulk_update');

$sid2 = CoachingLog::create_for_order($oid, ['session_number'=>2], 1);
$sid3 = CoachingLog::create_for_order($oid, ['session_number'=>3], 1);
CoachingLog::bulk_update([$sid2, $sid3], ['completed_at' => '2099-11-30 12:00:00'], 1);

$done = (int)$pdo->query("SELECT COUNT(*) FROM order_sessions WHERE order_id=$oid AND completed_at IS NOT NULL")->fetchColumn();
t_assert_eq(3, $done, 'bulk completion');

$pdo->rollBack();
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement helper**

`public_html/includes/coaching_log.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coaching_calendar.php';

class CoachingLog {
    /**
     * order_sessions row 생성 (혹은 (order_id, session_number) UPSERT).
     * calendar_id 는 그 order 의 매칭 캘린더로 자동 link.
     */
    public static function create_for_order(int $order_id, array $data, int $actor_id): int {
        $pdo = getDb();
        $cal = CoachingCalendar::get_for_order($order_id);
        $calendar_id = $cal['id'] ?? null;

        $sn = (int)$data['session_number'];

        // UPSERT
        $existing = $pdo->prepare("SELECT id FROM order_sessions WHERE order_id=:o AND session_number=:n");
        $existing->execute([':o'=>$order_id, ':n'=>$sn]);
        $eid = (int)$existing->fetchColumn();
        if ($eid > 0) {
            self::update($eid, $data, $actor_id);
            return $eid;
        }

        $stmt = $pdo->prepare("INSERT INTO order_sessions
            (order_id, calendar_id, session_number, completed_at, progress, issue, solution, improved, improved_at, updated_by)
            VALUES (:o,:c,:n,:done,:p,:i,:s,:imp,:impa,:u)");
        $imp = (int)($data['improved'] ?? 0);
        $stmt->execute([
            ':o'=>$order_id, ':c'=>$calendar_id, ':n'=>$sn,
            ':done'=>$data['completed_at']??null,
            ':p'=>$data['progress']??null, ':i'=>$data['issue']??null,
            ':s'=>$data['solution']??null, ':imp'=>$imp,
            ':impa'=>$imp ? ($data['improved_at']??date('Y-m-d H:i:s')) : null,
            ':u'=>$actor_id,
        ]);
        $id = (int)$pdo->lastInsertId();
        self::log_change('order_session', $id, 'create', null, $data, $actor_id);
        return $id;
    }

    public static function update(int $session_id, array $data, int $actor_id): void {
        $pdo = getDb();
        $before = $pdo->query("SELECT * FROM order_sessions WHERE id=$session_id")->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new RuntimeException("session not found");

        $fields = []; $params = [':id'=>$session_id, ':u'=>$actor_id];
        foreach (['progress','issue','solution','completed_at','memo'] as $k) {
            if (array_key_exists($k, $data)) { $fields[] = "$k=:$k"; $params[":$k"] = $data[$k]; }
        }
        if (array_key_exists('improved', $data)) {
            $imp = (int)$data['improved'];
            $fields[] = "improved=:imp"; $params[':imp'] = $imp;
            if ($imp && empty($before['improved_at'])) {
                $fields[] = "improved_at=:impa"; $params[':impa'] = date('Y-m-d H:i:s');
            } elseif (!$imp) {
                $fields[] = "improved_at=NULL";
            }
        }
        $fields[] = "updated_by=:u";
        if (!$fields) return;
        $sql = "UPDATE order_sessions SET " . implode(',', $fields) . " WHERE id=:id";
        $pdo->prepare($sql)->execute($params);
        self::log_change('order_session', $session_id, 'update', $before, $data, $actor_id);
    }

    public static function bulk_update(array $session_ids, array $data, int $actor_id): int {
        if (empty($session_ids)) return 0;
        $count = 0;
        foreach ($session_ids as $sid) {
            self::update((int)$sid, $data, $actor_id);
            $count++;
        }
        return $count;
    }

    public static function delete(int $session_id, int $actor_id): void {
        $pdo = getDb();
        $before = $pdo->query("SELECT * FROM order_sessions WHERE id=$session_id")->fetch(PDO::FETCH_ASSOC);
        if (!$before) return;
        $pdo->prepare("DELETE FROM order_sessions WHERE id=:id")->execute([':id'=>$session_id]);
        self::log_change('order_session', $session_id, 'delete', $before, null, $actor_id);
    }

    public static function list_for_order(int $order_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("
            SELECT os.*, ccd.scheduled_date
            FROM order_sessions os
            LEFT JOIN coaching_calendar_dates ccd
              ON ccd.calendar_id = os.calendar_id AND ccd.session_number = os.session_number
            WHERE os.order_id = :o
            ORDER BY os.session_number
        ");
        $stmt->execute([':o' => $order_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function log_change(string $tt, int $tid, string $action, ?array $before, ?array $after, int $actor_id): void {
        $pdo = getDb();
        $stmt = $pdo->prepare("INSERT INTO change_logs (actor_type, actor_id, target_type, target_id, action, before_json, after_json)
                               VALUES ('coach', :aid, :tt, :tid, :ac, :b, :a)");
        $stmt->execute([
            ':aid'=>$actor_id, ':tt'=>$tt, ':tid'=>$tid, ':ac'=>$action,
            ':b'=>$before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            ':a'=>$after  ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
php tests/coaching_log_test.php
```

Expected: all PASS. (change_logs 컬럼명 mismatch 시 Task 4와 같은 보정.)

- [ ] **Step 5: Commit**

```bash
git add public_html/includes/coaching_log.php tests/coaching_log_test.php
git commit -m "feat(pt): CoachingLog 헬퍼 (CRUD + bulk + auto calendar link)"
```

---

## Task 6: /api/coaching_calendar.php

**Files:**
- Create: `public_html/api/coaching_calendar.php`

admin only. GET list / get_for_order / POST create / POST pattern_preview / PUT update / DELETE.

- [ ] **Step 1: Implement endpoint**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coaching_calendar.php';

requireAdmin();
$user = getCurrentUser();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list': {
            $pdo = getDb();
            $rows = $pdo->query("SELECT * FROM coaching_calendars ORDER BY cohort_month DESC, product_name")
                ->fetchAll(PDO::FETCH_ASSOC);
            jsonSuccess(['calendars' => $rows]);
        }
        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            $pdo = getDb();
            $cal = $pdo->query("SELECT * FROM coaching_calendars WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
            if (!$cal) jsonError('캘린더를 찾을 수 없습니다', 404);
            $cal['dates'] = CoachingCalendar::get_dates($id);
            jsonSuccess(['calendar' => $cal]);
        }
        case 'pattern_preview': {
            $in = getJsonInput();
            $dates = CoachingCalendar::generate_pattern(
                $in['start'] ?? '', (int)($in['count'] ?? 0), $in['pattern'] ?? ''
            );
            jsonSuccess(['dates' => $dates]);
        }
        case 'create': {
            $in = getJsonInput();
            $cal_id = CoachingCalendar::create([
                'cohort_month' => $in['cohort_month'],
                'product_name' => $in['product_name'],
                'session_count'=> (int)$in['session_count'],
                'notes'        => $in['notes'] ?? null,
                'created_by'   => (int)$user['id'],
            ]);
            CoachingCalendar::set_dates($cal_id, $in['dates'] ?? []);
            jsonSuccess(['id' => $cal_id]);
        }
        case 'update': {
            $id = (int)($_GET['id'] ?? 0);
            $in = getJsonInput();
            CoachingCalendar::update($id, [
                'session_count' => (int)$in['session_count'],
                'notes'         => $in['notes'] ?? null,
            ], (int)$user['id']);
            if (isset($in['dates'])) CoachingCalendar::set_dates($id, $in['dates']);
            jsonSuccess();
        }
        case 'delete': {
            $id = (int)($_GET['id'] ?? 0);
            CoachingCalendar::delete($id, (int)$user['id']);
            jsonSuccess();
        }
        default:
            jsonError('unknown action', 400);
    }
} catch (Throwable $e) {
    error_log('coaching_calendar API: ' . $e->getMessage());
    jsonError('서버 오류: ' . $e->getMessage(), 500);
}
```

- [ ] **Step 2: Manual smoke**

```bash
# admin 세션 쿠키 있는 상태에서:
curl -s -X POST http://dev-pt.soritune.com/api/coaching_calendar.php?action=pattern_preview \
  -H 'Content-Type: application/json' \
  -d '{"start":"2026-05-04","count":5,"pattern":"weekday5"}'
```

Expected: `{"ok":true,"data":{"dates":["2026-05-04",...,"2026-05-08"]}}`

- [ ] **Step 3: Commit**

```bash
git add public_html/api/coaching_calendar.php
git commit -m "feat(pt): /api/coaching_calendar.php — admin CRUD + pattern_preview"
```

---

## Task 7: /api/coaching_log.php

**Files:**
- Create: `public_html/api/coaching_log.php`

admin || coach_can_access_member. list / single / create / update / delete / bulk.

- [ ] **Step 1: Implement endpoint**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coaching_log.php';
require_once __DIR__ . '/../includes/member_chart_access.php';

$user = getCurrentUser();
if (!$user) jsonError('로그인이 필요합니다', 401);

$action = $_GET['action'] ?? '';

/** 권한 가드: order_id → member_id → require_member_chart_access */
function _guard_by_order(int $order_id): array {
    $pdo = getDb();
    $member_id = (int)$pdo->query("SELECT member_id FROM orders WHERE id=$order_id")->fetchColumn();
    if (!$member_id) jsonError('주문을 찾을 수 없습니다', 404);
    return require_member_chart_access($member_id);
}
function _guard_by_session(int $sid): array {
    $pdo = getDb();
    $oid = (int)$pdo->query("SELECT order_id FROM order_sessions WHERE id=$sid")->fetchColumn();
    if (!$oid) jsonError('세션을 찾을 수 없습니다', 404);
    return _guard_by_order($oid);
}

try {
    switch ($action) {
        case 'list': {
            $oid = (int)($_GET['order_id'] ?? 0);
            _guard_by_order($oid);
            jsonSuccess(['sessions' => CoachingLog::list_for_order($oid)]);
        }
        case 'create': {
            $in = getJsonInput();
            $oid = (int)$in['order_id'];
            $u = _guard_by_order($oid);
            $sid = CoachingLog::create_for_order($oid, $in, (int)$u['id']);
            jsonSuccess(['id' => $sid]);
        }
        case 'update': {
            $sid = (int)($_GET['id'] ?? 0);
            $u = _guard_by_session($sid);
            CoachingLog::update($sid, getJsonInput(), (int)$u['id']);
            jsonSuccess();
        }
        case 'delete': {
            $sid = (int)($_GET['id'] ?? 0);
            $u = _guard_by_session($sid);
            CoachingLog::delete($sid, (int)$u['id']);
            jsonSuccess();
        }
        case 'bulk_update': {
            $in = getJsonInput();
            $ids = array_map('intval', $in['ids'] ?? []);
            if (empty($ids)) jsonError('ids 없음', 400);
            // 모든 session 이 같은 권한 범위인지 검증
            foreach ($ids as $sid) _guard_by_session($sid);
            $n = CoachingLog::bulk_update($ids, $in['data'] ?? [], (int)$user['id']);
            jsonSuccess(['updated' => $n]);
        }
        default:
            jsonError('unknown action', 400);
    }
} catch (Throwable $e) {
    error_log('coaching_log API: ' . $e->getMessage());
    jsonError('서버 오류: ' . $e->getMessage(), 500);
}
```

- [ ] **Step 2: Manual smoke**

```bash
# coach 세션으로 자기 회원의 order list 호출 → 200
# coach 세션으로 남의 order list 호출 → 404
```

- [ ] **Step 3: Commit**

```bash
git add public_html/api/coaching_log.php
git commit -m "feat(pt): /api/coaching_log.php — CRUD + bulk + 권한 가드"
```

---

## Task 8: /api/member_chart.php — 통합 data fetch

**Files:**
- Create: `public_html/api/member_chart.php`

회원 차트 페이지가 1회 호출로 필요한 모든 data 받음 — member + orders + tests + calendars + sessions + metrics.

- [ ] **Step 1: Implement endpoint**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/member_chart_access.php';
require_once __DIR__ . '/../includes/coaching_metrics.php';
require_once __DIR__ . '/../includes/coaching_log.php';
require_once __DIR__ . '/../includes/coaching_calendar.php';

$member_id = (int)($_GET['member_id'] ?? 0);
if (!$member_id) jsonError('member_id 필요', 400);

require_member_chart_access($member_id);

try {
    $pdo = getDb();
    $member = $pdo->query("SELECT * FROM members WHERE id=$member_id")->fetch(PDO::FETCH_ASSOC);
    if (!$member) jsonError('회원을 찾을 수 없습니다', 404);

    $orders = $pdo->prepare("
        SELECT o.*, c.name AS coach_name
        FROM orders o LEFT JOIN coaches c ON c.id = o.coach_id
        WHERE o.member_id = :m
        ORDER BY o.start_date DESC
    ");
    $orders->execute([':m' => $member_id]);
    $orderRows = $orders->fetchAll(PDO::FETCH_ASSOC);

    // 주문별 sessions + metrics + calendar dates
    foreach ($orderRows as &$o) {
        $oid = (int)$o['id'];
        $o['sessions'] = CoachingLog::list_for_order($oid);
        $o['metrics']  = CoachingMetrics::for_order($oid);
        $cal = CoachingCalendar::get_for_order($oid);
        $o['calendar'] = $cal ? ['id'=>$cal['id'], 'session_count'=>$cal['session_count'],
                                   'dates' => CoachingCalendar::get_dates((int)$cal['id'])] : null;
    }

    $tests = $pdo->prepare("SELECT test_type, result_data, tested_at FROM test_results WHERE member_id=:m ORDER BY tested_at DESC");
    $tests->execute([':m' => $member_id]);
    $testRows = $tests->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess([
        'member' => $member,
        'orders' => $orderRows,
        'tests'  => $testRows,
        'member_metrics' => CoachingMetrics::for_member($member_id),
    ]);
} catch (Throwable $e) {
    error_log('member_chart API: ' . $e->getMessage());
    jsonError('서버 오류: ' . $e->getMessage(), 500);
}
```

- [ ] **Step 2: Manual smoke**

```bash
curl -s "http://dev-pt.soritune.com/api/member_chart.php?member_id=1"
```

Expected: 200 + `data.member`, `data.orders[]` with sessions/metrics/calendar.

- [ ] **Step 3: Commit**

```bash
git add public_html/api/member_chart.php
git commit -m "feat(pt): /api/member_chart.php — 통합 data fetch + 권한 가드"
```

---

## Task 9: 관리자 캘린더 페이지 UI

**Files:**
- Create: `public_html/admin/js/pages/coaching-calendar.js`
- Modify: `public_html/admin/index.php` (사이드바 메뉴)
- Modify: `public_html/admin/js/app.js` (페이지 등록 자동 — 별도 작업 X)

- [ ] **Step 1: Add sidebar menu**

`public_html/admin/index.php` 사이드바 nav 영역에 항목 추가 (기존 패턴 따라):

```html
<a href="#coaching-calendar" data-page="coaching-calendar">코칭 캘린더</a>
```

- [ ] **Step 2: Add script tag**

`public_html/admin/index.php` 의 다른 page 스크립트들 옆에:

```html
<script src="/admin/js/pages/coaching-calendar.js?v=20260511"></script>
```

- [ ] **Step 3: Create page**

`public_html/admin/js/pages/coaching-calendar.js`:

```javascript
/**
 * 매칭 캘린더 관리 (관리자만)
 */
App.registerPage('coaching-calendar', {
  calendars: [],
  current: null,

  async render() {
    document.getElementById('pageContent').innerHTML = '<div class="loading">불러오는 중...</div>';
    const res = await API.get('/api/coaching_calendar.php?action=list');
    if (!res.ok) { document.getElementById('pageContent').innerHTML = `<div class="empty-state">${res.message}</div>`; return; }
    this.calendars = res.data.calendars;
    this.renderList();
  },

  renderList() {
    const rows = this.calendars.map(c => `
      <tr onclick="App.pages['coaching-calendar'].openEdit(${c.id})" style="cursor:pointer">
        <td>${UI.esc(c.cohort_month)}</td>
        <td>${UI.esc(c.product_name)}</td>
        <td>${c.session_count}회</td>
        <td>${UI.esc(c.notes || '')}</td>
      </tr>`).join('');
    document.getElementById('pageContent').innerHTML = `
      <div class="page-header">
        <h2>매칭 캘린더</h2>
        <button class="btn btn-primary" onclick="App.pages['coaching-calendar'].openCreate()">+ 신규</button>
      </div>
      <table class="data-table">
        <thead><tr><th>매칭월</th><th>상품</th><th>회차 수</th><th>메모</th></tr></thead>
        <tbody>${rows || '<tr><td colspan="4" class="empty">캘린더가 없습니다</td></tr>'}</tbody>
      </table>
    `;
  },

  openCreate() { this._openModal(null); },
  async openEdit(id) {
    const res = await API.get(`/api/coaching_calendar.php?action=get&id=${id}`);
    if (!res.ok) return alert(res.message);
    this._openModal(res.data.calendar);
  },

  _openModal(cal) {
    const isNew = !cal;
    const dates = isNew ? [] : cal.dates.map(d => d.scheduled_date);
    UI.openModal({
      title: isNew ? '신규 캘린더' : `${cal.cohort_month} / ${cal.product_name}`,
      body: `
        <div class="form-row">
          <label>매칭월 (YYYY-MM)</label>
          <input id="cal-cohort" value="${isNew ? '' : UI.esc(cal.cohort_month)}" ${isNew?'':'disabled'}>
        </div>
        <div class="form-row">
          <label>상품명</label>
          <input id="cal-product" value="${isNew ? '' : UI.esc(cal.product_name)}" ${isNew?'':'disabled'}>
        </div>
        <div class="form-row">
          <label>회차 수</label>
          <input id="cal-count" type="number" min="1" value="${cal?.session_count || 20}">
        </div>
        <div class="form-row">
          <label>자동 패턴</label>
          <input id="cal-start" type="date" value="${dates[0]||''}">
          <select id="cal-pattern">
            <option value="weekday5">평일 5회 (월~금)</option>
            <option value="mwf">주 3회 (월수금)</option>
            <option value="tt">주 2회 (화목)</option>
            <option value="every_day">매일</option>
          </select>
          <button class="btn btn-secondary" onclick="App.pages['coaching-calendar'].generatePreview()">후보 생성</button>
        </div>
        <div class="form-row">
          <label>예정일 (수정 가능, 한 줄에 하나)</label>
          <textarea id="cal-dates" rows="10">${dates.join('\n')}</textarea>
          <div class="form-help">YYYY-MM-DD 한 줄에 하나. 회차 수와 일치해야 함.</div>
        </div>
      `,
      footer: `
        <button class="btn btn-primary" onclick="App.pages['coaching-calendar'].save(${cal?.id || 'null'})">저장</button>
        ${cal ? `<button class="btn btn-danger" onclick="App.pages['coaching-calendar'].del(${cal.id})">삭제</button>` : ''}
        <button class="btn" onclick="UI.closeModal()">취소</button>
      `,
    });
  },

  async generatePreview() {
    const start = document.getElementById('cal-start').value;
    const count = parseInt(document.getElementById('cal-count').value);
    const pattern = document.getElementById('cal-pattern').value;
    if (!start || !count) return alert('시작일·회차 수 필요');
    const res = await API.post('/api/coaching_calendar.php?action=pattern_preview', {start, count, pattern});
    if (!res.ok) return alert(res.message);
    document.getElementById('cal-dates').value = res.data.dates.join('\n');
  },

  async save(id) {
    const cohort = document.getElementById('cal-cohort').value.trim();
    const product = document.getElementById('cal-product').value.trim();
    const count = parseInt(document.getElementById('cal-count').value);
    const dates = document.getElementById('cal-dates').value.split('\n').map(s=>s.trim()).filter(Boolean);
    if (dates.length !== count) return alert(`회차 수(${count})와 예정일 개수(${dates.length}) 불일치`);
    const body = {cohort_month: cohort, product_name: product, session_count: count, dates};
    const url = id ? `/api/coaching_calendar.php?action=update&id=${id}` : '/api/coaching_calendar.php?action=create';
    const res = id ? await API.put(url, body) : await API.post(url, body);
    if (!res.ok) return alert(res.message);
    UI.closeModal();
    this.render();
  },

  async del(id) {
    if (!confirm('삭제하시겠습니까? (관련 order_sessions 의 calendar_id 는 NULL 로 끊김)')) return;
    const res = await API.del(`/api/coaching_calendar.php?action=delete&id=${id}`);
    if (!res.ok) return alert(res.message);
    UI.closeModal();
    this.render();
  },
});
```

- [ ] **Step 4: DEV smoke**

브라우저에서 `/admin/#coaching-calendar` 접속 → 신규 캘린더 생성 → 자동 패턴 후보 → 저장 → list 에 표시 확인.

- [ ] **Step 5: Commit**

```bash
git add public_html/admin/index.php public_html/admin/js/pages/coaching-calendar.js
git commit -m "feat(pt): admin 매칭 캘린더 페이지 — 생성·자동 패턴·수정·삭제"
```

---

## Task 10: 공통 코칭 차트 모듈 (admin + coach 양쪽 재사용)

**Files:**
- Create: `public_html/assets/js/coaching-chart.js`
- Modify: `public_html/admin/index.php` — `<script src="/assets/js/coaching-chart.js">` 추가
- Modify: `public_html/coach/index.php` — 동일 추가

회원 차트 페이지에서 호출할 컴포넌트들. admin/coach 양쪽이 같은 코드 호출.

- [ ] **Step 1: Create shared module**

`public_html/assets/js/coaching-chart.js`:

```javascript
/**
 * 회원 차트 — 코칭 영역 컴포넌트 (admin + coach 공유)
 * 호출자: 부모 페이지가 member_id 와 mountEl 을 넘김.
 */
window.CoachingChart = {

  async mount(member_id, mountEl) {
    mountEl.innerHTML = '<div class="loading">코칭 데이터 불러오는 중...</div>';
    const res = await API.get(`/api/member_chart.php?member_id=${member_id}`);
    if (!res.ok) { mountEl.innerHTML = `<div class="empty-state">${res.message}</div>`; return; }
    this.data = res.data;
    this.member_id = member_id;
    this.mountEl = mountEl;
    this.render();
  },

  render() {
    const m = this.data.member_metrics;
    const orders = this.data.orders;

    const metricsHtml = `
      <div class="metrics-grid">
        <div class="metric-card"><div class="metric-label">피드백 진도율</div><div class="metric-value">${this._pct(m.progress_rate)}</div><div class="metric-sub">${m.done}/${m.total}</div></div>
        <div class="metric-card"><div class="metric-label">개선율</div><div class="metric-value">${this._pct(m.improvement_rate)}</div><div class="metric-sub">${m.improved}/${m.solution_total}</div></div>
        <div class="metric-card"><div class="metric-label">남은 회차</div><div class="metric-value">${m.total - m.done}</div></div>
      </div>
    `;

    const ordersHtml = orders.map(o => this._renderOrder(o)).join('');

    this.mountEl.innerHTML = `
      <div class="coaching-chart">
        ${metricsHtml}
        ${ordersHtml || '<div class="empty-state">주문이 없습니다</div>'}
      </div>
    `;
  },

  _renderOrder(o) {
    const sessions = o.sessions || [];
    const cal = o.calendar;
    const rows = sessions.map(s => this._renderSessionRow(s)).join('');
    return `
      <div class="order-card">
        <h3>${UI.esc(o.product_name)} <span class="muted">(${UI.esc(o.cohort_month || '-')})</span>
          <span class="status-badge">${UI.esc(o.status)}</span></h3>
        <div class="bulk-bar" id="bulk-${o.id}" style="display:none">
          <span class="selected-count"></span>
          <button class="btn btn-sm" onclick="CoachingChart.bulkComplete(${o.id})">일괄 완료처리</button>
          <button class="btn btn-sm btn-danger" onclick="CoachingChart.bulkDelete(${o.id})">삭제</button>
        </div>
        <table class="data-table session-table">
          <thead><tr>
            <th><input type="checkbox" onchange="CoachingChart.toggleAll(${o.id}, this.checked)"></th>
            <th>회차</th><th>예정일</th><th>완료일</th><th>진도</th><th>문제</th><th>솔루션</th><th>개선</th><th></th>
          </tr></thead>
          <tbody>${rows || `<tr><td colspan="9" class="empty">로그 없음. ${cal ? '+ 신규 회차 추가' : '캘린더 매칭 안됨'}</td></tr>`}</tbody>
        </table>
        <button class="btn btn-secondary" onclick="CoachingChart.addRow(${o.id})">+ 회차 추가</button>
      </div>
    `;
  },

  _renderSessionRow(s) {
    return `
      <tr class="session-row" data-id="${s.id}">
        <td><input type="checkbox" class="row-check" data-order="${s.order_id}" onchange="CoachingChart.updateBulkBar(${s.order_id})"></td>
        <td>${s.session_number}</td>
        <td>${UI.esc(s.scheduled_date || '-')}</td>
        <td>${UI.esc((s.completed_at||'').substr(0,10) || '-')}</td>
        <td class="cell-text">${UI.esc(s.progress || '')}</td>
        <td class="cell-text">${UI.esc(s.issue || '')}</td>
        <td class="cell-text">${UI.esc(s.solution || '')}</td>
        <td>${parseInt(s.improved) ? '✓' : ''}</td>
        <td><button class="btn btn-xs" onclick="CoachingChart.editRow(${s.id})">편집</button></td>
      </tr>
    `;
  },

  async addRow(order_id) {
    const o = this.data.orders.find(x => x.id == order_id);
    const next = (o.sessions.length ? Math.max(...o.sessions.map(s=>s.session_number)) : 0) + 1;
    this._openEditModal(null, order_id, next);
  },

  editRow(session_id) {
    let session = null, order_id = null;
    for (const o of this.data.orders) {
      const s = o.sessions.find(x => x.id == session_id);
      if (s) { session = s; order_id = o.id; break; }
    }
    if (!session) return;
    this._openEditModal(session, order_id, session.session_number);
  },

  _openEditModal(session, order_id, session_number) {
    const isNew = !session;
    UI.openModal({
      title: `회차 ${session_number}`,
      body: `
        <div class="form-row"><label>완료 처리</label><label class="switch"><input type="checkbox" id="ses-done" ${session?.completed_at ? 'checked':''}></label></div>
        <div class="form-row"><label>진도</label><textarea id="ses-progress" rows="2">${UI.esc(session?.progress || '')}</textarea></div>
        <div class="form-row"><label>문제</label><textarea id="ses-issue" rows="2">${UI.esc(session?.issue || '')}</textarea></div>
        <div class="form-row"><label>솔루션</label><textarea id="ses-solution" rows="2">${UI.esc(session?.solution || '')}</textarea></div>
        <div class="form-row"><label>개선됨</label><label class="switch"><input type="checkbox" id="ses-improved" ${parseInt(session?.improved||0) ? 'checked':''}></label></div>
      `,
      footer: `
        <button class="btn btn-primary" onclick="CoachingChart.saveRow(${session?.id || 'null'}, ${order_id}, ${session_number})">저장</button>
        ${session ? `<button class="btn btn-danger" onclick="CoachingChart.deleteRow(${session.id})">삭제</button>` : ''}
        <button class="btn" onclick="UI.closeModal()">취소</button>
      `,
    });
  },

  async saveRow(session_id, order_id, session_number) {
    const data = {
      session_number,
      progress: document.getElementById('ses-progress').value,
      issue: document.getElementById('ses-issue').value,
      solution: document.getElementById('ses-solution').value,
      improved: document.getElementById('ses-improved').checked ? 1 : 0,
      completed_at: document.getElementById('ses-done').checked
        ? (new Date()).toISOString().slice(0,19).replace('T',' ')
        : null,
    };
    let res;
    if (session_id) {
      res = await API.put(`/api/coaching_log.php?action=update&id=${session_id}`, data);
    } else {
      res = await API.post('/api/coaching_log.php?action=create', {order_id, ...data});
    }
    if (!res.ok) return alert(res.message);
    UI.closeModal();
    await this.mount(this.member_id, this.mountEl);
  },

  async deleteRow(session_id) {
    if (!confirm('이 회차 로그를 삭제하시겠습니까?')) return;
    const res = await API.del(`/api/coaching_log.php?action=delete&id=${session_id}`);
    if (!res.ok) return alert(res.message);
    UI.closeModal();
    await this.mount(this.member_id, this.mountEl);
  },

  toggleAll(order_id, checked) {
    document.querySelectorAll(`.row-check[data-order="${order_id}"]`).forEach(cb => cb.checked = checked);
    this.updateBulkBar(order_id);
  },

  updateBulkBar(order_id) {
    const checked = document.querySelectorAll(`.row-check[data-order="${order_id}"]:checked`);
    const bar = document.getElementById(`bulk-${order_id}`);
    if (!bar) return;
    bar.style.display = checked.length > 0 ? 'flex' : 'none';
    bar.querySelector('.selected-count').textContent = `${checked.length}개 선택`;
  },

  async bulkComplete(order_id) {
    const ids = Array.from(document.querySelectorAll(`.row-check[data-order="${order_id}"]:checked`))
      .map(cb => parseInt(cb.closest('tr').dataset.id));
    if (!ids.length) return;
    const res = await API.post('/api/coaching_log.php?action=bulk_update', {
      ids, data: { completed_at: (new Date()).toISOString().slice(0,19).replace('T',' ') }
    });
    if (!res.ok) return alert(res.message);
    await this.mount(this.member_id, this.mountEl);
  },

  async bulkDelete(order_id) {
    const ids = Array.from(document.querySelectorAll(`.row-check[data-order="${order_id}"]:checked`))
      .map(cb => parseInt(cb.closest('tr').dataset.id));
    if (!ids.length || !confirm(`${ids.length}개 회차 로그를 삭제하시겠습니까?`)) return;
    for (const id of ids) {
      await API.del(`/api/coaching_log.php?action=delete&id=${id}`);
    }
    await this.mount(this.member_id, this.mountEl);
  },

  _pct(v) { return (v * 100).toFixed(1) + '%'; },
};
```

- [ ] **Step 2: Add `<script>` to admin/coach entries**

`public_html/admin/index.php` 와 `public_html/coach/index.php` 의 마지막 `<script>` 그룹에:

```html
<script src="/assets/js/coaching-chart.js?v=20260511"></script>
```

- [ ] **Step 3: Commit**

```bash
git add public_html/assets/js/coaching-chart.js public_html/admin/index.php public_html/coach/index.php
git commit -m "feat(pt): coaching-chart.js 공통 모듈 (회원 차트 코칭 영역)"
```

---

## Task 11: member-chart.js 확장 (admin + coach)

**Files:**
- Modify: `public_html/admin/js/pages/member-chart.js`
- Modify: `public_html/coach/js/pages/member-chart.js`

기존 회원 차트 페이지에 **신규 탭 "코칭 로그"** 추가, 클릭 시 `CoachingChart.mount(member_id, mountEl)` 호출.

- [ ] **Step 1: admin/member-chart.js 의 탭 영역 찾고 신규 탭 + 핸들러 추가**

`public_html/admin/js/pages/member-chart.js` 내 탭 정의부에 "코칭 로그" 추가:

```javascript
// 기존: PT이력 / 메모 / 테스트결과 / 변경로그
// 추가: 코칭 로그
// 탭 HTML 에 한 줄 추가:
//   <button class="tab-btn" onclick="App.pages['member-chart'].loadCoaching()">코칭 로그</button>
// 핸들러 추가:
loadCoaching() {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
  const mount = document.getElementById('tabContent');
  CoachingChart.mount(this.memberId, mount);
},
```

(정확한 라인 번호는 기존 파일 read 후 결정. tabs html 의 마지막 `</div>` 직전에 button 추가. 핸들러는 다른 `loadXxx` 옆에.)

- [ ] **Step 2: coach/member-chart.js 도 동일하게 수정**

`public_html/coach/js/pages/member-chart.js` (이미 PT이력/메모/테스트결과/변경로그 탭 존재) 에 동일 패턴으로 추가. App → CoachApp.

- [ ] **Step 3: DEV smoke**

브라우저에서 admin 회원 차트 → "코칭 로그" 탭 클릭 → 메트릭 카드 + 주문별 회차 표 렌더링. 회차 row 편집 modal 동작. Coach 도 자기 회원 차트에서 같은 동작.

- [ ] **Step 4: Commit**

```bash
git add public_html/admin/js/pages/member-chart.js public_html/coach/js/pages/member-chart.js
git commit -m "feat(pt): member-chart 회원 차트에 코칭 로그 탭 추가 (admin + coach)"
```

---

## Task 11b: 회원 list 진도율/개선율 컬럼 (admin + coach 메인)

**Files:**
- Modify: `public_html/admin/js/pages/members.js`
- Modify: `public_html/coach/js/pages/my-members.js`
- Modify: `public_html/api/members.php` 또는 `/api/coach_self.php` (회원 list 응답에 메트릭 포함)

spec §5.3·§5.4: 코치/관리자 메인 list 에 "진도율 / 개선율" 컬럼 추가. 회원 단위 합산 메트릭을 list API 응답에 포함.

- [ ] **Step 1: list API 확장 — 회원별 메트릭 join**

`public_html/api/members.php` (admin) 와 `public_html/api/coach_self.php` (coach) 의 list action 에서, 각 회원 row 에 `CoachingMetrics::for_member($member_id)` 결과를 합치는 컬럼 추가. SELECT 후 PHP 루프에서 헬퍼 호출:

```php
require_once __DIR__ . '/../includes/coaching_metrics.php';
foreach ($rows as &$r) {
    $r['metrics'] = CoachingMetrics::for_member((int)$r['id']);
}
```

성능: 회원 수가 클 때 N+1 쿼리. 1차 구현은 인덱스에만 의지. PROD 에서 느려지면 단일 GROUP BY 로 최적화.

- [ ] **Step 2: members.js 테이블 컬럼 추가**

진도율 / 개선율 컬럼 표시:

```javascript
<td>${(r.metrics.progress_rate * 100).toFixed(0)}%</td>
<td>${(r.metrics.improvement_rate * 100).toFixed(0)}%</td>
```

(thead 도 동일 추가)

- [ ] **Step 3: my-members.js 동일 적용**

- [ ] **Step 4: DEV smoke**

브라우저에서 `/admin/#members` 와 `/coach/#my-members` 양쪽에서 새 컬럼 표시 + 회원 클릭 → 차트 페이지의 메트릭 카드와 값이 일치하는지 확인.

- [ ] **Step 5: Commit**

```bash
git add public_html/admin/js/pages/members.js public_html/coach/js/pages/my-members.js public_html/api/members.php public_html/api/coach_self.php
git commit -m "feat(pt): 회원 list 에 진도율/개선율 컬럼 추가 (admin + coach 메인)"
```

---

## Task 12: 마이그 헬퍼 + 테스트

**Files:**
- Create: `public_html/includes/coaching_log_migration.php`
- Create: `tests/coaching_log_migration_test.php`

CSV 파싱 + 정규화 + 매칭 검증 + 본 import.

- [ ] **Step 1: Write failing test**

`tests/coaching_log_migration_test.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../public_html/includes/coaching_log_migration.php';

t_section('CoachingLogMigration::normalize_improved');
t_assert_eq(1, CoachingLogMigration::normalize_improved('TRUE'), 'TRUE');
t_assert_eq(1, CoachingLogMigration::normalize_improved('true'), 'true');
t_assert_eq(1, CoachingLogMigration::normalize_improved('1'), '1');
t_assert_eq(1, CoachingLogMigration::normalize_improved('Y'), 'Y');
t_assert_eq(1, CoachingLogMigration::normalize_improved('✓'), 'check mark');
t_assert_eq(0, CoachingLogMigration::normalize_improved(''), 'empty');
t_assert_eq(0, CoachingLogMigration::normalize_improved('FALSE'), 'FALSE');

t_section('CoachingLogMigration::normalize_date');
t_assert_eq('2026-05-11', CoachingLogMigration::normalize_date('2026-05-11'), 'iso');
t_assert_eq('2026-05-11', CoachingLogMigration::normalize_date('2026/05/11'), 'slashes');
t_assert_eq('2026-05-11', CoachingLogMigration::normalize_date('5/11/2026'), 'US');
t_assert_eq(null, CoachingLogMigration::normalize_date(''), 'empty');
t_assert_eq(null, CoachingLogMigration::normalize_date('bogus'), 'bogus');

t_section('CoachingLogMigration::stage_csv (preview)');

$pdo = getDb();
$pdo->beginTransaction();

// fixture: member + order + calendar
$pdo->exec("INSERT INTO members (soritune_id, name) VALUES ('MIG_X','Mig User')");
$mid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO orders (member_id, product_name, product_type, start_date, end_date, status, cohort_month)
            VALUES ($mid,'MIG_PROD','count','2099-10-01','2099-10-31','진행중','2099-10')");
$oid = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO coaching_calendars (cohort_month, product_name, session_count, created_by)
            VALUES ('2099-10','MIG_PROD',3,1)");
$cal = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO coaching_calendar_dates (calendar_id, session_number, scheduled_date)
            VALUES ($cal,1,'2099-10-01'),($cal,2,'2099-10-02'),($cal,3,'2099-10-03')");

$rows = [
    ['soritune_id'=>'MIG_X','cohort_month'=>'2099-10','product_name'=>'MIG_PROD',
     'session_number'=>1,'scheduled_date'=>'2099-10-01','completed_at'=>'2099-10-01 10:00',
     'progress'=>'p1','issue'=>'i1','solution'=>'s1','improved'=>'TRUE'],
    ['soritune_id'=>'GHOST','cohort_month'=>'2099-10','product_name'=>'MIG_PROD',
     'session_number'=>2,'scheduled_date'=>'2099-10-02','completed_at'=>'','progress'=>'','issue'=>'','solution'=>'','improved'=>''],
];
$batch_id = CoachingLogMigration::stage_csv($rows, 'TEST_BATCH_1');
t_assert_eq('TEST_BATCH_1', $batch_id, 'batch id returned');

$counts = $pdo->query("SELECT match_status, COUNT(*) AS n FROM coaching_log_migration_preview
                       WHERE batch_id='TEST_BATCH_1' GROUP BY match_status")->fetchAll(PDO::FETCH_KEY_PAIR);
t_assert_eq(1, (int)($counts['matched']??0),           'one matched');
t_assert_eq(1, (int)($counts['member_not_found']??0),  'one member_not_found');

t_section('CoachingLogMigration::run_import');

$result = CoachingLogMigration::run_import('TEST_BATCH_1', 1);
t_assert_eq(1, $result['imported'], 'one imported');
t_assert_eq(0, $result['errors'], 'zero errors');

$sess = $pdo->query("SELECT progress, improved FROM order_sessions WHERE order_id=$oid AND session_number=1")
            ->fetch(PDO::FETCH_ASSOC);
t_assert_eq('p1', $sess['progress'], 'progress imported');
t_assert_eq(1, (int)$sess['improved'], 'improved imported');

// idempotent: 한 번 더 실행해도 update 만
$result2 = CoachingLogMigration::run_import('TEST_BATCH_1', 1);
t_assert_eq(1, $result2['imported'], 'idempotent — same count');

$pdo->rollBack();
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement helper**

`public_html/includes/coaching_log_migration.php`:

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coaching_log.php';

class CoachingLogMigration {

    public static function normalize_improved(string $raw): int {
        $r = trim(mb_strtolower($raw));
        if (in_array($r, ['1','true','y','yes','✓','o','ok','t'], true)) return 1;
        return 0;
    }

    public static function normalize_date(string $raw): ?string {
        $r = trim($raw);
        if ($r === '') return null;
        foreach (['Y-m-d','Y/m/d','m/d/Y','Y.m.d','d.m.Y'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $r);
            if ($dt && $dt->format($fmt) === $r) return $dt->format('Y-m-d');
        }
        // last resort: strtotime
        $t = strtotime($r);
        return $t ? date('Y-m-d', $t) : null;
    }

    public static function normalize_datetime(string $raw): ?string {
        $r = trim($raw);
        if ($r === '') return null;
        $t = strtotime($r);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /**
     * CSV row 배열을 받아 staging 테이블에 매칭 결과와 함께 저장.
     */
    public static function stage_csv(array $rows, string $batch_id): string {
        $pdo = getDb();
        $stmt = $pdo->prepare("INSERT INTO coaching_log_migration_preview
            (batch_id, source_row, soritune_id, cohort_month, product_name, session_number,
             scheduled_date, completed_at, progress, issue, solution, improved,
             sheet_progress_rate, sheet_improvement_rate,
             match_status, target_order_id, error_detail)
            VALUES (:b,:sr,:sid,:cm,:pn,:sn,:sd,:ca,:p,:i,:s,:imp,:spr,:sir,:ms,:toi,:ed)");

        foreach ($rows as $idx => $r) {
            $imp = self::normalize_improved((string)($r['improved'] ?? ''));
            $sd  = self::normalize_date((string)($r['scheduled_date'] ?? ''));
            $ca  = self::normalize_datetime((string)($r['completed_at'] ?? ''));
            $match = self::_match($r, $pdo);
            $stmt->execute([
                ':b' => $batch_id, ':sr' => $idx + 1,
                ':sid' => $r['soritune_id'] ?? null,
                ':cm'  => $r['cohort_month'] ?? null,
                ':pn'  => $r['product_name'] ?? null,
                ':sn'  => (int)($r['session_number'] ?? 0),
                ':sd'  => $sd, ':ca' => $ca,
                ':p'   => $r['progress'] ?? null,
                ':i'   => $r['issue']    ?? null,
                ':s'   => $r['solution'] ?? null,
                ':imp' => $imp,
                ':spr' => isset($r['sheet_progress_rate']) && $r['sheet_progress_rate'] !== ''
                          ? (float)$r['sheet_progress_rate'] : null,
                ':sir' => isset($r['sheet_improvement_rate']) && $r['sheet_improvement_rate'] !== ''
                          ? (float)$r['sheet_improvement_rate'] : null,
                ':ms'  => $match['status'],
                ':toi' => $match['order_id'],
                ':ed'  => $match['error'],
            ]);
        }
        return $batch_id;
    }

    /**
     * 한 행 매칭. (member, order, calendar 존재 확인.)
     */
    private static function _match(array $r, PDO $pdo): array {
        $sid = $r['soritune_id'] ?? '';
        $cm  = $r['cohort_month'] ?? '';
        $pn  = $r['product_name'] ?? '';
        if (!$sid || !$cm || !$pn) return ['status'=>'date_invalid','order_id'=>null,'error'=>'필수 키 누락'];

        $mid = (int)$pdo->query("SELECT id FROM members WHERE soritune_id=" . $pdo->quote($sid))->fetchColumn();
        if (!$mid) return ['status'=>'member_not_found','order_id'=>null,'error'=>"soritune_id: $sid"];

        $oid = (int)$pdo->query("SELECT id FROM orders WHERE member_id=$mid
            AND cohort_month=" . $pdo->quote($cm) . "
            AND product_name=" . $pdo->quote($pn) . " LIMIT 1")->fetchColumn();
        if (!$oid) return ['status'=>'order_not_found','order_id'=>null,'error'=>"$cm / $pn"];

        $cal_exists = $pdo->query("SELECT 1 FROM coaching_calendars
            WHERE cohort_month=" . $pdo->quote($cm) . "
            AND product_name=" . $pdo->quote($pn))->fetchColumn();
        if (!$cal_exists) return ['status'=>'calendar_missing','order_id'=>$oid,'error'=>'매칭 캘린더 미생성'];

        return ['status'=>'matched','order_id'=>$oid,'error'=>null];
    }

    /**
     * 본 import — staging 의 matched row 를 order_sessions 에 UPSERT.
     * chunk 500 단위 트랜잭션.
     */
    public static function run_import(string $batch_id, int $actor_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT * FROM coaching_log_migration_preview
                               WHERE batch_id=:b AND match_status='matched'");
        $stmt->execute([':b' => $batch_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $imported = 0; $errors = 0;
        $chunks = array_chunk($rows, 500);
        foreach ($chunks as $chunk) {
            $pdo->beginTransaction();
            try {
                foreach ($chunk as $r) {
                    CoachingLog::create_for_order((int)$r['target_order_id'], [
                        'session_number' => (int)$r['session_number'],
                        'completed_at'   => $r['completed_at'],
                        'progress'       => $r['progress'],
                        'issue'          => $r['issue'],
                        'solution'       => $r['solution'],
                        'improved'       => (int)$r['improved'],
                    ], $actor_id);

                    $pdo->prepare("UPDATE coaching_log_migration_preview SET match_status='imported' WHERE id=:id")
                        ->execute([':id' => $r['id']]);

                    self::log_migration_row($batch_id, (int)$r['source_row'], (int)$r['target_order_id'], 'success', null);
                    $imported++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors += count($chunk);
                self::log_migration_row($batch_id, 0, 0, 'error', $e->getMessage());
            }
        }
        return ['imported' => $imported, 'errors' => $errors];
    }

    private static function log_migration_row(string $batch, int $src_row, int $target_id, string $status, ?string $msg): void {
        $pdo = getDb();
        $pdo->prepare("INSERT INTO migration_logs
            (batch_id, source_type, source_row, target_table, target_id, status, message)
            VALUES (:b,'coaching_log',:sr,'order_sessions',:tid,:st,:ms)")
        ->execute([':b'=>$batch, ':sr'=>$src_row, ':tid'=>$target_id, ':st'=>$status, ':ms'=>$msg]);
    }
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
php tests/coaching_log_migration_test.php
```

- [ ] **Step 5: Commit**

```bash
git add public_html/includes/coaching_log_migration.php tests/coaching_log_migration_test.php
git commit -m "feat(pt): CoachingLogMigration 헬퍼 (정규화 + 매칭 + 본 import)"
```

---

## Task 13: /api/coaching_log_migration.php

**Files:**
- Create: `public_html/api/coaching_log_migration.php`

admin only. POST upload (CSV → stage), GET preview (batch summary + list), POST import.

- [ ] **Step 1: Implement endpoint**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coaching_log_migration.php';

requireAdmin();
$user = getCurrentUser();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'upload': {
            if (!isset($_FILES['file']) || $_FILES['file']['error']) jsonError('파일 업로드 실패', 400);
            $f = $_FILES['file'];
            if ($f['size'] > 5 * 1024 * 1024) jsonError('파일이 5MB 를 초과합니다', 400);

            $fp = fopen($f['tmp_name'], 'r');
            if (!$fp) jsonError('파일 열기 실패', 500);
            $headers = fgetcsv($fp);
            if (!$headers) jsonError('헤더 없음', 400);
            $headers = array_map(fn($h) => trim($h, "\xEF\xBB\xBF "), $headers);

            $rows = [];
            while (($row = fgetcsv($fp)) !== false) {
                $rows[] = array_combine($headers, array_pad($row, count($headers), ''));
            }
            fclose($fp);

            $batch_id = 'COACH_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6);
            CoachingLogMigration::stage_csv($rows, $batch_id);
            jsonSuccess(['batch_id' => $batch_id, 'staged' => count($rows)]);
        }
        case 'preview': {
            $batch = $_GET['batch_id'] ?? '';
            $pdo = getDb();
            $summary = $pdo->prepare("SELECT match_status, COUNT(*) AS n
                FROM coaching_log_migration_preview WHERE batch_id=:b GROUP BY match_status");
            $summary->execute([':b' => $batch]);
            $rows = $pdo->prepare("SELECT * FROM coaching_log_migration_preview
                WHERE batch_id=:b ORDER BY id LIMIT 500");
            $rows->execute([':b' => $batch]);
            jsonSuccess([
                'summary' => $summary->fetchAll(PDO::FETCH_KEY_PAIR),
                'rows'    => $rows->fetchAll(PDO::FETCH_ASSOC),
            ]);
        }
        case 'import': {
            $in = getJsonInput();
            $batch = $in['batch_id'] ?? '';
            if (!$batch) jsonError('batch_id 필요', 400);
            $result = CoachingLogMigration::run_import($batch, (int)$user['id']);
            jsonSuccess($result);
        }
        default:
            jsonError('unknown action', 400);
    }
} catch (Throwable $e) {
    error_log('coaching_log_migration API: ' . $e->getMessage());
    jsonError('서버 오류: ' . $e->getMessage(), 500);
}
```

- [ ] **Step 2: Commit**

```bash
git add public_html/api/coaching_log_migration.php
git commit -m "feat(pt): /api/coaching_log_migration.php — upload + preview + import"
```

---

## Task 14: 마이그 페이지 UI

**Files:**
- Create: `public_html/admin/js/pages/coaching-log-migration.js`
- Modify: `public_html/admin/index.php` — 사이드바 + script

- [ ] **Step 1: Add sidebar + script**

`public_html/admin/index.php`:

```html
<a href="#coaching-log-migration" data-page="coaching-log-migration">코칭 로그 마이그</a>
...
<script src="/admin/js/pages/coaching-log-migration.js?v=20260511"></script>
```

- [ ] **Step 2: Create page**

```javascript
App.registerPage('coaching-log-migration', {
  batch_id: null,
  preview: null,

  render() {
    document.getElementById('pageContent').innerHTML = `
      <h2>코칭 로그 마이그레이션 (시트 → DB)</h2>
      <div class="card">
        <div class="form-help">
          CSV 컬럼: soritune_id, cohort_month, product_name, session_number,
          scheduled_date, completed_at, progress, issue, solution, improved
          <br>(선택) sheet_progress_rate, sheet_improvement_rate
        </div>
        <input type="file" id="mig-file" accept=".csv">
        <button class="btn btn-primary" onclick="App.pages['coaching-log-migration'].upload()">업로드 (dry-run)</button>
      </div>
      <div id="mig-preview-area"></div>
    `;
  },

  async upload() {
    const file = document.getElementById('mig-file').files[0];
    if (!file) return alert('파일을 선택하세요');
    const fd = new FormData();
    fd.append('file', file);
    const res = await fetch('/api/coaching_log_migration.php?action=upload', {method:'POST', body: fd});
    const data = await res.json();
    if (!data.ok) return alert(data.message);
    this.batch_id = data.data.batch_id;
    await this.loadPreview();
  },

  async loadPreview() {
    const res = await API.get(`/api/coaching_log_migration.php?action=preview&batch_id=${this.batch_id}`);
    if (!res.ok) return alert(res.message);
    this.preview = res.data;
    this.renderPreview();
  },

  renderPreview() {
    const s = this.preview.summary;
    const total = Object.values(s).reduce((a,b)=>a+parseInt(b), 0);
    const summaryHtml = Object.entries(s).map(([k,v]) => `<span class="badge status-${k}">${k}: ${v}</span>`).join(' ');
    const rowsHtml = this.preview.rows.map(r => `
      <tr class="status-${r.match_status}">
        <td>${r.source_row}</td>
        <td>${UI.esc(r.soritune_id||'')}</td>
        <td>${UI.esc(r.cohort_month||'')}</td>
        <td>${UI.esc(r.product_name||'')}</td>
        <td>${r.session_number}</td>
        <td>${r.match_status}</td>
        <td>${UI.esc(r.error_detail||'')}</td>
      </tr>`).join('');
    document.getElementById('mig-preview-area').innerHTML = `
      <div class="card">
        <h3>Preview (batch: ${this.batch_id})</h3>
        <div>${summaryHtml} / 총 ${total}건</div>
        <button class="btn btn-primary" onclick="App.pages['coaching-log-migration'].runImport()"
          ${(s.matched||0) > 0 ? '' : 'disabled'}>matched ${s.matched||0}건 본 import 실행</button>
      </div>
      <table class="data-table">
        <thead><tr><th>#</th><th>소리튠ID</th><th>매칭월</th><th>상품</th><th>회차</th><th>상태</th><th>오류</th></tr></thead>
        <tbody>${rowsHtml}</tbody>
      </table>
    `;
  },

  async runImport() {
    if (!confirm(`matched ${this.preview.summary.matched}건을 import 합니다. 진행할까요?`)) return;
    const res = await API.post('/api/coaching_log_migration.php?action=import', {batch_id: this.batch_id});
    if (!res.ok) return alert(res.message);
    alert(`import ${res.data.imported} / errors ${res.data.errors}`);
    await this.loadPreview();
  },
});
```

- [ ] **Step 3: Commit**

```bash
git add public_html/admin/index.php public_html/admin/js/pages/coaching-log-migration.js
git commit -m "feat(pt): admin 코칭 로그 마이그레이션 페이지 (CSV → dry-run → import)"
```

---

## Task 15: 통합 회귀 + DEV smoke

**Files:** N/A (검증만)

- [ ] **Step 1: 모든 단위 테스트 재실행**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -30
```

Expected: 신규 +N 테스트 추가, 기존 회귀 0.

- [ ] **Step 2: DEV smoke 시나리오**

브라우저에서:
1. `/admin/#coaching-calendar` → 신규 캘린더 생성 (테스트 cohort, 5회) → 자동 패턴 후보 → 저장
2. `/admin/#members` → 회원 1명 클릭 → 회원 차트 → "코칭 로그" 탭 → 메트릭 카드 + 캘린더 매칭된 주문이면 회차 list 표시
3. 회차 row "편집" → 진도/문제/솔루션 입력 + 완료 토글 → 저장 → list 갱신, 메트릭 갱신
4. 여러 row 체크박스 선택 → "일괄 완료처리" → 갱신
5. `/coach/` 로그인 → 자기 담당 회원 → 동일 동작 확인
6. `/coach/` 로 다른 코치의 회원 차트 URL 직접 입력 시도 → 404
7. `/admin/#coaching-log-migration` → 샘플 CSV 업로드 → preview 매칭 결과 → matched 본 import

- [ ] **Step 3: 시트 vs DB 일치 검증 (옵션)**

사용자에게 시트의 회원 1~2명 + 그 회원의 진도율/개선율 값 제공 요청 → CSV import 후 PHP 헬퍼 결과와 비교.

- [ ] **Step 4: change_logs 확인**

```bash
mysql -u SORITUNECOM_DEV_PT -pVa4/7hdfj7oweLwMah3bHzXR SORITUNECOM_DEV_PT \
  -e "SELECT target_type, action, COUNT(*) FROM change_logs
      WHERE target_type IN ('coaching_calendar','order_session') GROUP BY target_type, action"
```

Expected: 캘린더 생성/수정 + 세션 생성/수정 모두 기록.

- [ ] **Step 5: push dev**

```bash
cd /root/pt-dev && git push origin dev
```

---

## Task 16: PROD 배포 게이트 (사용자 명시 요청 후에만)

⛔ **이 task 는 사용자가 "운영 반영해줘" 등 명시 요청한 경우에만 수행.** DEV 검증 + 사용자 확인 전엔 PROD 손대지 않음.

- [ ] **Step 1: dev → main merge (pt-dev 에서)**

```bash
cd /root/pt-dev && git checkout main && git merge dev && git push origin main && git checkout dev
```

- [ ] **Step 2: pt-prod pull**

```bash
cd /root/pt-prod && git pull origin main
```

- [ ] **Step 3: PROD 마이그**

```bash
cd /root/pt-prod && source .db_credentials && \
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  < migrations/20260511_add_coaching_chart.sql
```

- [ ] **Step 4: PROD smoke**

`https://pt.soritune.com` 어드민/코치 양쪽에서 Task 15 의 smoke 시나리오 반복.

- [ ] **Step 5: PROD invariants**

```bash
mysql -u <PROD_USER> -p<PROD_PASS> SORITUNECOM_PT -e "
  SELECT 'tables', COUNT(*) FROM information_schema.tables WHERE table_schema='SORITUNECOM_PT' AND table_name IN ('coaching_calendars','coaching_calendar_dates','coaching_log_migration_preview');
  SELECT 'order_sessions new columns', COUNT(*) FROM information_schema.columns WHERE table_schema='SORITUNECOM_PT' AND table_name='order_sessions' AND column_name IN ('calendar_id','progress','issue','solution','improved');
"
```

Expected: 3 신규 테이블 + 5 신규 컬럼.

---

## 향후 확장 (다음 spec)

- **A1/A2 단계별 승급** — `member_stage_targets` + D-Day/초과일수 자동 계산 + 코치 알림
- **`/me/` 회원 본인 노출** — 진도율 카드 + 캘린더만 (또는 전체)
- **알림톡 트리거** — 진도율 부진 / D-3 리마인드 (notify_* 인프라 활용)
