<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../public_html/includes/member_chart_access.php';

t_section('member_chart_access');

$pdo = getDb();

// Note: coaches.status='active' is PT 스키마 (is_active 컬럼 없음)
$coach_id = (int)$pdo->query(
    "SELECT id FROM coaches WHERE status='active' ORDER BY id LIMIT 1"
)->fetchColumn();

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
