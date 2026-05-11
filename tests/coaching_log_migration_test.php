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

// idempotent
$result2 = CoachingLogMigration::run_import('TEST_BATCH_1', 1);
t_assert_eq(1, $result2['imported'], 'idempotent — same count');

$pdo->rollBack();
