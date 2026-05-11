<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/tests/sensory_meta.php';

t_section('Sensory::questions');
t_assert_eq(48, count(Sensory::questions()), '48 questions');

$typeCounts = ['청각형' => 0, '시각형' => 0, '체각형' => 0];
foreach (Sensory::questions() as $q) {
    t_assert_true(in_array($q['type'], ['청각형','시각형','체각형'], true), "type valid: {$q['type']}");
    $typeCounts[$q['type']]++;
}
t_assert_eq(16, $typeCounts['청각형'], '청각형=16');
t_assert_eq(16, $typeCounts['시각형'], '시각형=16');
t_assert_eq(16, $typeCounts['체각형'], '체각형=16');

t_section('Sensory::categories — 8 keys');
$cats = Sensory::categories();
t_assert_eq(8, count($cats), '8 categories');
foreach (['0,0,0','0,0,1','0,1,0','0,1,1','1,0,0','1,0,1','1,1,0','1,1,1'] as $k) {
    t_assert_true(isset($cats[$k]), "key {$k}");
    t_assert_true(!empty($cats[$k]['title']), "title {$k}");
    t_assert_true(!empty($cats[$k]['subtitle']), "subtitle {$k}");
}

t_section('Sensory::score — 모든 0');
$r = Sensory::score(array_fill(0, 48, 0));
t_assert_eq(1, $r['version'], 'version=1');
t_assert_eq('0,0,0', $r['key'], 'key=0,0,0');
t_assert_eq('균형형', $r['title'], 'title=균형형');
t_assert_eq(0, $r['percents']['auditory'], 'auditory 0%');
t_assert_eq(0, $r['percents']['visual'], 'visual 0%');
t_assert_eq(0, $r['percents']['kinesthetic'], 'kinesthetic 0%');

t_section('Sensory::score — 모든 1');
$r = Sensory::score(array_fill(0, 48, 1));
t_assert_eq('1,1,1', $r['key'], 'key=1,1,1');
t_assert_eq('완전한 멀티 감각형 학습자', $r['title'], 'title=멀티감각');
t_assert_eq(100, $r['percents']['auditory'], 'auditory 100%');

t_section('Sensory::score — 청각만 100%');
$answers = [];
foreach (Sensory::questions() as $q) {
    $answers[] = $q['type'] === '청각형' ? 1 : 0;
}
$r = Sensory::score($answers);
t_assert_eq('1,0,0', $r['key'], 'key=1,0,0 (청각만)');
t_assert_eq('청각형 우세 학습자', $r['title'], 'title');
t_assert_eq(100, $r['percents']['auditory'], 'auditory 100');
t_assert_eq(0, $r['percents']['visual'], 'visual 0');
t_assert_eq(0, $r['percents']['kinesthetic'], 'kinesthetic 0');

t_section('Sensory::score — 50% 경계 (정확히 50% 는 0)');
$answers = [];
$auditoryChecked = 0;
foreach (Sensory::questions() as $q) {
    if ($q['type'] === '청각형') {
        $answers[] = $auditoryChecked < 8 ? 1 : 0;
        $auditoryChecked++;
    } else {
        $answers[] = 0;
    }
}
$r = Sensory::score($answers);
t_assert_eq('0,0,0', $r['key'], '청각 8/16=50% → bit=0 → key=0,0,0');
t_assert_eq(50, $r['percents']['auditory'], 'auditory 50%');

t_section('Sensory::score — 길이 검증');
t_assert_throws(
    fn() => Sensory::score(array_fill(0, 47, 0)),
    InvalidArgumentException::class,
    'length=47 throws'
);
t_assert_throws(
    fn() => Sensory::score(array_fill(0, 49, 0)),
    InvalidArgumentException::class,
    'length=49 throws'
);

t_section('Sensory::score — 값 검증');
$bad = array_fill(0, 48, 0);
$bad[5] = 2;
t_assert_throws(
    fn() => Sensory::score($bad),
    InvalidArgumentException::class,
    'value=2 throws'
);
$bad[5] = -1;
t_assert_throws(
    fn() => Sensory::score($bad),
    InvalidArgumentException::class,
    'value=-1 throws'
);
