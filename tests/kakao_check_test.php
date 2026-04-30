<?php
declare(strict_types=1);

t_section('kakao_check smoke');
t_assert_eq(2, 1 + 1, '1+1 == 2');

$db = getDB();

// 코치 ID를 최상단에서 미리 조회 (모든 cohorts 섹션에서 공유)
$activeCoach = (int)$db->query("SELECT id FROM coaches WHERE status='active' LIMIT 1")->fetchColumn();
$stmt = $db->prepare("SELECT id FROM coaches WHERE status='active' AND id != ? LIMIT 1");
$stmt->execute([$activeCoach]);
$otherCoach = (int)$stmt->fetchColumn();

// admin ID 최상단 조회 (toggle_join 테스트에서 공유)
$adminId = (int)$db->query("SELECT id FROM admins LIMIT 1")->fetchColumn();

// 이후 태스크들이 여기에 cohorts / list / toggle_join / set_cohort 섹션을 추가한다.

define('KAKAO_CHECK_LIB_ONLY', true);
require_once __DIR__ . '/../public_html/api/kakao_check.php';

t_section('cohorts — coach scope');

if ($activeCoach === 0) {
    echo "  SKIP  coach scope (active 코치 없음)\n";
} elseif ($otherCoach === 0) {
    echo "  SKIP  coach scope (active 코치 2명 미만)\n";
} else {
    $db->beginTransaction();

    $o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-15', 'end_date' => '2026-07-14']);
    $o2 = t_make_order($db, ['coach_id' => $otherCoach, 'status' => '진행중', 'start_date' => '2026-05-01', 'end_date' => '2026-07-31']);
    $o3 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '종료', 'start_date' => '2026-03-01', 'end_date' => '2026-04-01']); // 제외 대상

    $cohorts = kakaoCheckCohorts($db, $activeCoach);
    t_assert_eq(['2026-04'], $cohorts, 'coach scope: 본인 진행중 order만 cohort에 등장');

    $db->rollBack();
}

t_section('cohorts — admin scope');

if ($activeCoach === 0) {
    echo "  SKIP  admin scope (active 코치 없음)\n";
} else {
    $db->beginTransaction();

    $o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-06-15', 'end_date' => '2026-09-14']);
    $o2 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '매칭완료', 'start_date' => '2026-07-01', 'end_date' => '2026-09-30']);

    $cohorts = kakaoCheckCohorts($db, null); // admin = 전체
    t_assert_true(in_array('2026-06', $cohorts, true), 'admin scope: 2026-06 포함');
    t_assert_true(in_array('2026-07', $cohorts, true), 'admin scope: 2026-07 포함');

    $db->rollBack();
}

t_section('cohorts — cohort_month override 우선');

if ($activeCoach === 0) {
    echo "  SKIP  cohort_month override (active 코치 없음)\n";
} else {
    $db->beginTransaction();

    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-28', 'end_date' => '2026-07-27']);
    $db->prepare("UPDATE orders SET cohort_month='2026-05' WHERE id=?")->execute([$o]);

    $cohorts = kakaoCheckCohorts($db, $activeCoach);
    t_assert_true(in_array('2026-05', $cohorts, true), 'override 값이 effective_cohort에 반영');
    t_assert_true(!in_array('2026-04', $cohorts, true), 'override가 있으면 자동 분류는 사라짐');

    $db->rollBack();
}

t_section('list — 기본 list (include_joined=0)');

if ($activeCoach === 0) {
    echo "  SKIP  active 코치 없음\n";
} else {
    $db->beginTransaction();
    $o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09', 'product_name' => 'Speaking 3개월']);
    $o2 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-20', 'end_date' => '2026-07-19', 'product_name' => 'Listening 3개월']);
    $o3 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-15', 'end_date' => '2026-07-14', 'product_name' => 'Speaking 3개월']);
    $db->prepare("UPDATE orders SET kakao_room_joined=1, kakao_room_joined_at=NOW() WHERE id=?")->execute([$o3]);

    $result = kakaoCheckList($db, [
        'cohort' => '2026-04',
        'coach_id' => $activeCoach,
        'include_joined' => false,
        'product' => null,
    ]);

    t_assert_eq(2, count($result['orders']), 'include_joined=false면 체크된 행 제외 → 2건');
    t_assert_eq($o1, (int)$result['orders'][0]['order_id'], '정렬: start_date ASC → o1 첫번째');
    t_assert_eq(2, count($result['products']), 'products: 체크된 것 포함 distinct 2종');
    t_assert_true(in_array('Speaking 3개월', $result['products'], true), 'products에 Speaking 포함');
    t_assert_true(in_array('Listening 3개월', $result['products'], true), 'products에 Listening 포함');

    $db->rollBack();
}

t_section('list — include_joined=true 시 체크된 행 등장');

if ($activeCoach === 0) {
    echo "  SKIP  active 코치 없음\n";
} else {
    $db->beginTransaction();
    $o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
    $o2 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-15', 'end_date' => '2026-07-14']);
    $db->prepare("UPDATE orders SET kakao_room_joined=1 WHERE id=?")->execute([$o2]);

    $result = kakaoCheckList($db, ['cohort' => '2026-04', 'coach_id' => $activeCoach, 'include_joined' => true, 'product' => null]);
    $ids = array_map(fn($r) => (int)$r['order_id'], $result['orders']);
    t_assert_true(in_array($o1, $ids, true), 'include_joined=true: o1 (체크안됨) 포함');
    t_assert_true(in_array($o2, $ids, true), 'include_joined=true: o2 (체크됨) 포함');

    $db->rollBack();
}

t_section('list — cohort_month override가 effective_cohort 반영');

if ($activeCoach === 0) {
    echo "  SKIP  active 코치 없음\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-28', 'end_date' => '2026-07-27']);
    $db->prepare("UPDATE orders SET cohort_month='2026-05' WHERE id=?")->execute([$o]);

    $april = kakaoCheckList($db, ['cohort' => '2026-04', 'coach_id' => $activeCoach, 'include_joined' => false, 'product' => null]);
    $may = kakaoCheckList($db, ['cohort' => '2026-05', 'coach_id' => $activeCoach, 'include_joined' => false, 'product' => null]);
    $aprilIds = array_map(fn($r) => (int)$r['order_id'], $april['orders']);
    $mayIds = array_map(fn($r) => (int)$r['order_id'], $may['orders']);
    t_assert_true(!in_array($o, $aprilIds, true), 'override된 order는 4월에서 사라짐');
    t_assert_true(in_array($o, $mayIds, true), 'override된 order는 5월에 등장');

    $db->rollBack();
}

t_section('list — product 필터');

if ($activeCoach === 0) {
    echo "  SKIP  active 코치 없음\n";
} else {
    $db->beginTransaction();
    $os = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09', 'product_name' => 'Speaking']);
    $ol = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-15', 'end_date' => '2026-07-14', 'product_name' => 'Listening']);

    $result = kakaoCheckList($db, ['cohort' => '2026-04', 'coach_id' => $activeCoach, 'include_joined' => false, 'product' => 'Speaking']);
    $ids = array_map(fn($r) => (int)$r['order_id'], $result['orders']);
    t_assert_true(in_array($os, $ids, true), 'Speaking 필터: os 포함');
    t_assert_true(!in_array($ol, $ids, true), 'Speaking 필터: ol 제외');
    t_assert_eq(2, count($result['products']), 'products는 product 필터 무시 — 여전히 2종');

    $db->rollBack();
}

t_section('toggle_join — 기본 ON');

if ($activeCoach === 0) {
    echo "  SKIP  active 코치 없음\n";
} elseif ($adminId === 0) {
    echo "  SKIP  admin 없음\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);

    kakaoCheckToggle($db, $o, true, 'admin', $adminId);

    $row = $db->query("SELECT kakao_room_joined, kakao_room_joined_at, kakao_room_joined_by FROM orders WHERE id={$o}")->fetch();
    t_assert_eq(1, (int)$row['kakao_room_joined'], 'joined=1');
    t_assert_true($row['kakao_room_joined_at'] !== null, 'joined_at NOT NULL');
    t_assert_eq($adminId, (int)$row['kakao_room_joined_by'], 'joined_by = adminId');

    $log = $db->query("SELECT action, actor_type, actor_id, old_value, new_value FROM change_logs WHERE target_type='order' AND target_id={$o} ORDER BY id DESC LIMIT 1")->fetch();
    t_assert_eq('kakao_room_join', $log['action'], 'log action = kakao_room_join');
    t_assert_eq('admin', $log['actor_type'], 'log actor_type = admin');
    t_assert_eq($adminId, (int)$log['actor_id'], 'log actor_id = adminId');

    $db->rollBack();
}

t_section('toggle_join — OFF + idempotent');

if ($activeCoach === 0) {
    echo "  SKIP  active 코치 없음\n";
} else {
    $db->beginTransaction();
    $o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-10', 'end_date' => '2026-07-09']);
    $db->prepare("UPDATE orders SET kakao_room_joined=1, kakao_room_joined_at=NOW(), kakao_room_joined_by=999 WHERE id=?")->execute([$o]);

    // OFF로 토글
    $changed = kakaoCheckToggle($db, $o, false, 'coach', $activeCoach);
    t_assert_true($changed, 'OFF 토글 — 값 바뀜');
    $row = $db->query("SELECT kakao_room_joined, kakao_room_joined_at, kakao_room_joined_by FROM orders WHERE id={$o}")->fetch();
    t_assert_eq(0, (int)$row['kakao_room_joined'], 'joined=0');
    t_assert_true($row['kakao_room_joined_at'] === null, 'joined_at = NULL');
    t_assert_true($row['kakao_room_joined_by'] === null, 'joined_by = NULL');

    // 같은 값으로 다시 호출 → no-op
    $logCountBefore = (int)$db->query("SELECT COUNT(*) FROM change_logs WHERE target_type='order' AND target_id={$o}")->fetchColumn();
    $changed2 = kakaoCheckToggle($db, $o, false, 'coach', $activeCoach);
    $logCountAfter = (int)$db->query("SELECT COUNT(*) FROM change_logs WHERE target_type='order' AND target_id={$o}")->fetchColumn();
    t_assert_eq(false, $changed2, 'idempotent: 같은 값 재호출 false');
    t_assert_eq($logCountBefore, $logCountAfter, 'idempotent: change_logs 추가 없음');

    $db->rollBack();
}

t_section('toggle_join — non-existent order returns false');

$changed = kakaoCheckToggle($db, 99999999, true, 'admin', 1);
t_assert_eq(false, $changed, 'non-existent order_id → false (no exception)');
