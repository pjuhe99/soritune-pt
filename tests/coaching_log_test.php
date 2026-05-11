<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../public_html/includes/coaching_log.php';
require_once __DIR__ . '/../public_html/includes/coaching_calendar.php';

t_section('CoachingLog::create_for_order — auto link calendar');

$pdo = getDb();
$pdo->beginTransaction();

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

exit(t_summary());
