<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/tests/voice_intake_meta.php';

/**
 * JS 파일을 텍스트로 파싱해 questions 배열을 PHP 로 복원.
 * 패턴은 한 항목씩 매칭:
 *   {id:'qN', type:'single|multi', allow_other:true|false, section:N, short_label:'...', text:'...', options:['...','...']}
 */
function parseVoiceIntakeQuestionsFromJs(string $jsPath): array
{
    $src = file_get_contents($jsPath);
    if ($src === false) throw new RuntimeException("cannot read {$jsPath}");

    $out = [];
    $pattern = '/\{id:\'([^\']+)\',\s*type:\'([^\']+)\',\s*allow_other:(true|false),\s*section:(\d+),\s*short_label:\'([^\']*)\',\s*text:\'([^\']+)\',\s*options:\[([^\]]+)\]\}/u';
    if (preg_match_all($pattern, $src, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            // options 배열 파싱: 'a','b','c'
            $opts = [];
            if (preg_match_all("/'([^']+)'/u", $m[7], $optMatches)) {
                $opts = $optMatches[1];
            }
            $out[] = [
                'id' => $m[1],
                'type' => $m[2],
                'allow_other' => $m[3] === 'true',
                'section' => (int)$m[4],
                'short_label' => $m[5],
                'text' => $m[6],
                'options' => $opts,
            ];
        }
    }
    return $out;
}

t_section('PHP↔JS voice_intake questions parity');
$jsQs  = parseVoiceIntakeQuestionsFromJs(__DIR__ . '/../public_html/me/js/voice-intake-data.js');
$phpQs = VoiceIntake::questions();

t_assert_eq(count($phpQs), count($jsQs), "count match (PHP=" . count($phpQs) . ")");

$mismatch = 0;
$fields = ['id','type','allow_other','section','short_label','text','options'];
$max = min(count($phpQs), count($jsQs));
for ($i = 0; $i < $max; $i++) {
    foreach ($fields as $f) {
        $phpVal = $phpQs[$i][$f] ?? null;
        $jsVal  = $jsQs[$i][$f]  ?? null;
        if ($phpVal !== $jsVal) {
            $mismatch++;
            echo "  DIFF[{$i}.{$f}] PHP=" . json_encode($phpVal, JSON_UNESCAPED_UNICODE)
                 . " JS=" . json_encode($jsVal, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}
t_assert_eq(0, $mismatch, 'all questions identical (id/type/allow_other/section/short_label/text/options)');
