<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

t_section('member_chart_api_orders_sql');

/**
 * member_chart.php 의 orders+coaches JOIN SQL 회귀 가드.
 * helper 단위 테스트로 안 잡혔던 "Unknown column 'c.name'" 버그 재발 방지.
 * SQL 문자열을 member_chart.php 파일에서 추출 → 실제 fixture 로 execute.
 */

$pdo = getDb();

// member_chart.php 의 orders SELECT 블록을 정규식으로 추출.
$source = file_get_contents(__DIR__ . '/../public_html/api/member_chart.php');
$matched = preg_match('/\$orders = \$pdo->prepare\(\s*"([^"]+)"\s*\);/', $source, $m);
t_assert_eq(1, $matched, 'member_chart.php 의 orders SELECT 문 추출');
$ordersSql = $m[1] ?? '';

$pdo->beginTransaction();
try {
    $orderId = t_make_order($pdo, ['product_name' => 'SQL_REGRESSION_TEST']);
    $memberId = (int)$pdo->query("SELECT member_id FROM orders WHERE id={$orderId}")->fetchColumn();

    // 추출한 SQL 그대로 execute — 컬럼 미존재시 PDOException 을 잡아서 FAIL 처리.
    $rows = null;
    $sqlError = null;
    try {
        $stmt = $pdo->prepare($ordersSql);
        $stmt->execute([':m' => $memberId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $sqlError = $e->getMessage();
    }

    t_assert_eq(null, $sqlError, 'orders SELECT 가 PDOException 없이 실행됨 (예: Unknown column 회귀 가드)');
    if ($rows !== null) {
        t_assert_eq(1, count($rows), 'fixture order 1건 반환');
        t_assert_true(array_key_exists('coach_name', $rows[0]), 'coach_name alias 결과 포함 (frontend contract)');
        t_assert_eq('SQL_REGRESSION_TEST', $rows[0]['product_name'], 'product_name 일치');
    }
} finally {
    $pdo->rollBack();
}
