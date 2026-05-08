<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';
require_once __DIR__ . '/../public_html/includes/tests/sensory_meta.php';
require_once __DIR__ . '/../public_html/api/member_tests.php';

function _seed_member(PDO $db, string $sori = 'm_test'): int
{
    $db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
       ->execute([$sori . '_' . uniqid(), '테스트회원', '01000000000']);
    return (int)$db->lastInsertId();
}

t_section('memberTestsSubmitImpl — 정상 sensory submit');
$db = getDB();
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$answers = array_fill(0, 48, 0);
$res = memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => $answers]);

t_assert_true(isset($res['result_id']), 'result_id present');
t_assert_eq(1, $res['result_data']['version'], 'version=1');
t_assert_eq('0,0,0', $res['result_data']['key'], 'all-0 → key=0,0,0');
t_assert_eq('균형형', $res['result_data']['title'], 'title=균형형');

$row = $db->prepare("SELECT * FROM test_results WHERE id = ?");
$row->execute([$res['result_id']]);
$saved = $row->fetch(PDO::FETCH_ASSOC);
t_assert_eq($mid, (int)$saved['member_id'], 'member_id matches session');
t_assert_eq('sensory', $saved['test_type'], 'test_type=sensory');
$db->rollBack();

t_section('memberTestsSubmitImpl — 위조한 key 무시');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$res = memberTestsSubmitImpl($db, $user, [
    'test_type' => 'sensory',
    'answers'   => array_fill(0, 48, 0),
    'key'       => '1,1,1',
    'title'     => '완전한 멀티 감각형 학습자',
]);
t_assert_eq('0,0,0', $res['result_data']['key'], '위조 key 무시 → 서버 재계산');
$db->rollBack();

t_section('memberTestsSubmitImpl — 길이 검증');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 47, 0)]),
    InvalidArgumentException::class,
    'length=47 throws'
);
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 49, 0)]),
    InvalidArgumentException::class,
    'length=49 throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — 값 검증');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$bad = array_fill(0, 48, 0);
$bad[10] = 2;
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => $bad]),
    InvalidArgumentException::class,
    'value=2 throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — test_type 화이트리스트');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'unknown', 'answers' => array_fill(0, 48, 0)]),
    InvalidArgumentException::class,
    'unknown test_type throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — 같은 회원 같은 날 두 번 → 두 row');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$r1 = memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 48, 0)]);
$r2 = memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 48, 1)]);
t_assert_true($r2['result_id'] > $r1['result_id'], 'second id > first');

$cnt = $db->prepare("SELECT COUNT(*) FROM test_results WHERE member_id = ? AND test_type = 'sensory'");
$cnt->execute([$mid]);
t_assert_eq(2, (int)$cnt->fetchColumn(), '두 row 존재');
$db->rollBack();
