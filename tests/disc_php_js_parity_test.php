<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/tests/disc_meta.php';

/**
 * JS 파일을 텍스트로 파싱해 questions 배열을 PHP 로 복원.
 * 패턴: {D:'...', I:'...', S:'...', C:'...'}
 * 각 단어에 single quote 가 들어있지 않아야 함 — 본 데이터셋 충족.
 */
function parseDiscQuestionsFromJs(string $jsPath): array
{
    $src = file_get_contents($jsPath);
    if ($src === false) throw new RuntimeException("cannot read {$jsPath}");

    $out = [];
    if (preg_match_all(
        '/\{D:\s*\'([^\']+)\',\s*I:\s*\'([^\']+)\',\s*S:\s*\'([^\']+)\',\s*C:\s*\'([^\']+)\'\s*\}/u',
        $src,
        $m,
        PREG_SET_ORDER
    )) {
        foreach ($m as $row) {
            $out[] = ['D' => $row[1], 'I' => $row[2], 'S' => $row[3], 'C' => $row[4]];
        }
    }
    return $out;
}

t_section('PHP↔JS DISC questions parity');
$jsQs  = parseDiscQuestionsFromJs(__DIR__ . '/../public_html/me/js/disc.js');
$phpQs = Disc::questions();

t_assert_eq(count($phpQs), count($jsQs), "count match (PHP=" . count($phpQs) . ")");
$mismatch = 0;
$max = min(count($phpQs), count($jsQs));
for ($i = 0; $i < $max; $i++) {
    foreach (['D','I','S','C'] as $t) {
        if (($phpQs[$i][$t] ?? '') !== ($jsQs[$i][$t] ?? '')) {
            $mismatch++;
            echo "  DIFF[{$i}.{$t}] PHP=" . json_encode($phpQs[$i][$t], JSON_UNESCAPED_UNICODE)
                 . " JS=" . json_encode($jsQs[$i][$t], JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}
t_assert_eq(0, $mismatch, 'all questions identical (D/I/S/C)');
