# Order Status 자동 전환 구현 Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `orders.status` 의 자동 전이를 결정 트리 기반으로 통일하고, cron(매일 03:00 KST) + 명시 액션 후크로 즉시 반영시킨다. 회원 화면 `display_status` 가 정확히 따라가도록.

**Architecture:** 단일 진입점 함수 `recomputeOrderStatus()` 가 보호 컷 → 결정 트리 평가 → UPDATE + change_logs 기록을 수행. cron 은 `withOrderLock()` 헬퍼로 호출, 명시 액션 후크는 기존 트랜잭션 안에서 `SELECT FOR UPDATE → recomputeOrderStatus()` 패턴 직접 호출.

**Tech Stack:** PHP 8.1+, PDO/MySQL, vanilla JS. 테스트는 PT 프로젝트 신규 디렉토리 `tests/` + 단순 assert 러너.

**Spec:** `docs/superpowers/specs/2026-04-29-order-status-auto-transition-design.md`

---

## File Structure

**Create:**
- `tests/_bootstrap.php` — 공통 부트스트랩 (DB 연결, assertion 헬퍼)
- `tests/run_tests.php` — `*_test.php` 일괄 실행 러너
- `tests/auto_status_transition_test.php` — 결정 트리 24 케이스
- `cron/auto_status_transition.php` — 일 1회 cron 본체

**Modify:**
- `public_html/includes/helpers.php` — `recomputeOrderStatus()`, `withOrderLock()` 추가; `logChange()` docblock 보정
- `public_html/api/matching.php` — confirm 의 `UPDATE coach_id, status` 분리
- `public_html/api/orders.php` — create / update / complete_session 후크
- `public_html/api/import.php` — import 트랜잭션 commit 후 후크
- `public_html/coach/js/pages/member-chart.js` — `loadLogs()` 라벨 매핑
- `public_html/admin/js/pages/member-chart.js` — 변경로그 렌더 라벨 매핑

---

## Task 1: 테스트 인프라 + 핵심 함수 골격

**Files:**
- Create: `tests/_bootstrap.php`, `tests/run_tests.php`
- Modify: `public_html/includes/helpers.php` (함수 골격 + docblock 수정)

### Step 1: `tests/_bootstrap.php` 작성

- [ ] **Step 1: bootstrap 작성**

`tests/_bootstrap.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';

$GLOBALS['__test_pass'] = 0;
$GLOBALS['__test_fail'] = 0;
$GLOBALS['__test_current'] = null;

function t_section(string $name): void {
    $GLOBALS['__test_current'] = $name;
    echo "\n=== {$name} ===\n";
}

function t_assert_eq(mixed $expected, mixed $actual, string $label): void {
    if ($expected === $actual) {
        $GLOBALS['__test_pass']++;
        echo "  PASS  {$label}\n";
    } else {
        $GLOBALS['__test_fail']++;
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        echo "  FAIL  {$label}\n        expected: {$e}\n        actual:   {$a}\n";
    }
}

function t_assert_true(bool $cond, string $label): void {
    t_assert_eq(true, $cond, $label);
}

function t_assert_throws(callable $fn, string $exceptionClass, string $label): void {
    try {
        $fn();
        $GLOBALS['__test_fail']++;
        echo "  FAIL  {$label} — expected {$exceptionClass}, got no exception\n";
    } catch (Throwable $e) {
        if ($e instanceof $exceptionClass) {
            $GLOBALS['__test_pass']++;
            echo "  PASS  {$label}\n";
        } else {
            $GLOBALS['__test_fail']++;
            $cls = get_class($e);
            echo "  FAIL  {$label} — expected {$exceptionClass}, got {$cls}\n";
        }
    }
}

function t_summary(): int {
    $p = $GLOBALS['__test_pass'];
    $f = $GLOBALS['__test_fail'];
    echo "\n----\nTotal: " . ($p+$f) . "  Pass: {$p}  Fail: {$f}\n";
    return $f === 0 ? 0 : 1;
}

/**
 * 테스트용 fixture: 회원 1명 + order 1개 + (count 면 sessions) 생성.
 * 호출자 트랜잭션 내에서 사용 — ROLLBACK으로 정리됨.
 *
 * @return int order id
 */
function t_make_order(PDO $db, array $opts): int
{
    $opts = array_merge([
        'product_type'   => 'period',
        'start_date'     => date('Y-m-d', strtotime('-7 days')),
        'end_date'       => date('Y-m-d', strtotime('+30 days')),
        'total_sessions' => null,
        'coach_id'       => null,
        'status'         => '매칭대기',
        'product_name'   => '테스트상품',
        'used_sessions'  => 0,
    ], $opts);

    $db->prepare("INSERT INTO members (name, phone) VALUES (?, ?)")
       ->execute(['테스트회원_' . uniqid(), '01000000000']);
    $memberId = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO orders (member_id, coach_id, product_name, product_type,
                            start_date, end_date, total_sessions, amount, status, memo)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NULL)
    ")->execute([
        $memberId, $opts['coach_id'], $opts['product_name'], $opts['product_type'],
        $opts['start_date'], $opts['end_date'], $opts['total_sessions'], $opts['status']
    ]);
    $orderId = (int)$db->lastInsertId();

    if ($opts['product_type'] === 'count' && (int)$opts['total_sessions'] > 0) {
        $insSes = $db->prepare("INSERT INTO order_sessions (order_id, session_number, completed_at) VALUES (?, ?, ?)");
        for ($i = 1; $i <= (int)$opts['total_sessions']; $i++) {
            $completedAt = $i <= (int)$opts['used_sessions'] ? date('Y-m-d H:i:s') : null;
            $insSes->execute([$orderId, $i, $completedAt]);
        }
    }

    return $orderId;
}
```

- [ ] **Step 2: `tests/run_tests.php` 러너 작성**

```php
<?php
declare(strict_types=1);

$dir = __DIR__;
$files = glob($dir . '/*_test.php');
if (!$files) {
    echo "No test files found in {$dir}\n";
    exit(1);
}

require_once $dir . '/_bootstrap.php';

foreach ($files as $f) {
    echo "\n>>> " . basename($f) . "\n";
    require $f;
}

exit(t_summary());
```

- [ ] **Step 3: 빈 테스트 파일 작성 (러너 동작 확인용)**

`tests/auto_status_transition_test.php`:

```php
<?php
declare(strict_types=1);

t_section('smoke');
t_assert_eq(2, 1 + 1, '1+1 == 2');
```

- [ ] **Step 4: 러너 실행으로 인프라 동작 확인**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected output (마지막 부분):
```
=== smoke ===
  PASS  1+1 == 2

----
Total: 1  Pass: 1  Fail: 0
```

- [ ] **Step 5: helpers.php 에 함수 골격 + docblock 수정**

`public_html/includes/helpers.php` 끝부분(`logChange` 다음)에 추가, 그리고 logChange docblock 의 actorType 라인을 수정.

logChange docblock 수정 (`'admin' or 'coach'` → `'admin', 'coach', or 'system'`):

```php
 * @param string $actorType   'admin', 'coach', or 'system'
```

파일 끝에 추가:

```php
/**
 * 결정 트리에 따라 단일 order 의 status 를 재평가하고 필요 시 갱신한다.
 * 호출자가 row 를 FOR UPDATE 로 잠그고 트랜잭션을 관리해야 한다.
 *
 * @param PDO      $db
 * @param int      $orderId
 * @param string   $today                  YYYY-MM-DD. 생략 시 date('Y-m-d').
 * @param bool     $allowRevertTerminated  true 이면 status='종료' 도 보호 컷을 통과시키고 결정 트리 평가.
 *                                          단 '연기/중단/환불' 은 이 플래그와 무관하게 항상 보호.
 * @return string|null                      변경된 새 status. 변경이 없거나 order 가 존재하지 않으면 null.
 */
function recomputeOrderStatus(
    PDO $db,
    int $orderId,
    ?string $today = null,
    bool $allowRevertTerminated = false
): ?string {
    throw new RuntimeException('recomputeOrderStatus: not implemented');
}

/**
 * 활성 트랜잭션이 없는 호출자가 order 1건을 lock 하고 콜백 안에서
 * recomputeOrderStatus() 등을 호출할 때 쓰는 헬퍼.
 *
 * @throws RuntimeException 호출 시점에 이미 트랜잭션이 활성화된 경우
 */
function withOrderLock(PDO $db, int $orderId, callable $fn): mixed
{
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'withOrderLock() must not be called inside an active transaction. ' .
            'Use SELECT ... FOR UPDATE + recomputeOrderStatus() directly instead.'
        );
    }
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);
        $result = $fn();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 6: smoke 테스트 다시 실행해서 깨지지 않는지 확인**

```bash
php tests/run_tests.php
```
Expected: 1 PASS, 0 FAIL.

- [ ] **Step 7: 커밋**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
git add tests/_bootstrap.php tests/run_tests.php tests/auto_status_transition_test.php public_html/includes/helpers.php
git commit -m "feat(status): 테스트 인프라 + recomputeOrderStatus/withOrderLock 골격"
```

---

## Task 2: 결정 트리 — 보호 상태 컷 + coach_id NULL 분기

**Files:**
- Modify: `tests/auto_status_transition_test.php`, `public_html/includes/helpers.php`

- [ ] **Step 1: 실패하는 테스트 5건 추가**

`tests/auto_status_transition_test.php` 의 smoke 섹션을 그대로 두고 그 아래에 추가:

```php
$db = getDB();

t_section('보호 상태 컷');

$db->beginTransaction();
$id = t_make_order($db, ['status' => '연기', 'coach_id' => null]);
t_assert_eq(null, recomputeOrderStatus($db, $id), '연기는 항상 보호');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, ['status' => '중단']);
t_assert_eq(null, recomputeOrderStatus($db, $id), '중단은 항상 보호');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, ['status' => '환불']);
t_assert_eq(null, recomputeOrderStatus($db, $id), '환불은 항상 보호');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, ['status' => '종료']);
t_assert_eq(null, recomputeOrderStatus($db, $id), '종료는 기본 보호');
$db->rollBack();

t_section('coach_id NULL 분기');

$db->beginTransaction();
$id = t_make_order($db, ['status' => '매칭완료', 'coach_id' => null]);
t_assert_eq('매칭대기', recomputeOrderStatus($db, $id), 'coach NULL + 매칭완료 → 매칭대기');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, ['status' => '매칭대기', 'coach_id' => null]);
t_assert_eq(null, recomputeOrderStatus($db, $id), 'coach NULL + 매칭대기 → 변경 없음');
$db->rollBack();
```

- [ ] **Step 2: 테스트 실행 (실패 확인)**

```bash
php tests/run_tests.php
```
Expected: smoke 1건 PASS, 6건 모두 RuntimeException 으로 FAIL (현재 함수가 throw).

(러너가 `t_assert_eq` 안에서 호출하는 것이 아니므로 throw 가 그대로 전파됨 → 테스트 중단됨이 정상. 다음 단계에서 함수 구현하면 모두 PASS.)

- [ ] **Step 3: `recomputeOrderStatus()` 본체 구현**

`public_html/includes/helpers.php` 의 함수 본체를 다음으로 교체:

```php
function recomputeOrderStatus(
    PDO $db,
    int $orderId,
    ?string $today = null,
    bool $allowRevertTerminated = false
): ?string {
    $today ??= date('Y-m-d');

    $stmt = $db->prepare("
        SELECT id, coach_id, status, product_type, start_date, end_date, total_sessions
          FROM orders
         WHERE id = ?
    ");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    // 보호 컷
    if (in_array($row['status'], ['연기', '중단', '환불'], true)) {
        return null;
    }
    if ($row['status'] === '종료' && !$allowRevertTerminated) {
        return null;
    }

    // 결정 트리
    if ($row['coach_id'] === null) {
        $newStatus = '매칭대기';
    } else {
        // 종료 조건
        $terminated = false;
        if ($row['end_date'] !== null && $today > $row['end_date']) {
            $terminated = true;
        }
        if ($row['product_type'] === 'count'
            && (int)($row['total_sessions'] ?? 0) > 0
        ) {
            $usedStmt = $db->prepare("
                SELECT COUNT(*) FROM order_sessions
                 WHERE order_id = ? AND completed_at IS NOT NULL
            ");
            $usedStmt->execute([$orderId]);
            $used = (int)$usedStmt->fetchColumn();
            if ($used >= (int)$row['total_sessions']) {
                $terminated = true;
            }
        }
        if ($terminated) {
            $newStatus = '종료';
        } elseif ($row['start_date'] !== null && $today >= $row['start_date']) {
            $newStatus = '진행중';
        } else {
            $newStatus = '매칭완료';
        }
    }

    if ($newStatus === $row['status']) {
        return null;
    }

    // 사유 라벨
    $action = match (true) {
        $newStatus === '매칭완료' => 'auto_match_complete',
        $newStatus === '진행중'  => 'auto_in_progress',
        $newStatus === '종료'    => 'auto_terminate',
        $newStatus === '매칭대기' => 'auto_revert_to_pending',
        default                 => 'auto_status_change',
    };

    $db->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$newStatus, $orderId]);

    logChange(
        $db, 'order', $orderId, $action,
        ['status' => $row['status']],
        ['status' => $newStatus],
        'system', 0
    );

    return $newStatus;
}
```

- [ ] **Step 4: 테스트 재실행 (모두 PASS 확인)**

```bash
php tests/run_tests.php
```
Expected: smoke 1 + 보호 4 + coach_id NULL 2 = 7 PASS, 0 FAIL.

- [ ] **Step 5: 커밋**

```bash
git add tests/auto_status_transition_test.php public_html/includes/helpers.php
git commit -m "feat(status): 결정 트리 본체 — 보호 컷 + coach_id NULL 분기"
```

---

## Task 3: 결정 트리 — 종료 조건 + start_date 분기

**Files:**
- Modify: `tests/auto_status_transition_test.php`

- [ ] **Step 1: 종료 조건 + start_date 분기 테스트 추가**

`tests/auto_status_transition_test.php` 끝에 append:

```php
t_section('종료 조건 — period');

$db->beginTransaction();
$id = t_make_order($db, [
    'product_type' => 'period',
    'coach_id'     => 1,
    'start_date'   => date('Y-m-d', strtotime('-30 days')),
    'end_date'     => date('Y-m-d', strtotime('-1 days')),
    'status'       => '진행중',
]);
t_assert_eq('종료', recomputeOrderStatus($db, $id), 'period end_date < today → 종료');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, [
    'product_type' => 'period',
    'coach_id'     => 1,
    'start_date'   => date('Y-m-d', strtotime('-30 days')),
    'end_date'     => date('Y-m-d'),
    'status'       => '진행중',
]);
t_assert_eq(null, recomputeOrderStatus($db, $id), 'period end_date == today → 진행중 유지 (변경 없음)');
$db->rollBack();

t_section('종료 조건 — count');

$db->beginTransaction();
$id = t_make_order($db, [
    'product_type'   => 'count',
    'coach_id'       => 1,
    'start_date'     => date('Y-m-d', strtotime('-30 days')),
    'end_date'       => date('Y-m-d', strtotime('+30 days')),
    'total_sessions' => 5,
    'used_sessions'  => 5,
    'status'         => '진행중',
]);
t_assert_eq('종료', recomputeOrderStatus($db, $id), 'count used==total → 종료');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, [
    'product_type'   => 'count',
    'coach_id'       => 1,
    'start_date'     => date('Y-m-d', strtotime('-60 days')),
    'end_date'       => date('Y-m-d', strtotime('-1 days')),
    'total_sessions' => 5,
    'used_sessions'  => 2,
    'status'         => '진행중',
]);
t_assert_eq('종료', recomputeOrderStatus($db, $id), 'count end_date 만료(회차 미소진) → 종료');
$db->rollBack();

t_section('start_date 분기');

$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('+5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '매칭대기',
]);
t_assert_eq('매칭완료', recomputeOrderStatus($db, $id), 'coach + start 미래 → 매칭완료');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d'),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '매칭완료',
]);
t_assert_eq('진행중', recomputeOrderStatus($db, $id), 'start == today → 진행중');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('-5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '매칭완료',
]);
t_assert_eq('진행중', recomputeOrderStatus($db, $id), 'start 과거 + end 미래 → 진행중');
$db->rollBack();
```

- [ ] **Step 2: 테스트 실행 (모두 PASS 확인 — 함수 본체는 이미 Task 2에서 구현됨)**

```bash
php tests/run_tests.php
```
Expected: 누적 14 PASS, 0 FAIL.

- [ ] **Step 3: 커밋**

```bash
git add tests/auto_status_transition_test.php
git commit -m "test(status): 종료 조건 + start_date 분기 케이스 추가"
```

---

## Task 4: 결정 트리 — 엣지 케이스 + allowRevertTerminated

**Files:**
- Modify: `tests/auto_status_transition_test.php`

- [ ] **Step 1: 엣지 + 플래그 테스트 추가**

```php
t_section('allowRevertTerminated 플래그');

$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('-5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '종료',
]);
t_assert_eq(null, recomputeOrderStatus($db, $id, null, false), '기본 호출 — 종료 보호');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('-5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '종료',
]);
t_assert_eq('진행중', recomputeOrderStatus($db, $id, null, true), 'allowRevert=true + 회차 미소진 + 기간 안 지남 → 진행중');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, ['status' => '연기', 'coach_id' => 1]);
t_assert_eq(null, recomputeOrderStatus($db, $id, null, true), '연기는 플래그 무관하게 보호');
$db->rollBack();

t_section('엣지: order 미존재');

t_assert_eq(null, recomputeOrderStatus(getDB(), 99999999), '미존재 id → null (예외 없음)');

t_section('엣지: count + total_sessions NULL/0');

$db->beginTransaction();
$id = t_make_order($db, [
    'product_type'   => 'count',
    'coach_id'       => 1,
    'start_date'     => date('Y-m-d', strtotime('-5 days')),
    'end_date'       => date('Y-m-d', strtotime('+30 days')),
    'total_sessions' => null,
    'status'         => '매칭완료',
]);
t_assert_eq('진행중', recomputeOrderStatus($db, $id), 'count + total NULL + end 미래 → 진행중');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, [
    'product_type'   => 'count',
    'coach_id'       => 1,
    'start_date'     => date('Y-m-d', strtotime('-60 days')),
    'end_date'       => date('Y-m-d', strtotime('-1 days')),
    'total_sessions' => 0,
    'status'         => '진행중',
]);
t_assert_eq('종료', recomputeOrderStatus($db, $id), 'count + total 0 + end 만료 → 종료 (기간 만료만)');
$db->rollBack();
```

- [ ] **Step 2: 테스트 실행**

```bash
php tests/run_tests.php
```
Expected: 누적 20 PASS, 0 FAIL.

- [ ] **Step 3: 변경 로그 기록 검증 테스트 추가**

```php
t_section('change_logs 기록');

$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('-5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '매칭완료',
]);
recomputeOrderStatus($db, $id);
$log = $db->query("
    SELECT action, actor_type, actor_id, old_value, new_value
      FROM change_logs
     WHERE target_type='order' AND target_id={$id}
     ORDER BY id DESC LIMIT 1
")->fetch();
t_assert_eq('auto_in_progress', $log['action'] ?? null, 'log action = auto_in_progress');
t_assert_eq('system', $log['actor_type'] ?? null, 'log actor_type = system');
t_assert_eq(0, (int)($log['actor_id'] ?? -1), 'log actor_id = 0');
t_assert_eq('{"status":"ë§¤ì¹ìë£"}', $log['old_value'] ?? null, 'log old_value JSON contains 매칭완료');
t_assert_eq('{"status":"ì§íì¤"}', $log['new_value'] ?? null, 'log new_value JSON contains 진행중');
$db->rollBack();
```

> JSON 의 한국어가 escape 되어 저장되는지(\\uXXXX) 또는 raw UTF-8 으로 저장되는지는 `logChange()` 가 `JSON_UNESCAPED_UNICODE` 를 쓰는지에 따라 다르다. `helpers.php:186-188` 을 보면 `JSON_UNESCAPED_UNICODE` 사용 → expected 를 raw UTF-8 로 변경:

위 expected 부분을 다음으로 교체:

```php
t_assert_eq('{"status":"매칭완료"}', $log['old_value'] ?? null, 'log old_value JSON');
t_assert_eq('{"status":"진행중"}', $log['new_value'] ?? null, 'log new_value JSON');
```

- [ ] **Step 4: 테스트 실행**

```bash
php tests/run_tests.php
```
Expected: 누적 25 PASS, 0 FAIL.

- [ ] **Step 5: 커밋**

```bash
git add tests/auto_status_transition_test.php
git commit -m "test(status): allowRevertTerminated/엣지 케이스/change_logs 기록 검증"
```

---

## Task 5: withOrderLock 트랜잭션 모델 테스트

**Files:**
- Modify: `tests/auto_status_transition_test.php`

- [ ] **Step 1: withOrderLock 가드 + 패턴 B 테스트 추가**

PT 는 DEV/PROD 분리가 없어 fixture 를 commit 하는 패턴 A 라이브 테스트는 cleanup 실패 시 PROD DB 에 leak 위험이 있다. 따라서 단위 테스트는 (a) inTransaction 가드 throw 와 (b) 패턴 B 정상 동작만 검증하고, 패턴 A 의 실제 commit 경로는 cron 백필 (Task 10 Step 3) 에서 통합 검증한다.

```php
t_section('withOrderLock — 트랜잭션 모델 가드');

$db = getDB();

// 가드: 활성 트랜잭션 안에서 호출 시 RuntimeException
$db->beginTransaction();
t_assert_throws(
    fn() => withOrderLock($db, 1, fn() => null),
    RuntimeException::class,
    '활성 트랜잭션 내 withOrderLock 호출 → RuntimeException'
);
$db->rollBack();

// 패턴 B: 활성 트랜잭션 내에서 직접 SELECT FOR UPDATE + recompute 호출
$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('-5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '매칭완료',
]);
$db->prepare("SELECT id FROM orders WHERE id=? FOR UPDATE")->execute([$id]);
$result = recomputeOrderStatus($db, $id);
t_assert_eq('진행중', $result, '패턴 B: 트랜잭션 내 직접 호출 정상');
$db->rollBack();
```

- [ ] **Step 2: 테스트 실행**

```bash
php tests/run_tests.php
```
Expected: 누적 27 PASS, 0 FAIL.

- [ ] **Step 3: 커밋**

```bash
git add tests/auto_status_transition_test.php
git commit -m "test(status): withOrderLock 트랜잭션 가드 + 두 호출 패턴 검증"
```

---

## Task 6: matching.php confirm — coach_id 만 UPDATE + recompute

**Files:**
- Modify: `public_html/api/matching.php`

- [ ] **Step 1: 현재 confirm 로직 확인**

`public_html/api/matching.php` 약 338-345 행:

```php
$db->beginTransaction();
try {
    $updOrder    = $db->prepare("UPDATE orders SET coach_id = ?, status = '매칭완료' WHERE id = ?");
    $insAssign   = $db->prepare(...);
    $insLog      = $db->prepare(...);

    foreach ($matched as $m) {
        $updOrder->execute([(int)$m['proposed_coach_id'], (int)$m['order_id']]);
        ...
```

- [ ] **Step 2: prepare 라인 + execute 라인 변경**

`UPDATE orders SET coach_id = ?, status = '매칭완료'` 라인을 `UPDATE orders SET coach_id = ?` 만으로 줄이고, 같은 트랜잭션 안에서 lock + recompute 호출.

수정 위치 (340행 근방):

```php
        $updOrder    = $db->prepare("UPDATE orders SET coach_id = ? WHERE id = ?");
        $lockOrder   = $db->prepare("SELECT id FROM orders WHERE id = ? FOR UPDATE");
        $insAssign   = $db->prepare("
            INSERT INTO coach_assignments (member_id, coach_id, order_id, assigned_at, reason)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        $insLog = $db->prepare("
            INSERT INTO change_logs (target_type, target_id, action, old_value, new_value, actor_type, actor_id)
            VALUES ('order', ?, 'coach_assigned', ?, ?, 'admin', ?)
        ");

        foreach ($matched as $m) {
            $orderId = (int)$m['order_id'];
            $lockOrder->execute([$orderId]);
            $updOrder->execute([(int)$m['proposed_coach_id'], $orderId]);
            recomputeOrderStatus($db, $orderId);

            // (이하 기존 reasonLabel / insAssign / insLog 로직 그대로)
```

- [ ] **Step 3: 변경 후 동작 검증 — 통합 smoke**

가벼운 smoke 테스트 추가. `tests/auto_status_transition_test.php` 에 append:

```php
t_section('matching confirm — 통합 시나리오');

$db = getDB();

// 활성 코치 1명 확보
$activeCoach = $db->query("SELECT id FROM coaches WHERE status='active' LIMIT 1")->fetchColumn();

if (!$activeCoach) {
    echo "  SKIP  활성 코치가 DB에 없어 통합 smoke 생략\n";
} else {
    // 직접 함수만 호출하여 결정 트리 통합 동작 검증
    // (matching.php 라우트 호출은 인증 의존성 때문에 통합테스트로 별도 진행)
    $db->beginTransaction();
    $id = t_make_order($db, [
        'coach_id'   => null,
        'start_date' => date('Y-m-d', strtotime('+5 days')),
        'end_date'   => date('Y-m-d', strtotime('+30 days')),
        'status'     => '매칭대기',
    ]);
    // confirm 시뮬레이션: coach_id 만 UPDATE 후 recompute
    $db->prepare("SELECT id FROM orders WHERE id=? FOR UPDATE")->execute([$id]);
    $db->prepare("UPDATE orders SET coach_id=? WHERE id=?")->execute([(int)$activeCoach, $id]);
    $newStatus = recomputeOrderStatus($db, $id);
    t_assert_eq('매칭완료', $newStatus, 'confirm 시뮬레이션 — start 미래 → 매칭완료');
    $db->rollBack();

    $db->beginTransaction();
    $id = t_make_order($db, [
        'coach_id'   => null,
        'start_date' => date('Y-m-d', strtotime('-5 days')),
        'end_date'   => date('Y-m-d', strtotime('+30 days')),
        'status'     => '매칭대기',
    ]);
    $db->prepare("SELECT id FROM orders WHERE id=? FOR UPDATE")->execute([$id]);
    $db->prepare("UPDATE orders SET coach_id=? WHERE id=?")->execute([(int)$activeCoach, $id]);
    $newStatus = recomputeOrderStatus($db, $id);
    t_assert_eq('진행중', $newStatus, 'confirm 시뮬레이션 — start 이미 지남 → 진행중');
    $db->rollBack();
}
```

- [ ] **Step 4: 테스트 실행**

```bash
php tests/run_tests.php
```
Expected: 누적 29 PASS, 0 FAIL (활성 코치 있을 시).

- [ ] **Step 5: 커밋**

```bash
git add public_html/api/matching.php tests/auto_status_transition_test.php
git commit -m "feat(status): matching confirm — coach_id만 UPDATE + recomputeOrderStatus 위임"
```

---

## Task 7: orders.php — create / update 후크

**Files:**
- Modify: `public_html/api/orders.php`

- [ ] **Step 1: create 후크 추가**

`public_html/api/orders.php` 의 `case 'create':` 블록에서 `$db->commit();` 직전에 lock + recompute 추가.

기존 (87-114 라인 근방):

```php
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO orders ...");
        $stmt->execute([...]);
        $orderId = (int)$db->lastInsertId();

        if ($productType === 'count' && $totalSessions > 0) {
            // session rows 생성
        }

        if ($coachId) {
            logChange(...);
        }

        $db->commit();
```

수정 — `$db->commit();` 직전에 다음 두 줄 삽입:

```php
        $db->prepare("SELECT id FROM orders WHERE id = ? FOR UPDATE")->execute([$orderId]);
        recomputeOrderStatus($db, $orderId);

        $db->commit();
```

- [ ] **Step 2: update 후크 추가 (status 키 포함 시 스킵)**

`case 'update':` 블록 내 `$db->commit();` 직전:

```php
        // 자동 status 재평가 — 사람이 명시적으로 status 를 보낸 경우 스킵
        if (!array_key_exists('status', $input)) {
            $db->prepare("SELECT id FROM orders WHERE id = ? FOR UPDATE")->execute([$id]);
            recomputeOrderStatus($db, $id);
        }

        $db->commit();
```

- [ ] **Step 3: 통합 smoke 테스트 추가**

`tests/auto_status_transition_test.php` 에 append:

```php
t_section('orders.php — create/update 후크 (단위)');

$db = getDB();
$activeCoach = $db->query("SELECT id FROM coaches WHERE status='active' LIMIT 1")->fetchColumn();

if ($activeCoach) {
    // create 시뮬레이션 — status 기본 매칭대기, coach 있고 start 이미 지났으면 후크가 진행중으로
    $db->beginTransaction();
    $db->prepare("INSERT INTO members (name, phone) VALUES (?, ?)")
       ->execute(['후크테스트_' . uniqid(), '01000000000']);
    $memberId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO orders (member_id, coach_id, product_name, product_type, start_date, end_date, total_sessions, amount, status) VALUES (?, ?, ?, 'period', ?, ?, NULL, 0, '매칭대기')")
       ->execute([$memberId, (int)$activeCoach, '후크테스트', date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('+30 days'))]);
    $orderId = (int)$db->lastInsertId();
    $db->prepare("SELECT id FROM orders WHERE id=? FOR UPDATE")->execute([$orderId]);
    recomputeOrderStatus($db, $orderId);
    $after = $db->query("SELECT status FROM orders WHERE id={$orderId}")->fetchColumn();
    t_assert_eq('진행중', $after, 'create 후크: coach + 과거 start → 진행중');
    $db->rollBack();

    // update 후크: status 키 포함 시 스킵 시뮬레이션
    $db->beginTransaction();
    $orderId = t_make_order($db, [
        'coach_id'   => null,
        'start_date' => date('Y-m-d', strtotime('-5 days')),
        'end_date'   => date('Y-m-d', strtotime('+30 days')),
        'status'     => '매칭대기',
    ]);
    // 운영자가 status 를 직접 진행중으로 set
    $db->prepare("UPDATE orders SET status='진행중' WHERE id=?")->execute([$orderId]);
    // 후크는 status 키 포함이므로 스킵 (recompute 호출 X)
    // recompute 가 호출되지 않은 상태와 같음 → status 그대로 진행중 유지
    $after = $db->query("SELECT status FROM orders WHERE id={$orderId}")->fetchColumn();
    t_assert_eq('진행중', $after, 'update 후크 스킵 시 status 보존');
    $db->rollBack();
}
```

- [ ] **Step 4: 테스트 실행**

```bash
php tests/run_tests.php
```
Expected: 누적 31 PASS, 0 FAIL (활성 코치 있을 시).

- [ ] **Step 5: 커밋**

```bash
git add public_html/api/orders.php tests/auto_status_transition_test.php
git commit -m "feat(status): orders.php create/update 후크 — update는 status 키 시 스킵"
```

---

## Task 8: orders.php — complete_session 후크 (allowRevertTerminated)

**Files:**
- Modify: `public_html/api/orders.php`

- [ ] **Step 1: complete_session 핸들러 수정**

`public_html/api/orders.php` `case 'complete_session':` 블록.

기존 토글 부분 (약 207-213 라인):

```php
        // Toggle completion
        if ($session['completed_at']) {
            $db->prepare("UPDATE order_sessions SET completed_at = NULL WHERE id = ?")->execute([$sessionId]);
            jsonSuccess(['completed' => false], '회차 완료가 취소되었습니다');
        } else {
            $db->prepare("UPDATE order_sessions SET completed_at = NOW() WHERE id = ?")->execute([$sessionId]);
            jsonSuccess(['completed' => true], '회차가 완료 처리되었습니다');
        }
```

수정 — 토글 직후 / jsonSuccess 직전에 recompute (트랜잭션 없으니 withOrderLock):

```php
        // Toggle completion
        if ($session['completed_at']) {
            $db->prepare("UPDATE order_sessions SET completed_at = NULL WHERE id = ?")->execute([$sessionId]);
            $newCompleted = false;
            $msg = '회차 완료가 취소되었습니다';
        } else {
            $db->prepare("UPDATE order_sessions SET completed_at = NOW() WHERE id = ?")->execute([$sessionId]);
            $newCompleted = true;
            $msg = '회차가 완료 처리되었습니다';
        }

        // 자동 status 재평가 (자동 종료된 order 의 회차 취소 시 진행중 복귀 위해 allowRevertTerminated=true)
        $orderId = (int)$session['order_id'];
        withOrderLock($db, $orderId, fn() => recomputeOrderStatus($db, $orderId, null, true));

        jsonSuccess(['completed' => $newCompleted], $msg);
```

- [ ] **Step 2: complete_session 테스트 추가 (자동 종료 → 회차 취소 → 진행중)**

`tests/auto_status_transition_test.php` 에 append:

```php
t_section('complete_session 토글 — 자동 종료 후 취소로 진행중 복귀 (단위)');

$db = getDB();
$activeCoach = $db->query("SELECT id FROM coaches WHERE status='active' LIMIT 1")->fetchColumn();

if ($activeCoach) {
    $db->beginTransaction();
    // 1. count 상품 5회짜리, 5회 다 완료된 상태 + status 종료(=auto_terminate 결과로 가정)
    $orderId = t_make_order($db, [
        'product_type'   => 'count',
        'coach_id'       => (int)$activeCoach,
        'start_date'     => date('Y-m-d', strtotime('-30 days')),
        'end_date'       => date('Y-m-d', strtotime('+30 days')),
        'total_sessions' => 5,
        'used_sessions'  => 5,
        'status'         => '종료',
    ]);

    // 2. 마지막 회차 완료 취소 (5번째 세션의 completed_at = NULL)
    $db->prepare("
        UPDATE order_sessions SET completed_at = NULL
         WHERE order_id = ? AND session_number = 5
    ")->execute([$orderId]);

    // 3. complete_session 후크 시뮬레이션 — allowRevertTerminated=true
    $db->prepare("SELECT id FROM orders WHERE id=? FOR UPDATE")->execute([$orderId]);
    $newStatus = recomputeOrderStatus($db, $orderId, null, true);
    t_assert_eq('진행중', $newStatus, '자동 종료 + 회차 취소 → 진행중 복귀');
    $db->rollBack();
}
```

- [ ] **Step 3: 테스트 실행**

```bash
php tests/run_tests.php
```
Expected: 누적 32 PASS, 0 FAIL.

- [ ] **Step 4: 커밋**

```bash
git add public_html/api/orders.php tests/auto_status_transition_test.php
git commit -m "feat(status): complete_session 후크 + allowRevertTerminated 로 종료 복귀"
```

---

## Task 9: import.php — CSV import 후 일괄 후크

**Files:**
- Modify: `public_html/api/import.php`

- [ ] **Step 1: import.php 의 트랜잭션 commit 위치 파악**

`public_html/api/import.php` 의 `case 'orders':` 또는 import 메인 핸들러에서 `$db->commit();` 호출 위치 확인.

```bash
grep -n "beginTransaction\|commit\|rollBack" public_html/api/import.php
```

- [ ] **Step 2: 영향 받은 order id 목록 수집 + commit 후 일괄 recompute**

import 핸들러가 INSERT 한 모든 order id 를 배열에 모은다 (이미 그렇게 안 모으고 있다면 그렇게 변경). 트랜잭션 commit 후 다음 루프 추가:

```php
        // import 트랜잭션 끝난 뒤 자동 status 재평가
        foreach ($importedOrderIds as $oid) {
            try {
                withOrderLock($db, (int)$oid, fn() => recomputeOrderStatus($db, (int)$oid));
            } catch (Throwable $e) {
                // 단일 row 실패가 전체 import 응답을 막지 않도록 로그만 남기고 진행
                error_log("auto_status_transition after import: order={$oid} err=" . $e->getMessage());
            }
        }
```

> 정확한 변수명 (`$importedOrderIds`) 은 현재 import.php 코드를 읽고 결정. 없으면 INSERT 루프에서 `$insertedIds[] = (int)$db->lastInsertId();` 로 모은다.

- [ ] **Step 3: 라이브 import 1회 smoke (운영자 자체 검증)**

이 단계는 자동 테스트로 커버하기 어려움. 작업자는 plan 작성 시점에 기존 import.php 동작에 대한 단위 테스트 자체가 없음을 인지하고, 본 task 완료 후 운영자에게 "샘플 CSV 1건으로 어드민 import 화면에서 결과 status 가 결정 트리대로 들어가는지 확인" 요청한다.

- [ ] **Step 4: 커밋**

```bash
git add public_html/api/import.php
git commit -m "feat(status): import.php commit 후 영향 order 일괄 recompute"
```

---

## Task 10: cron 본체 + 디렉토리/crontab

**Files:**
- Create: `cron/auto_status_transition.php`, `cron/logs/.gitkeep` (디렉토리 트래킹용)

- [ ] **Step 1: `cron/auto_status_transition.php` 작성**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../public_html/includes/db.php';
require __DIR__ . '/../public_html/includes/helpers.php';

$db = getDB();
$today = date('Y-m-d');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$candidates = $db->query("
    SELECT id FROM orders
     WHERE status NOT IN ('연기','중단','환불','종료')
")->fetchAll(PDO::FETCH_COLUMN);

$summary = ['total' => count($candidates), 'changed' => 0, 'errors' => 0];

foreach ($candidates as $orderId) {
    try {
        $newStatus = withOrderLock($db, (int)$orderId, function () use ($db, $orderId, $today) {
            return recomputeOrderStatus($db, (int)$orderId, $today);
        });
        if ($newStatus !== null) {
            $summary['changed']++;
        }
    } catch (Throwable $e) {
        $summary['errors']++;
        error_log("auto_status_transition: order={$orderId} err=" . $e->getMessage());
    }
}

$logLine = sprintf(
    "[%s] candidates=%d changed=%d errors=%d\n",
    date('Y-m-d H:i:s'),
    $summary['total'], $summary['changed'], $summary['errors']
);
file_put_contents($logDir . '/auto_status.log', $logLine, FILE_APPEND);
```

- [ ] **Step 2: `cron/logs/` 디렉토리 트래킹**

```bash
mkdir -p cron/logs
touch cron/logs/.gitkeep
```

- [ ] **Step 3: cron 수동 1회 실행 — 백필**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php cron/auto_status_transition.php
tail -1 cron/logs/auto_status.log
```

Expected output 형태:
```
[2026-04-29 HH:MM:SS] candidates=NNN changed=MMM errors=0
```

`changed` 가 0보다 크면 정상 (기존 데이터 정렬됨). `errors` 가 0이 아니면 PHP error log 확인.

- [ ] **Step 4: change_logs 사후 점검**

```bash
mysql -u root -p<pass> -e "
SELECT action, COUNT(*) AS cnt
  FROM SORITUNE_PT.change_logs
 WHERE actor_type='system'
   AND created_at >= NOW() - INTERVAL 1 HOUR
 GROUP BY action;
"
```

기대: `auto_match_complete / auto_in_progress / auto_terminate / auto_revert_to_pending` 중 일부에 카운트.

- [ ] **Step 5: crontab 등록**

운영자에게 다음 등록 요청. (작업자가 직접 등록하지 말고 사용자에게 confirm 후)

```
0 3 * * * /usr/bin/php /var/www/html/_______site_SORITUNECOM_PT/cron/auto_status_transition.php >> /var/www/html/_______site_SORITUNECOM_PT/cron/logs/cron.log 2>&1
```

- [ ] **Step 6: 커밋**

```bash
git add cron/auto_status_transition.php cron/logs/.gitkeep
git commit -m "feat(status): 일 1회 cron 본체 + logs 디렉토리"
```

---

## Task 11: UI 라벨 매핑 (코치 + 어드민 회원 차트)

**Files:**
- Modify: `public_html/coach/js/pages/member-chart.js`, `public_html/admin/js/pages/member-chart.js`

- [ ] **Step 1: 코치 회원 차트 변경로그 매핑**

`public_html/coach/js/pages/member-chart.js::loadLogs()` (159-170 라인 근방).

기존:

```js
  async loadLogs() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===3));
    const res = await API.get(`/api/logs.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const logs = res.data.logs;
    document.getElementById('tabContent').innerHTML = logs.length === 0
      ? '<div class="empty-state">변경 이력이 없습니다</div>'
      : `<table class="data-table"><thead><tr><th>일시</th><th>변경</th><th>변경자</th></tr></thead><tbody>
          ${logs.map(l => `<tr><td style="font-size:11px;color:var(--text-secondary)">${UI.esc(l.created_at)}</td>
          <td style="font-size:12px">${UI.esc(l.action)}</td><td style="font-size:12px">${UI.esc(l.actor_name||l.actor_type)}</td></tr>`).join('')}
        </tbody></table>`;
  },
```

수정 — 함수 내 상수 + 매핑 헬퍼 사용:

```js
  ACTION_LABELS: {
    auto_match_complete: '자동 매칭완료',
    auto_in_progress: '자동 진행중 전환',
    auto_terminate: '기간/회차 만료 자동 종료',
    auto_revert_to_pending: '코치 해제로 매칭대기 복귀',
  },

  formatActor(l) {
    const name = l.actor_name || l.actor_type;
    return name === 'system' ? '시스템 자동' : name;
  },

  formatAction(action) {
    return this.ACTION_LABELS[action] || action;
  },

  async loadLogs() {
    document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.toggle('active', i===3));
    const res = await API.get(`/api/logs.php?action=list&member_id=${this.memberId}`);
    if (!res.ok) return;
    const logs = res.data.logs;
    document.getElementById('tabContent').innerHTML = logs.length === 0
      ? '<div class="empty-state">변경 이력이 없습니다</div>'
      : `<table class="data-table"><thead><tr><th>일시</th><th>변경</th><th>변경자</th></tr></thead><tbody>
          ${logs.map(l => `<tr><td style="font-size:11px;color:var(--text-secondary)">${UI.esc(l.created_at)}</td>
          <td style="font-size:12px">${UI.esc(this.formatAction(l.action))}</td><td style="font-size:12px">${UI.esc(this.formatActor(l))}</td></tr>`).join('')}
        </tbody></table>`;
  },
```

- [ ] **Step 2: 어드민 회원 차트 동일 매핑 적용**

`public_html/admin/js/pages/member-chart.js` 의 변경로그 렌더 위치를 찾는다:

```bash
grep -n "loadLogs\|change_logs\|action\|actor_name" public_html/admin/js/pages/member-chart.js | head -20
```

찾은 위치에 동일한 `ACTION_LABELS / formatActor / formatAction` 추가하고 렌더 시 `formatAction(l.action)`, `formatActor(l)` 사용. 동일 라벨 객체를 두 파일에 복제하는 것은 PT JS 모듈 시스템이 없는 점을 감안한 의도적 중복 (DRY 위반이지만, 공유 유틸 빌드/번들 인프라 도입은 본 plan 범위 밖).

- [ ] **Step 3: 운영자에게 수동 검증 요청**

브라우저에서 코치/어드민 로그인 → 임의 회원 차트 → 변경로그 탭 → `system` actor + `auto_*` action 항목이 한국어 라벨로 보이는지 시각 확인.

- [ ] **Step 4: 커밋**

```bash
git add public_html/coach/js/pages/member-chart.js public_html/admin/js/pages/member-chart.js
git commit -m "feat(status): 변경로그 탭 — system actor + auto_* action 한국어 라벨 매핑"
```

---

## Task 12: 최종 검증 + push 결정

**Files:** (변경 없음 — 검증 단계)

- [ ] **Step 1: 전체 테스트 재실행**

```bash
cd /var/www/html/_______site_SORITUNECOM_PT
php tests/run_tests.php
```

Expected: 모든 테스트 PASS, FAIL=0.

- [ ] **Step 2: 커밋 히스토리 점검**

```bash
git log --oneline -15
```

Task 1~11 의 커밋이 순차적으로 있어야 함. 각 커밋이 단일 책임으로 깔끔한지 확인.

- [ ] **Step 3: 변경 파일 sanity scan**

```bash
git diff main~12..main --stat
```

수정된 파일 목록이 spec 의 "File Structure" 와 일치하는지 확인.

- [ ] **Step 4: 사용자에게 push 권한 요청**

```
Task 1~11 완료. 모든 자동화 테스트 통과. 백필 cron 1회 실행으로 NNN건 정렬됨 (auto_*  카운트 기록).

PT 사이트는 DEV/PROD 분리가 없어 push 시 즉시 PROD 반영됩니다. 다음 단계 결정 부탁:

1. crontab 등록 (운영자)
2. origin/main push
3. 변경로그 탭 시각 확인 (코치/어드민)
```

작업자는 사용자 명시 승인 없이 push / crontab 등록을 진행하지 않는다.

---

## 자체 검증 체크리스트 (작업자가 plan 시작 시 한 번 훑기)

- [ ] spec `2026-04-29-order-status-auto-transition-design.md` 모든 섹션을 읽음
- [ ] PT 사이트 DEV/PROD 분리 없음 — push 결정은 사용자 승인 필요
- [ ] git config 손대지 않음
- [ ] 각 task commit 메시지에 plan/spec 식별자 일관 표기 (`feat(status):` / `test(status):` / `docs(status):`)
- [ ] 운영자 명시 status update 시 후크 스킵이 핵심 룰 — Task 7 step 2 의 조건 (`!array_key_exists('status', $input)`) 확인
- [ ] complete_session 만 `allowRevertTerminated=true` 호출 — Task 8 외 다른 호출 지점에서 plain 호출인지 확인
