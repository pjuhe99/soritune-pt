<?php
declare(strict_types=1);

t_section('kakao_check smoke');
t_assert_eq(2, 1 + 1, '1+1 == 2');

$db = getDB();

// 이후 태스크들이 여기에 cohorts / list / toggle_join / set_cohort 섹션을 추가한다.

define('KAKAO_CHECK_LIB_ONLY', true);
require_once __DIR__ . '/../public_html/api/kakao_check.php';

t_section('cohorts — coach scope');

$db->beginTransaction();
$activeCoach = (int)$db->query("SELECT id FROM coaches WHERE status='active' LIMIT 1")->fetchColumn();
$otherCoach = (int)$db->query("SELECT id FROM coaches WHERE status='active' AND id != {$activeCoach} LIMIT 1")->fetchColumn();

if ($otherCoach === 0) {
    echo "  SKIP  coach scope (active 코치 2명 미만)\n";
    $db->rollBack();
} else {
    $o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-15', 'end_date' => '2026-07-14']);
    $o2 = t_make_order($db, ['coach_id' => $otherCoach, 'status' => '진행중', 'start_date' => '2026-05-01', 'end_date' => '2026-07-31']);
    $o3 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '종료', 'start_date' => '2026-03-01', 'end_date' => '2026-04-01']); // 제외 대상

    $cohorts = kakaoCheckCohorts($db, $activeCoach);
    t_assert_eq(['2026-04'], $cohorts, 'coach scope: 본인 진행중 order만 cohort에 등장');

    $db->rollBack();
}

t_section('cohorts — admin scope');

$db->beginTransaction();
$o1 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-06-15', 'end_date' => '2026-09-14']);
$o2 = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '매칭완료', 'start_date' => '2026-07-01', 'end_date' => '2026-09-30']);

$cohorts = kakaoCheckCohorts($db, null); // admin = 전체
t_assert_true(in_array('2026-06', $cohorts, true), 'admin scope: 2026-06 포함');
t_assert_true(in_array('2026-07', $cohorts, true), 'admin scope: 2026-07 포함');

$db->rollBack();

t_section('cohorts — cohort_month override 우선');

$db->beginTransaction();
$o = t_make_order($db, ['coach_id' => $activeCoach, 'status' => '진행중', 'start_date' => '2026-04-28', 'end_date' => '2026-07-27']);
$db->prepare("UPDATE orders SET cohort_month='2026-05' WHERE id=?")->execute([$o]);

$cohorts = kakaoCheckCohorts($db, $activeCoach);
t_assert_true(in_array('2026-05', $cohorts, true), 'override 값이 effective_cohort에 반영');
t_assert_true(!in_array('2026-04', $cohorts, true), 'override가 있으면 자동 분류는 사라짐');

$db->rollBack();
