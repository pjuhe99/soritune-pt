<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../public_html/includes/coaching_metrics.php';

t_section('CoachingMetrics::for_order');

$pdo = getDb();

$pdo->beginTransaction();
$pdo->exec("INSERT INTO coaching_calendars (cohort_month, product_name, session_count, created_by)
            VALUES ('2099-12','TEST_PRODUCT_X',5,1)");
$cal = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO coaching_calendar_dates (calendar_id, session_number, scheduled_date) VALUES
    ($cal,1,'2099-12-01'),($cal,2,'2099-12-02'),($cal,3,'2099-12-03'),($cal,4,'2099-12-04'),($cal,5,'2099-12-05')");

$pdo->exec("INSERT INTO members (soritune_id, name) VALUES ('TEST_X', 'Test User X')");
$member_id = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO orders (member_id, product_name, product_type, start_date, end_date, status, cohort_month)
            VALUES ($member_id,'TEST_PRODUCT_X','count','2099-12-01','2099-12-31','진행중','2099-12')");
$order_id = (int)$pdo->lastInsertId();

// 5개 세션: 3개 완료, 솔루션 4개, improved 2개
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
