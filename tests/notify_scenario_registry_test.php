<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/notify/scenario_registry.php';

t_section('notifyValidateScenario — description 옵셔널 검증');

$base = [
    'key' => 'k1', 'name' => 'n1',
    'source' => ['type' => 'google_sheet'],
    'template' => ['templateId' => 'T', 'variables' => []],
    'schedule' => '0 0 * * *', 'cooldown_hours' => 0, 'max_attempts' => 0,
];

// 1) description 없음 → 통과
$err = null;
try { notifyValidateScenario($base); } catch (Throwable $e) { $err = $e; }
t_assert_true($err === null, '1) description 없으면 통과');

// 2) description string → 통과
$err = null;
try {
    $def = $base; $def['description'] = '리마인드 시나리오';
    notifyValidateScenario($def);
} catch (Throwable $e) { $err = $e; }
t_assert_true($err === null, '2) description string 통과');

// 3) description 배열 → throw
t_assert_throws(function() use ($base) {
    $def = $base; $def['description'] = ['a', 'b'];
    notifyValidateScenario($def);
}, 'RuntimeException', '3) description 배열이면 throw');

// 4) description 정수 → throw
t_assert_throws(function() use ($base) {
    $def = $base; $def['description'] = 42;
    notifyValidateScenario($def);
}, 'RuntimeException', '4) description 정수면 throw');
