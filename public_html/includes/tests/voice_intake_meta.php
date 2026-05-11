<?php
declare(strict_types=1);

/**
 * Voice Intake survey meta — 단일 source of truth (PHP 측).
 * JS 측: public_html/me/js/voice-intake-data.js
 * 동기화 가드: tests/voice_intake_php_js_parity_test.php
 *
 * 변경 이력:
 *   v1 (2026-05-08): 사용자 제공 11문항 + 2섹션 구성 v1
 */

final class VoiceIntake
{
    public const VERSION = 1;
    public const OTHER_MAX_LEN = 200;
    public const Q10_NONE = '해당없음';

    /**
     * 11 questions. 각 항목:
     *   id          : 'q1'..'q11'
     *   text        : 원본 질문문
     *   short_label : 어드민 차트용 짧은 라벨 (참고용 — JS 측 카드에서도 사용)
     *   type        : 'single' | 'multi'
     *   allow_other : true 일 때 'value' === '기타' 면 'other' 텍스트 필수
     *   section     : 1 (Q1~Q6 기본정보) 또는 2 (Q7~Q11 훈련방향)
     *   options     : string[] — 옵션 목록 (string equality 로 검증)
     */
    public static function questions(): array
    {
        return [
            [
                'id' => 'q1', 'type' => 'single', 'allow_other' => false, 'section' => 1,
                'short_label' => '성별',
                'text' => '성별을 알려주세요.',
                'options' => ['여성', '남성'],
            ],
            [
                'id' => 'q2', 'type' => 'single', 'allow_other' => true, 'section' => 1,
                'short_label' => '연령대',
                'text' => '연령대를 알려주세요.',
                'options' => ['10대','20대','30대','40대','50대','60대','70대 이상','기타'],
            ],
            [
                'id' => 'q3', 'type' => 'single', 'allow_other' => true, 'section' => 1,
                'short_label' => '거주지역',
                'text' => '거주지역은 어디신가요?',
                'options' => ['국내','해외','기타'],
            ],
            [
                'id' => 'q4', 'type' => 'single', 'allow_other' => true, 'section' => 1,
                'short_label' => '학습 목표',
                'text' => '소리튠 영어를 하는 목표를 알려주세요.',
                'options' => [
                    '승진이나 취업을 위해서','업무에 필요해서','유학을 위해서','해외여행을 위해서',
                    '영어를 유창하게 하고 싶어서.','일상에서의 원활한 의사소통을 위해서',
                    '자연스러운 영어소리와 리스닝 향상을 위해서','영화나 미드를 자막없이 보기 위해서',
                    '자기 만족& 자신감을 위해서','기타',
                ],
            ],
            [
                'id' => 'q5', 'type' => 'single', 'allow_other' => true, 'section' => 1,
                'short_label' => '하루 투자 시간',
                'text' => '소리튠영어 훈련하는데 하루에 투자할 수 있는 시간을 알려주세요.',
                'options' => ['30분 이하','30분~1시간','1시간~2시간','2시간~3시간','3시간~4시간','4시간 이상','기타'],
            ],
            [
                'id' => 'q6', 'type' => 'single', 'allow_other' => true, 'section' => 1,
                'short_label' => '훈련 시간대',
                'text' => '주로 훈련하는 시간대를 알려주세요. (한국시간 기준)',
                'options' => ['오전(6시~12시)','오후(12시~18시)','저녁(18시~0시)','새벽(0시~6시)','기타'],
            ],
            [
                'id' => 'q7', 'type' => 'single', 'allow_other' => true, 'section' => 2,
                'short_label' => '지속 어려움',
                'text' => '그동안 영어 공부를 지속하기 어려웠던 상황은 무엇인가요.',
                'options' => ['낮은 영어 훈련 의지','불규칙한 생활 패턴','바쁜 일상으로 훈련 시간 부족','나에게 맞는 훈련법이 없음','기타'],
            ],
            [
                'id' => 'q8', 'type' => 'single', 'allow_other' => true, 'section' => 2,
                'short_label' => '코칭 도움',
                'text' => '음성코칭서비스를 통해 어떤 도움을 받고 싶나요.',
                'options' => ['꾸준히 하는 훈련 습관 형성','함께 한다는 심리적 지지','끝까지 완주하는 성취감','코치와의 소통','기타'],
            ],
            [
                'id' => 'q9', 'type' => 'single', 'allow_other' => true, 'section' => 2,
                'short_label' => '코치 스타일',
                'text' => '원하시는 코치 스타일을 알려주세요.',
                'options' => ['타이트하게 끌어주는 코치','상냥하게 밀어주는 코치','상관없음','기타'],
            ],
            [
                'id' => 'q10', 'type' => 'multi', 'allow_other' => false, 'section' => 2,
                'short_label' => '해당 사항',
                'text' => '해당 사항에 체크해주세요. (복수체크 가능)',
                'options' => [
                    '목소리가 잘 쉰다.',
                    '한국어 딕션이 명확하지 않다.',
                    '매일 1시간이상 영어훈련에 투자하기 어렵다.',
                    '영어공부 꾸준히 해본적이 없다.',
                    '음치박치다.',
                    '듣고 이해하는 것보다 글을 읽고 이해하는 게 빠르다.',
                    '해외 여행시 영어가 두렵다.',
                    '말을 할 때 조음기관(입, 혀, 턱)의 움직임이 크지 않다.',
                    '해당없음',
                ],
            ],
            [
                'id' => 'q11', 'type' => 'single', 'allow_other' => false, 'section' => 2,
                'short_label' => '자기개방 편안함',
                'text' => '자신의 이야기를 편안하게 나눌 수 있나요?',
                'options' => ['매우 그렇다','그렇다','그렇지않다','매우그렇지않다'],
            ],
        ];
    }

    /**
     * 응답 검증 + result_data 빌드.
     *
     * @param array $rawAnswers ['q1' => ['value' => ...], 'q10' => ['values' => [...]], ...]
     * @return array result_data v1 (version, answers, submitted_at)
     * @throws InvalidArgumentException
     */
    public static function validate(array $rawAnswers): array
    {
        $questions = self::questions();
        $cleanAnswers = [];

        foreach ($questions as $q) {
            $id = $q['id'];
            if (!isset($rawAnswers[$id]) || !is_array($rawAnswers[$id])) {
                throw new InvalidArgumentException("answers[{$id}] missing or not an array");
            }
            $a = $rawAnswers[$id];

            if ($q['type'] === 'single') {
                $value = $a['value'] ?? null;
                if (!is_string($value)) {
                    throw new InvalidArgumentException("answers[{$id}].value must be string");
                }
                if (!in_array($value, $q['options'], true)) {
                    throw new InvalidArgumentException("answers[{$id}].value not in options: {$value}");
                }
                $entry = ['value' => $value];

                if ($q['allow_other'] && $value === '기타') {
                    $other = isset($a['other']) ? trim((string)$a['other']) : '';
                    if ($other === '') {
                        throw new InvalidArgumentException("answers[{$id}].other required when '기타'");
                    }
                    if (mb_strlen($other) > self::OTHER_MAX_LEN) {
                        throw new InvalidArgumentException(
                            "answers[{$id}].other exceeds " . self::OTHER_MAX_LEN . " chars"
                        );
                    }
                    $entry['other'] = $other;
                }
                $cleanAnswers[$id] = $entry;
                continue;
            }

            // multi (Q10)
            $values = $a['values'] ?? null;
            if (!is_array($values) || count($values) === 0) {
                throw new InvalidArgumentException("answers[{$id}].values must be non-empty array");
            }
            // 중복 제거 후 검증, 중복은 입력 오류로 throw
            if (count($values) !== count(array_unique($values))) {
                throw new InvalidArgumentException("answers[{$id}].values has duplicates");
            }
            foreach ($values as $v) {
                if (!is_string($v) || !in_array($v, $q['options'], true)) {
                    throw new InvalidArgumentException("answers[{$id}].values has invalid option: " . var_export($v, true));
                }
            }
            // 상호 배타: '해당없음' + 다른 항목 동시 선택 거절 (Q10 만 적용)
            if ($id === 'q10' && in_array(self::Q10_NONE, $values, true) && count($values) > 1) {
                throw new InvalidArgumentException("answers[q10] '해당없음' cannot be combined with other options");
            }
            $cleanAnswers[$id] = ['values' => array_values($values)];
        }

        return [
            'version'      => self::VERSION,
            'answers'      => $cleanAnswers,
            'submitted_at' => date('c'),
        ];
    }
}
