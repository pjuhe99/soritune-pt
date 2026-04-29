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
 * Test fixture: insert one member + one order (and sessions if count) inside the caller's transaction.
 * Caller is expected to ROLLBACK to clean up.
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

    $uniq = uniqid();
    $db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
       ->execute(['test_' . $uniq, '테스트회원_' . $uniq, '01000000000']);
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
