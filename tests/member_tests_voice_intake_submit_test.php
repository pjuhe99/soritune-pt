<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';
require_once __DIR__ . '/../public_html/includes/tests/voice_intake_meta.php';
require_once __DIR__ . '/../public_html/api/member_tests.php';

function _seed_vi_member(PDO $db): int
{
    $db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
       ->execute(['m_vi_' . uniqid(), '테스트회원', '01000000000']);
    return (int)$db->lastInsertId();
}

function _vi_valid_input(): array {
    return [
        'test_type' => 'voice_intake',
        'answers' => [
            'q1'  => ['value' => '여성'],
            'q2'  => ['value' => '30대'],
            'q3'  => ['value' => '국내'],
            'q4'  => ['value' => '영어를 유창하게 하고 싶어서.'],
            'q5'  => ['value' => '1시간~2시간'],
            'q6'  => ['value' => '저녁(18시~0시)'],
            'q7'  => ['value' => '바쁜 일상으로 훈련 시간 부족'],
            'q8'  => ['value' => '꾸준히 하는 훈련 습관 형성'],
            'q9'  => ['value' => '타이트하게 끌어주는 코치'],
            'q10' => ['values' => ['목소리가 잘 쉰다.']],
            'q11' => ['value' => '그렇다'],
        ],
    ];
}

t_section('memberTestsSubmitImpl — voice_intake 정상 submit');
$db = getDB();
$db->beginTransaction();
$mid = _seed_vi_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$res = memberTestsSubmitImpl($db, $user, _vi_valid_input());

t_assert_true(isset($res['result_id']), 'result_id present');
t_assert_eq(1, $res['result_data']['version'], 'version=1');
t_assert_eq('여성', $res['result_data']['answers']['q1']['value'], 'q1=여성');

$row = $db->prepare("SELECT * FROM test_results WHERE id = ?");
$row->execute([$res['result_id']]);
$saved = $row->fetch(PDO::FETCH_ASSOC);
t_assert_eq($mid, (int)$saved['member_id'], 'member_id matches');
t_assert_eq('voice_intake', $saved['test_type'], 'test_type=voice_intake');
$db->rollBack();

t_section('memberTestsSubmitImpl — voice_intake Q4 기타 + other');
$db->beginTransaction();
$mid = _seed_vi_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$input = _vi_valid_input();
$input['answers']['q4'] = ['value' => '기타', 'other' => '특정 자격시험 대비'];
$res = memberTestsSubmitImpl($db, $user, $input);
t_assert_eq('기타', $res['result_data']['answers']['q4']['value'], 'q4=기타');
t_assert_eq('특정 자격시험 대비', $res['result_data']['answers']['q4']['other'], 'q4 other 저장됨');
$db->rollBack();

t_section('memberTestsSubmitImpl — voice_intake Q4 기타 + other 누락 → 400');
$db->beginTransaction();
$mid = _seed_vi_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$input = _vi_valid_input();
$input['answers']['q4'] = ['value' => '기타'];
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, $input),
    InvalidArgumentException::class,
    'q4 기타 + other 누락 throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — voice_intake Q10 해당없음 + 다른 항목 → 400');
$db->beginTransaction();
$mid = _seed_vi_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$input = _vi_valid_input();
$input['answers']['q10'] = ['values' => ['해당없음', '목소리가 잘 쉰다.']];
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, $input),
    InvalidArgumentException::class,
    '상호 배타 throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — voice_intake 위조 응답 무시 (서버 재검증)');
$db->beginTransaction();
$mid = _seed_vi_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$input = _vi_valid_input();
$input['version'] = 999;        // 무의미한 위조
$input['fake_score'] = 100;     // 무의미한 위조
$res = memberTestsSubmitImpl($db, $user, $input);
t_assert_eq(1, $res['result_data']['version'], 'version 서버 결정 = 1');
t_assert_true(!isset($res['result_data']['fake_score']), 'fake_score 무시');
$db->rollBack();

t_section('memberTestsSubmitImpl — sensory/disc 회귀');
$db->beginTransaction();
$mid = _seed_vi_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$res = memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 48, 0)]);
t_assert_eq('0,0,0', $res['result_data']['key'], 'sensory all-0 key');
$res = memberTestsSubmitImpl($db, $user, ['test_type' => 'disc', 'answers' => array_fill(0, 10, [4,3,2,1])]);
t_assert_eq('D', $res['result_data']['primary'], 'disc primary=D');
$db->rollBack();

t_section('memberTestsLatestImpl — voice_intake 화이트리스트');
$db->beginTransaction();
$mid = _seed_vi_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$res = memberTestsLatestImpl($db, $user, 'voice_intake');
t_assert_eq(null, $res['result'], '미응시 → null');

$db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at) VALUES (?, ?, ?, ?)")
   ->execute([$mid, 'voice_intake', json_encode(['version'=>1,'answers'=>['q1'=>['value'=>'여성']]]), '2026-05-08']);
$res = memberTestsLatestImpl($db, $user, 'voice_intake');
t_assert_true($res['result'] !== null, 'voice_intake 응시 후 latest 반환');
t_assert_eq('여성', $res['result']['result_data']['answers']['q1']['value'], 'q1=여성');
$db->rollBack();
