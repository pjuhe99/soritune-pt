<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';
require_once __DIR__ . '/../public_html/includes/tests/disc_meta.php';
require_once __DIR__ . '/../public_html/api/member_tests.php';

function _seed_disc_member(PDO $db): int
{
    $db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
       ->execute(['m_disc_' . uniqid(), '테스트회원', '01000000000']);
    return (int)$db->lastInsertId();
}

t_section('memberTestsSubmitImpl — 정상 disc submit');
$db = getDB();
$db->beginTransaction();
$mid = _seed_disc_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$answers = array_fill(0, 10, [4, 3, 2, 1]);
$res = memberTestsSubmitImpl($db, $user, ['test_type' => 'disc', 'answers' => $answers]);

t_assert_true(isset($res['result_id']), 'result_id present');
t_assert_eq(1, $res['result_data']['version'], 'version=1');
t_assert_eq('D', $res['result_data']['primary'], 'primary=D');
t_assert_eq(40, $res['result_data']['scores']['D'], 'D=40');
t_assert_eq('주도형', $res['result_data']['title'], 'title=주도형');

$row = $db->prepare("SELECT * FROM test_results WHERE id = ?");
$row->execute([$res['result_id']]);
$saved = $row->fetch(PDO::FETCH_ASSOC);
t_assert_eq($mid, (int)$saved['member_id'], 'member_id matches');
t_assert_eq('disc', $saved['test_type'], 'test_type=disc');
$db->rollBack();

t_section('memberTestsSubmitImpl — disc 위조한 primary 무시');
$db->beginTransaction();
$mid = _seed_disc_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$res = memberTestsSubmitImpl($db, $user, [
    'test_type' => 'disc',
    'answers'   => array_fill(0, 10, [4, 3, 2, 1]),
    'primary'   => 'C',
    'title'     => '신중형',
]);
t_assert_eq('D', $res['result_data']['primary'], '위조 primary 무시 → 서버 재계산 D');
$db->rollBack();

t_section('memberTestsSubmitImpl — disc inner 순열 검증');
$db->beginTransaction();
$mid = _seed_disc_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$bad = array_fill(0, 10, [4, 3, 2, 1]);
$bad[2] = [4, 4, 2, 1];
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'disc', 'answers' => $bad]),
    InvalidArgumentException::class,
    'duplicate inner throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — disc 길이 검증');
$db->beginTransaction();
$mid = _seed_disc_member($db);
$user = ['id' => $mid, 'role' => 'member'];
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'disc', 'answers' => array_fill(0, 9, [4,3,2,1])]),
    InvalidArgumentException::class,
    'length=9 throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — disc inner 비배열 처리');
$db->beginTransaction();
$mid = _seed_disc_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$bad = array_fill(0, 10, [4, 3, 2, 1]);
$bad[5] = "not an array";
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'disc', 'answers' => $bad]),
    InvalidArgumentException::class,
    'non-array inner throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — sensory 회귀 (변경 없음)');
$db->beginTransaction();
$mid = _seed_disc_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$res = memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 48, 0)]);
t_assert_eq('0,0,0', $res['result_data']['key'], 'sensory all-0 key');
$db->rollBack();
