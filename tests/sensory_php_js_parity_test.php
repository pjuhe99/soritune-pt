<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/tests/sensory_meta.php';

/**
 * JS 파일을 텍스트로 파싱해 questions 배열을 PHP 로 복원.
 * `{type:'X', text:'Y'},` 패턴 매칭. text 안에 작은따옴표가 들어있지 않아야 함.
 */
function parseSensoryQuestionsFromJs(string $jsPath): array
{
    $src = file_get_contents($jsPath);
    if ($src === false) throw new RuntimeException("cannot read {$jsPath}");
    $out = [];
    if (preg_match_all(
        '/\{type:\s*\'([^\']+)\',\s*text:\s*\'([^\']+)\'\s*\}/u',
        $src,
        $m,
        PREG_SET_ORDER
    )) {
        foreach ($m as $row) $out[] = ['type' => $row[1], 'text' => $row[2]];
    }
    return $out;
}

t_section('PHP↔JS questions parity');
$jsQs  = parseSensoryQuestionsFromJs(__DIR__ . '/../public_html/me/js/sensory.js');
$phpQs = Sensory::questions();

t_assert_eq(count($phpQs), count($jsQs), "count match (PHP=" . count($phpQs) . ")");
$mismatch = 0;
$max = min(count($phpQs), count($jsQs));
for ($i = 0; $i < $max; $i++) {
    if ($phpQs[$i]['type'] !== $jsQs[$i]['type'] || $phpQs[$i]['text'] !== $jsQs[$i]['text']) {
        $mismatch++;
        echo "  DIFF[{$i}] PHP=" . json_encode($phpQs[$i], JSON_UNESCAPED_UNICODE)
             . " JS=" . json_encode($jsQs[$i], JSON_UNESCAPED_UNICODE) . "\n";
    }
}
t_assert_eq(0, $mismatch, 'all questions identical (type+text)');
