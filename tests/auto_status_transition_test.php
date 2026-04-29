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
