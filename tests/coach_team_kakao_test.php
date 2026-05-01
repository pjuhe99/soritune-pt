<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/coach_team.php';

t_section('normalizeKakaoRoomUrl — null/empty 정규화');
t_assert_eq(null, normalizeKakaoRoomUrl(null), 'null → null');
t_assert_eq(null, normalizeKakaoRoomUrl(''), '"" → null');
t_assert_eq(null, normalizeKakaoRoomUrl('   '), '공백만 → null');

t_section('normalizeKakaoRoomUrl — 정상 URL 통과');
t_assert_eq(
    'https://open.kakao.com/o/sz1en1ag',
    normalizeKakaoRoomUrl('https://open.kakao.com/o/sz1en1ag'),
    'open.kakao.com/o/...'
);
t_assert_eq(
    'https://open.kakao.com/me/raina',
    normalizeKakaoRoomUrl('https://open.kakao.com/me/raina'),
    'open.kakao.com/me/...'
);
t_assert_eq(
    'https://open.kakao.com/me/Coach_Tess',
    normalizeKakaoRoomUrl('https://open.kakao.com/me/Coach_Tess'),
    'open.kakao.com/me/... with underscore'
);
t_assert_eq(
    'https://open.kakao.com/o/sBcGGboi',
    normalizeKakaoRoomUrl('  https://open.kakao.com/o/sBcGGboi  '),
    '앞뒤 공백은 trim'
);

t_section('normalizeKakaoRoomUrl — 잘못된 URL 거부');
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('http://open.kakao.com/o/abc'),
    InvalidArgumentException::class,
    'http:// 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('https://kakao.com/o/abc'),
    InvalidArgumentException::class,
    'open. 누락 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('https://open.kakao.com/x/abc'),
    InvalidArgumentException::class,
    '/o/ 또는 /me/ 외 path 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('https://open.kakao.com/o/<script>'),
    InvalidArgumentException::class,
    '특수문자 거부'
);
t_assert_throws(
    fn() => normalizeKakaoRoomUrl('javascript:alert(1)'),
    InvalidArgumentException::class,
    'javascript: 스킴 거부'
);

require_once __DIR__ . '/../public_html/includes/db.php';

$db = getDB();

// Helper: 임시 코치 생성 (transaction 안에서만 사용)
function t_make_coach(PDO $db, array $opts = []): int {
    $opts = array_merge([
        'coach_name'    => 'TC_' . uniqid(),
        'login_id'      => 'tc_' . uniqid(),
        'status'        => 'active',
        'team_leader_id'=> null,
    ], $opts);
    $db->prepare("
        INSERT INTO coaches (login_id, password_hash, coach_name, status, team_leader_id)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $opts['login_id'],
        password_hash('x', PASSWORD_BCRYPT),
        $opts['coach_name'],
        $opts['status'],
        $opts['team_leader_id'],
    ]);
    return (int)$db->lastInsertId();
}

t_section('validateTeamLeaderId — 통과 케이스');

$db->beginTransaction();
$leader = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leader]);
$member = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leader]);

validateTeamLeaderId($db, $member, null);  // null 통과
t_assert_true(true, 'null leader id는 통과');

validateTeamLeaderId($db, $leader, $leader);  // self 통과
t_assert_true(true, 'self leader id는 통과');

validateTeamLeaderId($db, $member, $leader);  // 정상 active 팀장 통과
t_assert_true(true, '정상 active 팀장은 통과');
$db->rollBack();

t_section('validateTeamLeaderId — 거부 케이스');

$db->beginTransaction();
$inactiveLeader = t_make_coach($db, ['status' => 'inactive']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$inactiveLeader]);
$other = t_make_coach($db, ['status' => 'active']);
t_assert_throws(
    fn() => validateTeamLeaderId($db, $other, $inactiveLeader),
    InvalidArgumentException::class,
    'inactive 팀장 거부'
);
$db->rollBack();

$db->beginTransaction();
$nonLeader = t_make_coach($db, ['status' => 'active', 'team_leader_id' => null]);
$other = t_make_coach($db, ['status' => 'active']);
t_assert_throws(
    fn() => validateTeamLeaderId($db, $other, $nonLeader),
    InvalidArgumentException::class,
    '팀장이 아닌(team_leader_id=NULL) 코치 거부'
);
$db->rollBack();

$db->beginTransaction();
$leaderA = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leaderA]);
// 팀원 코치를 leaderId로 지정 시도
$memberOfA = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leaderA]);
$other = t_make_coach($db, ['status' => 'active']);
t_assert_throws(
    fn() => validateTeamLeaderId($db, $other, $memberOfA),
    InvalidArgumentException::class,
    '팀장이 아닌(다른 사람의 팀원) 코치 거부'
);
$db->rollBack();

t_section('countTeamMembers — 본인 제외');

$db->beginTransaction();
$leader = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leader]);
t_assert_eq(0, countTeamMembers($db, $leader), '본인만 있는 팀 = 0명');

$m1 = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leader]);
$m2 = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leader]);
t_assert_eq(2, countTeamMembers($db, $leader), '팀원 2명');
$db->rollBack();

t_section('assertCanModifyLeader — 팀원 0명이면 통과');

$db->beginTransaction();
$leader = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leader]);
foreach (['inactive', 'unset_leader', 'delete'] as $action) {
    assertCanModifyLeader($db, $leader, $action);
    t_assert_true(true, "팀원 0명 + action={$action} 통과");
}
$db->rollBack();

t_section('assertCanModifyLeader — 팀원 있으면 차단');

$db->beginTransaction();
$leader = t_make_coach($db, ['status' => 'active']);
$db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$leader]);
$m = t_make_coach($db, ['status' => 'active', 'team_leader_id' => $leader]);
foreach (['inactive', 'unset_leader', 'delete'] as $action) {
    t_assert_throws(
        fn() => assertCanModifyLeader($db, $leader, $action),
        RuntimeException::class,
        "팀원 1명 + action={$action} 차단"
    );
}
$db->rollBack();

t_section('시드 데이터 정합성');

// 팀장 3명이 모두 self-ref
$leaders = $db->query("
    SELECT coach_name FROM coaches
    WHERE team_leader_id = id AND status = 'active'
    ORDER BY coach_name
")->fetchAll(PDO::FETCH_COLUMN);
t_assert_eq(['Flora', 'Kel', 'Nana'], $leaders, '팀장 3명 = Flora/Kel/Nana');

// 각 팀별 멤버 수 (본인 포함하면 10명씩)
$counts = $db->query("
    SELECT l.coach_name, COUNT(*) AS n
    FROM coaches c JOIN coaches l ON l.id = c.team_leader_id
    WHERE c.status='active'
    GROUP BY l.id, l.coach_name
    ORDER BY l.coach_name
")->fetchAll(PDO::FETCH_KEY_PAIR);
t_assert_eq(10, (int)$counts['Kel'], 'Kel팀 10명 (본인 포함)');
t_assert_eq(10, (int)$counts['Nana'], 'Nana팀 10명');
t_assert_eq(10, (int)$counts['Flora'], 'Flora팀 10명');

// active 코치 30명 모두 팀 배정
$unassigned = $db->query("
    SELECT COUNT(*) FROM coaches WHERE status='active' AND team_leader_id IS NULL
")->fetchColumn();
t_assert_eq(0, (int)$unassigned, 'active 미배정 코치 0명');

// 카톡방 28명 (active only — 시드는 active 코치 한정)
$withKakao = (int)$db->query("
    SELECT COUNT(*) FROM coaches WHERE status='active' AND kakao_room_url IS NOT NULL
")->fetchColumn();
t_assert_eq(28, $withKakao, 'active + kakao_room_url 보유 28명');

// 카톡방 미설정 active = Frida, Jenny
$noKakao = $db->query("
    SELECT coach_name FROM coaches
    WHERE status='active' AND kakao_room_url IS NULL ORDER BY coach_name
")->fetchAll(PDO::FETCH_COLUMN);
t_assert_eq(['Frida', 'Jenny'], $noKakao, '카톡방 미설정은 Frida, Jenny');

// 모든 카톡방 URL이 정규식 통과
$urls = $db->query("SELECT kakao_room_url FROM coaches WHERE kakao_room_url IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$invalid = [];
foreach ($urls as $u) {
    try { normalizeKakaoRoomUrl($u); }
    catch (InvalidArgumentException $e) { $invalid[] = $u; }
}
$label = empty($invalid)
    ? '시드 카톡방 URL 모두 정규식 통과'
    : '시드 카톡방 URL 정규식 실패: ' . $invalid[0];
t_assert_eq([], $invalid, $label);
