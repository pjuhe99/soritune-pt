<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/coach_training.php';

t_section('recentTrainingDates — 오늘이 목요일이면 오늘 포함');
$thu = new DateTimeImmutable('2026-04-30 21:00:00', new DateTimeZone('Asia/Seoul')); // Thursday
t_assert_eq(
    ['2026-04-30','2026-04-23','2026-04-16','2026-04-09'],
    recentTrainingDates($thu, 4, 4),
    '목요일 21시 → 오늘 포함 직전 4개'
);

t_section('recentTrainingDates — 금요일은 어제(목) 포함');
$fri = new DateTimeImmutable('2026-05-01 09:00:00', new DateTimeZone('Asia/Seoul')); // Friday
t_assert_eq(
    ['2026-04-30','2026-04-23','2026-04-16','2026-04-09'],
    recentTrainingDates($fri, 4, 4),
    '금요일 → 어제 목요일 첫 원소'
);

t_section('recentTrainingDates — 수요일은 지난 목요일이 첫 원소');
$wed = new DateTimeImmutable('2026-04-29 12:00:00', new DateTimeZone('Asia/Seoul')); // Wednesday
t_assert_eq(
    ['2026-04-23','2026-04-16','2026-04-09','2026-04-02'],
    recentTrainingDates($wed, 4, 4),
    '수요일 → 지난 목요일'
);

t_section('recentTrainingDates — 월/연도 경계');
$earlyJan = new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('Asia/Seoul')); // Monday
t_assert_eq(
    ['2026-01-01','2025-12-25','2025-12-18','2025-12-11'],
    recentTrainingDates($earlyJan, 4, 4),
    '연도 넘어가는 경계'
);

t_section('recentTrainingDates — DOW 파라미터(토요일=6)');
$sat = new DateTimeImmutable('2026-05-02 10:00:00', new DateTimeZone('Asia/Seoul')); // Saturday
t_assert_eq(
    ['2026-05-02','2026-04-25','2026-04-18','2026-04-11'],
    recentTrainingDates($sat, 4, 6),
    '토요일 DOW로도 작동'
);

t_section('recentTrainingDates — N=8');
t_assert_eq(8, count(recentTrainingDates($thu, 8, 4)), 'N개수 그대로');

require_once __DIR__ . '/../public_html/includes/coach_team_guard.php';

t_section('coachIsMyMember — 같은 팀이면 true');
$db = getDB();
// 시드: Kel(팀장) — Lulu/Ella/... (같은 팀)
$kelId  = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Kel'")->fetchColumn();
$luluId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Lulu'")->fetchColumn();
$nanaId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Nana'")->fetchColumn();
$hyunId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Hyun'")->fetchColumn();

t_assert_true(coachIsMyMember($db, $kelId, $luluId), 'Kel→Lulu 같은 팀');
t_assert_true(coachIsMyMember($db, $kelId, $kelId),  'Kel→Kel 자기 자신도 true');
t_assert_eq(false, coachIsMyMember($db, $kelId, $hyunId), 'Kel→Hyun(Nana팀) false');
t_assert_eq(false, coachIsMyMember($db, $kelId, 99999),   '존재하지 않는 coach_id false');

t_section('coachIsLeader — 자기 팀장이면 true');
t_assert_true(coachIsLeader($db, $kelId),  'Kel은 팀장');
t_assert_true(coachIsLeader($db, $nanaId), 'Nana는 팀장');
t_assert_eq(false, coachIsLeader($db, $luluId), 'Lulu는 팀장 아님');
t_assert_eq(false, coachIsLeader($db, 99999),   '존재하지 않는 coach_id false');

t_section('coach_team_guard — active 필터');
$kelId  = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Kel'")->fetchColumn();
$luluId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Lulu'")->fetchColumn();
$origStatus = $db->query("SELECT status FROM coaches WHERE id={$kelId}")->fetchColumn();

// Kel을 임시로 inactive 처리
$db->prepare("UPDATE coaches SET status='inactive' WHERE id=?")->execute([$kelId]);
t_assert_eq(false, coachIsLeader($db, $kelId), 'inactive Kel은 팀장 아님');
t_assert_eq(false, coachIsMyMember($db, $kelId, $luluId), 'inactive 팀장은 멤버 관계 아님');
// 복원
$db->prepare("UPDATE coaches SET status=? WHERE id=?")->execute([$origStatus, $kelId]);
t_assert_true(coachIsLeader($db, $kelId), '복원 후 다시 팀장');
