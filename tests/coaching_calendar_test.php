<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../public_html/includes/coaching_calendar.php';

t_section('CoachingCalendar::generate_pattern');

$dates = CoachingCalendar::generate_pattern('2026-05-04', 5, 'weekday5');
t_assert_eq(['2026-05-04','2026-05-05','2026-05-06','2026-05-07','2026-05-08'], $dates, 'weekday5 from Monday');

$dates2 = CoachingCalendar::generate_pattern('2026-05-08', 5, 'weekday5');
t_assert_eq(['2026-05-08','2026-05-11','2026-05-12','2026-05-13','2026-05-14'], $dates2, 'weekday5 skip weekend');

$dates3 = CoachingCalendar::generate_pattern('2026-05-04', 3, 'mwf');
t_assert_eq(['2026-05-04','2026-05-06','2026-05-08'], $dates3, 'mwf');

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
t_assert_eq($cal, (int)$found['id'], 'lookup by cohort_month+product_name');

$pdo->rollBack();

exit(t_summary());
