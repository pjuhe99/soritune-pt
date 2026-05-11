<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/tests/voice_intake_meta.php';

t_section('VoiceIntake::questions');
t_assert_eq(11, count(VoiceIntake::questions()), '11 questions');
$ids = [];
foreach (VoiceIntake::questions() as $q) {
    $ids[] = $q['id'];
    t_assert_true(in_array($q['type'], ['single','multi'], true), "{$q['id']} type valid");
    t_assert_true(in_array($q['section'], [1,2], true), "{$q['id']} section valid");
    t_assert_true(!empty($q['text']), "{$q['id']} text non-empty");
    t_assert_true(!empty($q['short_label']), "{$q['id']} short_label non-empty");
    t_assert_true(is_array($q['options']) && count($q['options']) >= 2, "{$q['id']} options >=2");
}
t_assert_eq(['q1','q2','q3','q4','q5','q6','q7','q8','q9','q10','q11'], $ids, 'ids in order');

// Helper: build a fully-valid raw answers fixture
function _vi_valid(): array {
    return [
        'q1'  => ['value' => '여성'],
        'q2'  => ['value' => '30대'],
        'q3'  => ['value' => '국내'],
        'q4'  => ['value' => '영어를 유창하게 하고 싶어서.'],
        'q5'  => ['value' => '1시간~2시간'],
        'q6'  => ['value' => '저녁(18시~0시)'],
        'q7'  => ['value' => '바쁜 일상으로 훈련 시간 부족'],
        'q8'  => ['value' => '꾸준히 하는 훈련 습관 형성'],
        'q9'  => ['value' => '타이트하게 끌어주는 코치'],
        'q10' => ['values' => ['목소리가 잘 쉰다.','영어공부 꾸준히 해본적이 없다.']],
        'q11' => ['value' => '그렇다'],
    ];
}

t_section('VoiceIntake::validate — 정상 응답');
$r = VoiceIntake::validate(_vi_valid());
t_assert_eq(1, $r['version'], 'version=1');
t_assert_eq('여성', $r['answers']['q1']['value'], 'q1 value');
t_assert_eq(2, count($r['answers']['q10']['values']), 'q10 values count');

t_section('VoiceIntake::validate — Q1 누락');
$bad = _vi_valid(); unset($bad['q1']);
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q1 누락 throws'
);

t_section('VoiceIntake::validate — Q1 옵션 외 값');
$bad = _vi_valid(); $bad['q1']['value'] = '기타';
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q1=기타 throws (옵션에 없음)'
);

t_section('VoiceIntake::validate — Q4 기타 + other 비어있음');
$bad = _vi_valid(); $bad['q4'] = ['value' => '기타'];
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q4 기타 + other 누락 throws'
);
$bad['q4'] = ['value' => '기타', 'other' => '   '];
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q4 기타 + other 공백만 throws'
);

t_section('VoiceIntake::validate — Q4 기타 + other 200자 초과');
$bad = _vi_valid();
$bad['q4'] = ['value' => '기타', 'other' => str_repeat('가', 201)];
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q4 기타 + other 201자 throws'
);

t_section('VoiceIntake::validate — Q4 기타 + other 200자 정상');
$ok = _vi_valid();
$ok['q4'] = ['value' => '기타', 'other' => str_repeat('가', 200)];
$r = VoiceIntake::validate($ok);
t_assert_eq(str_repeat('가', 200), $r['answers']['q4']['other'], 'q4 other 200자 통과');

t_section('VoiceIntake::validate — Q4 비기타 + other 무시');
$ok = _vi_valid();
$ok['q4'] = ['value' => '업무에 필요해서', 'other' => '무시되어야'];
$r = VoiceIntake::validate($ok);
t_assert_true(!isset($r['answers']['q4']['other']), 'q4 비기타 → other 저장 안 됨');

t_section('VoiceIntake::validate — Q10 빈 배열');
$bad = _vi_valid(); $bad['q10'] = ['values' => []];
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q10 빈 배열 throws'
);

t_section('VoiceIntake::validate — Q10 옵션 외 값');
$bad = _vi_valid(); $bad['q10'] = ['values' => ['옵션에 없는 값']];
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q10 옵션 외 throws'
);

t_section('VoiceIntake::validate — Q10 중복');
$bad = _vi_valid(); $bad['q10'] = ['values' => ['목소리가 잘 쉰다.','목소리가 잘 쉰다.']];
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q10 중복 throws'
);

t_section('VoiceIntake::validate — Q10 해당없음 + 다른 항목 (상호 배타)');
$bad = _vi_valid();
$bad['q10'] = ['values' => ['해당없음', '목소리가 잘 쉰다.']];
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q10 해당없음 + 다른 항목 throws'
);

t_section('VoiceIntake::validate — Q10 해당없음 단독');
$ok = _vi_valid(); $ok['q10'] = ['values' => ['해당없음']];
$r = VoiceIntake::validate($ok);
t_assert_eq(['해당없음'], $r['answers']['q10']['values'], 'q10 해당없음 단독 OK');

t_section('VoiceIntake::validate — Q11 옵션 외 값');
$bad = _vi_valid(); $bad['q11']['value'] = '잘 모르겠다';
t_assert_throws(
    fn() => VoiceIntake::validate($bad),
    InvalidArgumentException::class,
    'q11 옵션 외 throws'
);
