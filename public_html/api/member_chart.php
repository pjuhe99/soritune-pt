<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
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
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) jsonError('회원을 찾을 수 없습니다', 404);

    $orders = $pdo->prepare("
        SELECT o.*, c.coach_name
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
        $o['calendar'] = $cal ? [
            'id' => (int)$cal['id'],
            'session_count' => (int)$cal['session_count'],
            'dates' => CoachingCalendar::get_dates((int)$cal['id']),
        ] : null;
    }
    unset($o);

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
