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

t_section('allowRevertTerminated 플래그');

$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('-5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '종료',
]);
t_assert_eq(null, recomputeOrderStatus($db, $id, null, false), '기본 호출 — 종료 보호');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('-5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '종료',
]);
t_assert_eq('진행중', recomputeOrderStatus($db, $id, null, true), 'allowRevert=true + 회차 미소진 + 기간 안 지남 → 진행중');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, ['status' => '연기', 'coach_id' => 1]);
t_assert_eq(null, recomputeOrderStatus($db, $id, null, true), '연기는 플래그 무관하게 보호');
$db->rollBack();

t_section('엣지: order 미존재');

t_assert_eq(null, recomputeOrderStatus(getDB(), 99999999), '미존재 id → null (예외 없음)');

t_section('엣지: count + total_sessions NULL/0');

$db->beginTransaction();
$id = t_make_order($db, [
    'product_type'   => 'count',
    'coach_id'       => 1,
    'start_date'     => date('Y-m-d', strtotime('-5 days')),
    'end_date'       => date('Y-m-d', strtotime('+30 days')),
    'total_sessions' => null,
    'status'         => '매칭완료',
]);
t_assert_eq('진행중', recomputeOrderStatus($db, $id), 'count + total NULL + end 미래 → 진행중');
$db->rollBack();

$db->beginTransaction();
$id = t_make_order($db, [
    'product_type'   => 'count',
    'coach_id'       => 1,
    'start_date'     => date('Y-m-d', strtotime('-60 days')),
    'end_date'       => date('Y-m-d', strtotime('-1 days')),
    'total_sessions' => 0,
    'status'         => '진행중',
]);
t_assert_eq('종료', recomputeOrderStatus($db, $id), 'count + total 0 + end 만료 → 종료 (기간 만료만)');
$db->rollBack();

t_section('change_logs 기록');

$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('-5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '매칭완료',
]);
recomputeOrderStatus($db, $id);
$log = $db->query("
    SELECT action, actor_type, actor_id, old_value, new_value
      FROM change_logs
     WHERE target_type='order' AND target_id={$id}
     ORDER BY id DESC LIMIT 1
")->fetch();
t_assert_eq('auto_in_progress', $log['action'] ?? null, 'log action = auto_in_progress');
t_assert_eq('system', $log['actor_type'] ?? null, 'log actor_type = system');
t_assert_eq(0, (int)($log['actor_id'] ?? -1), 'log actor_id = 0');
t_assert_eq('{"status":"매칭완료"}', $log['old_value'] ?? null, 'log old_value JSON');
t_assert_eq('{"status":"진행중"}', $log['new_value'] ?? null, 'log new_value JSON');
$db->rollBack();

t_section('withOrderLock — 트랜잭션 모델 가드');

// 가드: 활성 트랜잭션 안에서 호출 시 RuntimeException
$db->beginTransaction();
t_assert_throws(
    fn() => withOrderLock($db, 1, fn() => null),
    RuntimeException::class,
    '활성 트랜잭션 내 withOrderLock 호출 → RuntimeException'
);
$db->rollBack();

// 패턴 B: 활성 트랜잭션 내에서 직접 SELECT FOR UPDATE + recompute 호출
$db->beginTransaction();
$id = t_make_order($db, [
    'coach_id'   => 1,
    'start_date' => date('Y-m-d', strtotime('-5 days')),
    'end_date'   => date('Y-m-d', strtotime('+30 days')),
    'status'     => '매칭완료',
]);
$db->prepare("SELECT id FROM orders WHERE id=? FOR UPDATE")->execute([$id]);
$result = recomputeOrderStatus($db, $id);
t_assert_eq('진행중', $result, '패턴 B: 트랜잭션 내 직접 호출 정상');
$db->rollBack();
