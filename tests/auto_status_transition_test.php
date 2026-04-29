<?php
declare(strict_types=1);

t_section('smoke');
t_assert_eq(2, 1 + 1, '1+1 == 2');

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
