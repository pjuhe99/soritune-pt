<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/tests/disc_meta.php';

t_section('Disc::questions');
t_assert_eq(10, count(Disc::questions()), '10 questions');
foreach (Disc::questions() as $i => $q) {
    foreach (['D','I','S','C'] as $t) {
        t_assert_true(!empty($q[$t]), "Q{$i} has {$t}");
    }
}

t_section('Disc::categories — 4 keys');
$cats = Disc::categories();
t_assert_eq(4, count($cats), '4 categories');
foreach (['D','I','S','C'] as $k) {
    t_assert_true(isset($cats[$k]), "key {$k}");
    t_assert_true(!empty($cats[$k]['title']), "title {$k}");
    t_assert_true(!empty($cats[$k]['subtitle']), "subtitle {$k}");
    t_assert_true(!empty($cats[$k]['keywords']), "keywords {$k}");
}

t_section('Disc::score — 모든 D=4 (D=40)');
$answers = array_fill(0, 10, [4, 3, 2, 1]);
$r = Disc::score($answers);
t_assert_eq(1, $r['version'], 'version=1');
t_assert_eq(40, $r['scores']['D'], 'D=40');
t_assert_eq(30, $r['scores']['I'], 'I=30');
t_assert_eq(20, $r['scores']['S'], 'S=20');
t_assert_eq(10, $r['scores']['C'], 'C=10');
t_assert_eq('D', $r['primary'], 'primary=D');
t_assert_eq('주도형', $r['title'], 'title');
t_assert_eq(1, $r['ranks'][0]['rank'], 'rank 1 = D');
t_assert_eq('D', $r['ranks'][0]['type'], 'top type = D');
t_assert_eq(4, $r['ranks'][3]['rank'], 'rank 4 = C');

t_section('Disc::score — 모든 C=4 (C=40)');
$r = Disc::score(array_fill(0, 10, [1, 2, 3, 4]));
t_assert_eq('C', $r['primary'], 'primary=C');
t_assert_eq('신중형', $r['title'], 'title');
t_assert_eq(40, $r['scores']['C'], 'C=40');

t_section('Disc::score — D/I 동점 → primary=D (D>I 우선)');
$answers = [];
for ($i = 0; $i < 5; $i++) $answers[] = [4, 3, 2, 1];
for ($i = 0; $i < 5; $i++) $answers[] = [3, 4, 2, 1];
$r = Disc::score($answers);
t_assert_eq(35, $r['scores']['D'], 'D=35');
t_assert_eq(35, $r['scores']['I'], 'I=35');
t_assert_eq('D', $r['primary'], 'D>I 동점 → primary=D');
t_assert_eq(1, $r['ranks'][0]['rank'], '1순위');
t_assert_eq(1, $r['ranks'][1]['rank'], '동점 1순위 (공동)');

t_section('Disc::score — S/C 동점 (3순위 공동)');
$answers = [];
for ($i = 0; $i < 5; $i++) $answers[] = [4, 3, 2, 1];
for ($i = 0; $i < 5; $i++) $answers[] = [4, 3, 1, 2];
$r = Disc::score($answers);
t_assert_eq(40, $r['scores']['D'], 'D=40');
t_assert_eq(30, $r['scores']['I'], 'I=30');
t_assert_eq(15, $r['scores']['S'], 'S=15');
t_assert_eq(15, $r['scores']['C'], 'C=15');
t_assert_eq('D', $r['primary'], 'primary=D');
$rankByType = [];
foreach ($r['ranks'] as $row) $rankByType[$row['type']] = $row['rank'];
t_assert_eq(1, $rankByType['D'], 'D rank=1');
t_assert_eq(2, $rankByType['I'], 'I rank=2');
t_assert_eq(3, $rankByType['S'], 'S rank=3 (S/C 동점)');
t_assert_eq(3, $rankByType['C'], 'C rank=3 (S/C 동점)');

t_section('Disc::score — 길이 검증');
t_assert_throws(
    fn() => Disc::score(array_fill(0, 9, [4,3,2,1])),
    InvalidArgumentException::class,
    'length=9 throws'
);
t_assert_throws(
    fn() => Disc::score(array_fill(0, 11, [4,3,2,1])),
    InvalidArgumentException::class,
    'length=11 throws'
);

t_section('Disc::score — inner 길이 검증');
$bad = array_fill(0, 10, [4, 3, 2, 1]);
$bad[3] = [4, 3, 2];
t_assert_throws(
    fn() => Disc::score($bad),
    InvalidArgumentException::class,
    'inner length 3 throws'
);

t_section('Disc::score — inner 순열 아님 (중복)');
$bad = array_fill(0, 10, [4, 3, 2, 1]);
$bad[5] = [4, 4, 2, 1];
t_assert_throws(
    fn() => Disc::score($bad),
    InvalidArgumentException::class,
    'inner [4,4,2,1] throws (duplicate)'
);

t_section('Disc::score — inner 값 범위 (0 또는 5)');
$bad = array_fill(0, 10, [4, 3, 2, 1]);
$bad[0] = [4, 3, 2, 0];
t_assert_throws(
    fn() => Disc::score($bad),
    InvalidArgumentException::class,
    'inner [4,3,2,0] throws (0 not in {1,2,3,4})'
);
$bad[0] = [5, 3, 2, 1];
t_assert_throws(
    fn() => Disc::score($bad),
    InvalidArgumentException::class,
    'inner [5,3,2,1] throws (5 not in {1,2,3,4})'
);
