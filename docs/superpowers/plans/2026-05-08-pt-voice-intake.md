# PT 음성 케어 매칭 사전 질문 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PT 회원이 `/me/` 대시보드에서 음성 설문 카드 → 11문항 2섹션 폼 응답 → result_data 가 본인 차트에 저장되어 코치/어드민이 매칭 시 참조할 수 있게 한다.

**Architecture:** sensory · DISC 인프라(`/me/` SPA · `test_results` 테이블 · `formatTestResult` 차트 카드 · `requireMember` · PHP↔JS parity 가드) 위에 plug-in. 응답 모델 차이(boolean → ranking → 폼/체크박스+기타) 로 `me/js/voice-intake-runner.js` 별도 모듈. 채점 없는 검증만 — `VoiceIntake::validate()` PHP 클래스. test_results ENUM 1회 확장.

**Tech Stack:** PHP 8 (PDO), Vanilla JS SPA, MySQL JSON·ENUM, 자체 PHP 테스트 러너.

**Spec:** `docs/superpowers/specs/2026-05-08-pt-voice-intake-design.md`

**Working dir:** `/root/pt-dev` (DEV_PT, dev 브랜치). 운영 반영은 사용자 명시 요청 시.

---

## Conventions / Useful References

**테스트 실행**
```bash
cd /root/pt-dev && php tests/run_tests.php
```

**기존 헬퍼**
- `tests/_bootstrap.php` — `t_section`, `t_assert_eq`, `t_assert_true`, `t_assert_throws`
- `public_html/includes/helpers.php` — `jsonSuccess`, `jsonError`, `getJsonInput`
- `public_html/includes/auth.php` — `requireMember()`
- `public_html/includes/tests/sensory_meta.php` — `Sensory::score()` 패턴 참조
- `public_html/includes/tests/disc_meta.php` — `Disc::score()` 패턴 참조

**선행 인프라 — 직접 의존**
- `test_results` 테이블 (T1 에서 ENUM 확장)
- `/api/member_tests.php?action=submit` (T2 에서 testType 분기 확장)
- `/api/member_tests.php?action=latest` (`['sensory','disc']` 화이트리스트 → `['sensory','disc','voice_intake']` 로 확장)
- `MeApp.go(...)`, `MeAPI.get/post`, `MeUI.esc/formatDate`
- `formatTestResult(testType, data)` — admin/coach 차트 카드

**기존 테스트 기준선**: 510/510 PASS (DISC 완료 시점). voice_intake 추가 후 +N 신규 + 회귀 0.

**DEV DB 마이그레이션 명령어 (T1 의 마지막 단계에 사용)**
```bash
cd /root/pt-dev && source .db_credentials && \
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  < migrations/20260508_test_results_add_voice_intake.sql
```

---

## File Structure

**Create:**
- `migrations/20260508_test_results_add_voice_intake.sql` — ENUM 확장
- `public_html/includes/tests/voice_intake_meta.php` — 11문항 + `VoiceIntake::validate()`
- `public_html/me/js/voice-intake-data.js` — JS 측 11문항 메타 (parity 가드 대상)
- `public_html/me/js/voice-intake-runner.js` — 2섹션 폼 + 결과 보기 + 감사 화면
- `tests/voice_intake_validate_test.php` — PHP 검증 단위 테스트
- `tests/voice_intake_php_js_parity_test.php` — PHP↔JS 동기화 가드
- `tests/member_tests_voice_intake_submit_test.php` — submit API 테스트

**Modify:**
- `public_html/api/member_tests.php` — submit 의 testType 분기에 voice_intake 추가, latest 화이트리스트 확장
- `public_html/me/index.php` — `<script>` 2개 추가
- `public_html/me/js/app.js` — `render()` 의 `'test'`/`'result'` 분기에 voice_intake 라우팅
- `public_html/me/js/dashboard.js` — `renderVoiceIntakeCard` 추가, `loadCards` 가 voice_intake latest fetch
- `public_html/admin/js/pages/member-chart.js` — `formatTestResult` 의 `isNewVoiceIntake` 분기
- `public_html/coach/js/pages/member-chart.js` — 동일

---

## Task 1: ENUM 마이그 + PHP VoiceIntake 클래스 + 검증 단위 테스트

**Files:**
- Create: `migrations/20260508_test_results_add_voice_intake.sql`
- Create: `public_html/includes/tests/voice_intake_meta.php`
- Create: `tests/voice_intake_validate_test.php`

11문항 메타 + `VoiceIntake::validate(array $answers): array` — sensory/DISC 와 평행 구조.

- [ ] **Step 1: Create migration**

`migrations/20260508_test_results_add_voice_intake.sql`:

```sql
-- 2026-05-08: test_results.test_type ENUM 확장 — voice_intake 추가
-- 기존 row 영향 없음 (ENUM 추가만)
ALTER TABLE test_results
  MODIFY COLUMN test_type ENUM('disc', 'sensory', 'voice_intake') NOT NULL;
```

- [ ] **Step 2: Apply migration to DEV**

```bash
cd /root/pt-dev && source .db_credentials && \
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  < migrations/20260508_test_results_add_voice_intake.sql && \
  mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  -e "SHOW COLUMNS FROM test_results LIKE 'test_type'\G"
```

Expected: `Type: enum('disc','sensory','voice_intake')` in output.

- [ ] **Step 3: Create `public_html/includes/tests/voice_intake_meta.php`**

```php
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
```

- [ ] **Step 4: Create `tests/voice_intake_validate_test.php`**

```php
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
```

- [ ] **Step 5: Run tests**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -50
```

Expected: 510 prior PASS + ~30 new asserts pass = ~540 PASS, Fail: 0.

- [ ] **Step 6: Commit**

```bash
cd /root/pt-dev && git add migrations/20260508_test_results_add_voice_intake.sql public_html/includes/tests/voice_intake_meta.php tests/voice_intake_validate_test.php && git commit -m "$(cat <<'EOF'
feat(pt): voice_intake 마이그 + PHP meta + validate()

- test_results.test_type ENUM 에 'voice_intake' 추가 (DEV 적용 완료)
- VoiceIntake 클래스: 11문항(2섹션) + validate()
- result_data v1 (version/answers/submitted_at)
- '기타' 옵션 자유 입력 검증 (1~200자)
- Q10 '해당없음' 상호 배타 검증

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: api/member_tests.php — voice_intake submit 분기 + 테스트

**Files:**
- Modify: `public_html/api/member_tests.php` (`memberTestsSubmitImpl` 본문 + require_once 추가, latest 화이트리스트 확장)
- Create: `tests/member_tests_voice_intake_submit_test.php`

`memberTestsSubmitImpl` 의 화이트리스트에 `'voice_intake'` 추가, testType 별로 `Sensory::score` / `Disc::score` / `VoiceIntake::validate` 분기. `memberTestsLatestImpl` 화이트리스트 확장.

- [ ] **Step 1: Write failing tests**

Create `tests/member_tests_voice_intake_submit_test.php`:

```php
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
```

Run: `cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -30` — expect FAIL (test_type='voice_intake' 거절 또는 함수 미정의).

- [ ] **Step 2: Inspect current member_tests.php**

```bash
sed -n '1,100p' /root/pt-dev/public_html/api/member_tests.php
```

확인사항:
- 상단에 `require_once .../sensory_meta.php` 와 `disc_meta.php` 두 줄
- `memberTestsSubmitImpl` 의 화이트리스트 `['sensory', 'disc']`
- `memberTestsSubmitImpl` 의 testType 분기 (sensory/disc 각각)
- `memberTestsLatestImpl` 의 화이트리스트 `['sensory', 'disc']`

- [ ] **Step 3: Modify `public_html/api/member_tests.php`**

3a) 상단 require_once 블록에 한 줄 추가. 찾기:
```php
require_once __DIR__ . '/../includes/tests/disc_meta.php';
```
직후에:
```php
require_once __DIR__ . '/../includes/tests/voice_intake_meta.php';
```

3b) `memberTestsSubmitImpl` 의 화이트리스트와 분기 확장. 현재 함수 본문을 다음으로 교체:

```php
function memberTestsSubmitImpl(PDO $db, array $user, array $input): array
{
    $testType = $input['test_type'] ?? '';
    if (!in_array($testType, ['sensory', 'disc', 'voice_intake'], true)) {
        throw new InvalidArgumentException("test_type must be 'sensory', 'disc', or 'voice_intake'");
    }

    $answers = $input['answers'] ?? null;
    if (!is_array($answers)) {
        throw new InvalidArgumentException('answers must be an array');
    }

    if ($testType === 'sensory') {
        $answers = array_map(static fn($a) => is_int($a) ? $a : (is_numeric($a) ? (int)$a : -1), $answers);
        $resultData = Sensory::score($answers);
    } elseif ($testType === 'disc') {
        $coerced = [];
        foreach ($answers as $inner) {
            if (!is_array($inner)) {
                $coerced[] = [-1, -1, -1, -1];
                continue;
            }
            $coerced[] = array_map(
                static fn($a) => is_int($a) ? $a : (is_numeric($a) ? (int)$a : -1),
                $inner
            );
        }
        $resultData = Disc::score($coerced);
    } else {
        // voice_intake — 채점 없음, 검증만
        $resultData = VoiceIntake::validate($answers);
    }

    $stmt = $db->prepare(
        "INSERT INTO test_results (member_id, test_type, result_data, tested_at, memo)
         VALUES (?, ?, ?, ?, NULL)"
    );
    $stmt->execute([
        (int)$user['id'],
        $testType,
        json_encode($resultData, JSON_UNESCAPED_UNICODE),
        date('Y-m-d'),
    ]);

    return [
        'result_id'   => (int)$db->lastInsertId(),
        'result_data' => $resultData,
    ];
}
```

3c) `memberTestsLatestImpl` 의 화이트리스트 확장. 현재 코드의:
```php
    if (!in_array($testType, ['sensory', 'disc'], true)) {
        throw new InvalidArgumentException("test_type must be sensory|disc");
    }
```
다음으로 교체:
```php
    if (!in_array($testType, ['sensory', 'disc', 'voice_intake'], true)) {
        throw new InvalidArgumentException("test_type must be sensory|disc|voice_intake");
    }
```

- [ ] **Step 4: Run all tests**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -30
```

Expected: 540 prior PASS + ~9 new asserts = ~549, Fail: 0.

- [ ] **Step 5: Commit**

```bash
cd /root/pt-dev && git add public_html/api/member_tests.php tests/member_tests_voice_intake_submit_test.php && git commit -m "$(cat <<'EOF'
feat(pt): member_tests submit/latest voice_intake 추가

- submit 화이트리스트 ['sensory','disc'] → ['sensory','disc','voice_intake']
- testType 분기에 VoiceIntake::validate() 추가
- latest 화이트리스트도 voice_intake 확장
- sensory/disc 회귀 0

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: voice-intake-data.js + PHP↔JS parity

**Files:**
- Create: `public_html/me/js/voice-intake-data.js`
- Create: `tests/voice_intake_php_js_parity_test.php`

JS 측 11문항 메타 + 동기화 가드.

- [ ] **Step 1: Create `public_html/me/js/voice-intake-data.js`**

```js
'use strict';

/**
 * Voice Intake survey questions — JS 측. PHP 측: includes/tests/voice_intake_meta.php.
 * 동기화 가드: tests/voice_intake_php_js_parity_test.php
 *
 * 각 항목의 모든 필드(id, type, allow_other, section, text, options) 가 PHP 와 byte-identical.
 * single quote 사용 — parity 가드 정규식이 single quote 기반.
 */
const VoiceIntakeData = {
  questions: [
    {id:'q1', type:'single', allow_other:false, section:1, short_label:'성별',
     text:'성별을 알려주세요.',
     options:['여성','남성']},
    {id:'q2', type:'single', allow_other:true, section:1, short_label:'연령대',
     text:'연령대를 알려주세요.',
     options:['10대','20대','30대','40대','50대','60대','70대 이상','기타']},
    {id:'q3', type:'single', allow_other:true, section:1, short_label:'거주지역',
     text:'거주지역은 어디신가요?',
     options:['국내','해외','기타']},
    {id:'q4', type:'single', allow_other:true, section:1, short_label:'학습 목표',
     text:'소리튠 영어를 하는 목표를 알려주세요.',
     options:['승진이나 취업을 위해서','업무에 필요해서','유학을 위해서','해외여행을 위해서','영어를 유창하게 하고 싶어서.','일상에서의 원활한 의사소통을 위해서','자연스러운 영어소리와 리스닝 향상을 위해서','영화나 미드를 자막없이 보기 위해서','자기 만족& 자신감을 위해서','기타']},
    {id:'q5', type:'single', allow_other:true, section:1, short_label:'하루 투자 시간',
     text:'소리튠영어 훈련하는데 하루에 투자할 수 있는 시간을 알려주세요.',
     options:['30분 이하','30분~1시간','1시간~2시간','2시간~3시간','3시간~4시간','4시간 이상','기타']},
    {id:'q6', type:'single', allow_other:true, section:1, short_label:'훈련 시간대',
     text:'주로 훈련하는 시간대를 알려주세요. (한국시간 기준)',
     options:['오전(6시~12시)','오후(12시~18시)','저녁(18시~0시)','새벽(0시~6시)','기타']},
    {id:'q7', type:'single', allow_other:true, section:2, short_label:'지속 어려움',
     text:'그동안 영어 공부를 지속하기 어려웠던 상황은 무엇인가요.',
     options:['낮은 영어 훈련 의지','불규칙한 생활 패턴','바쁜 일상으로 훈련 시간 부족','나에게 맞는 훈련법이 없음','기타']},
    {id:'q8', type:'single', allow_other:true, section:2, short_label:'코칭 도움',
     text:'음성코칭서비스를 통해 어떤 도움을 받고 싶나요.',
     options:['꾸준히 하는 훈련 습관 형성','함께 한다는 심리적 지지','끝까지 완주하는 성취감','코치와의 소통','기타']},
    {id:'q9', type:'single', allow_other:true, section:2, short_label:'코치 스타일',
     text:'원하시는 코치 스타일을 알려주세요.',
     options:['타이트하게 끌어주는 코치','상냥하게 밀어주는 코치','상관없음','기타']},
    {id:'q10', type:'multi', allow_other:false, section:2, short_label:'해당 사항',
     text:'해당 사항에 체크해주세요. (복수체크 가능)',
     options:['목소리가 잘 쉰다.','한국어 딕션이 명확하지 않다.','매일 1시간이상 영어훈련에 투자하기 어렵다.','영어공부 꾸준히 해본적이 없다.','음치박치다.','듣고 이해하는 것보다 글을 읽고 이해하는 게 빠르다.','해외 여행시 영어가 두렵다.','말을 할 때 조음기관(입, 혀, 턱)의 움직임이 크지 않다.','해당없음']},
    {id:'q11', type:'single', allow_other:false, section:2, short_label:'자기개방 편안함',
     text:'자신의 이야기를 편안하게 나눌 수 있나요?',
     options:['매우 그렇다','그렇다','그렇지않다','매우그렇지않다']},
  ],
};
window.VoiceIntakeData = VoiceIntakeData;
```

- [ ] **Step 2: Create `tests/voice_intake_php_js_parity_test.php`**

```php
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
```

- [ ] **Step 3: Lint + run tests**

```bash
cd /root/pt-dev && \
  node --check public_html/me/js/voice-intake-data.js && \
  php tests/run_tests.php 2>&1 | tail -10
```

Expected: no JS errors. Parity test count=11, 0 mismatches. Total +2 (549 → 551).

- [ ] **Step 4: Commit**

```bash
cd /root/pt-dev && git add public_html/me/js/voice-intake-data.js tests/voice_intake_php_js_parity_test.php && git commit -m "$(cat <<'EOF'
feat(pt): voice_intake JS data + PHP↔JS parity 가드

- VoiceIntakeData.questions = 11항목 (PHP 와 동일)
- parity 검증: id/type/allow_other/section/short_label/text/options 모두
- 한쪽만 수정 시 즉시 fail

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: voice-intake-runner.js — 2섹션 폼 + 결과 보기 + 감사 화면

**Files:**
- Create: `public_html/me/js/voice-intake-runner.js`
- Modify: `public_html/assets/css/member.css` (append voice intake styles)

응답 폼 + 결과 재조회 + 제출 후 감사 화면을 한 모듈에서 처리. 내부 view 상태로 분기.

- [ ] **Step 1: Create `public_html/me/js/voice-intake-runner.js`**

```js
'use strict';

/**
 * Voice Intake 사전 설문 러너 — 2섹션 폼.
 *
 * 사용:
 *   MeVoiceIntakeRunner.start(rootEl, member);
 *   MeVoiceIntakeRunner.renderResult(rootEl, resultData);
 *
 * 응답 모델:
 *   state.values[questionId] = string (single) | string[] (multi)
 *   state.others[questionId] = string ('기타' 인 경우 자유 입력)
 *   state.section = 1 | 2 ('thanks' 는 제출 후 감사 화면)
 */
const MeVoiceIntakeRunner = {
  state: null,

  start(root, member) {
    if (!window.VoiceIntakeData) {
      root.innerHTML = '<div class="me-error">설문 데이터가 로드되지 않았습니다</div>';
      return;
    }
    this.state = {
      root, member,
      data: window.VoiceIntakeData,
      values: {},   // qId → string | string[]
      others: {},   // qId → string
      section: 1,
    };
    this.render();
  },

  /** 제출 후 감사 화면 (start() 와 별개로 호출) */
  renderThanks(root) {
    root.innerHTML = `
      <div class="me-shell">
        <main class="me-result">
          <div class="me-result-top">
            <div class="me-result-top-label">응답 완료</div>
            <h2>✓ 응답이 저장되었습니다</h2>
            <p>소중한 응답 감사합니다. 코치 매칭에 반영됩니다.</p>
          </div>
          <div class="me-result-actions">
            <button class="me-btn me-btn-primary" id="meBackDash">메인으로</button>
          </div>
        </main>
      </div>
    `;
    document.getElementById('meBackDash').onclick = () => MeApp.go('dashboard');
  },

  /** "내 응답 보기" — 저장된 result_data 를 11문항 펼친 화면으로 표시 */
  renderResult(root, resultData) {
    const qs = (window.VoiceIntakeData && window.VoiceIntakeData.questions) || [];
    const answers = (resultData && resultData.answers) || {};
    const rows = qs.map(q => {
      const a = answers[q.id] || {};
      let answerHtml;
      if (q.type === 'multi') {
        const vs = Array.isArray(a.values) ? a.values : [];
        answerHtml = vs.map(v => `<div>• ${MeUI.esc(v)}</div>`).join('');
      } else {
        const v = typeof a.value === 'string' ? a.value : '';
        if (v === '기타' && typeof a.other === 'string' && a.other) {
          answerHtml = MeUI.esc(a.other);
        } else {
          answerHtml = MeUI.esc(v || '-');
        }
      }
      return `
        <section class="me-vi-row">
          <div class="me-vi-q">${MeUI.esc(q.id.toUpperCase())}. ${MeUI.esc(q.text)}</div>
          <div class="me-vi-a">${answerHtml}</div>
        </section>
      `;
    }).join('');

    root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 메인으로</button>
          <div class="me-greet">음성 케어 매칭 사전 질문</div>
        </header>
        <main class="me-vi-result">${rows}</main>
        <div class="me-result-actions">
          <button class="me-btn me-btn-outline" id="meRetry">수정하려면 다시 응답</button>
          <button class="me-btn me-btn-primary" id="meBackDash">메인으로</button>
        </div>
      </div>
    `;
    document.getElementById('meBackBtn').onclick = () => MeApp.go('dashboard');
    document.getElementById('meBackDash').onclick = () => MeApp.go('dashboard');
    document.getElementById('meRetry').onclick = () => MeApp.go('test', { testType: 'voice_intake' });
  },

  render() {
    const s = this.state;
    const sectionQs = s.data.questions.filter(q => q.section === s.section);
    const sectionTitle = s.section === 1
      ? `1 / 2  기본 정보`
      : `2 / 2  훈련 방향 설정을 위한 설문입니다.`;

    s.root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 메인으로</button>
          <div class="me-greet">음성 케어 매칭 사전 질문</div>
        </header>
        <main class="me-vi">
          <div class="me-step-header">
            <h2>${sectionTitle}</h2>
          </div>
          <ol class="me-vi-questions">
            ${sectionQs.map(q => this.renderQuestion(q)).join('')}
          </ol>
          <div class="me-test-actions">
            ${s.section === 1
              ? `<button class="me-btn me-btn-ghost" id="meBackDash">취소</button>`
              : `<button class="me-btn me-btn-ghost" id="meBtnPrev">← 이전</button>`}
            <button class="me-btn me-btn-primary" id="meBtnNext">${s.section === 1 ? '다음 →' : '제출하기'}</button>
          </div>
        </main>
      </div>
    `;

    document.getElementById('meBackBtn').onclick = () => MeApp.go('dashboard');
    if (s.section === 1) {
      document.getElementById('meBackDash').onclick = () => MeApp.go('dashboard');
    } else {
      document.getElementById('meBtnPrev').onclick = () => { s.section = 1; this.render(); };
    }
    document.getElementById('meBtnNext').onclick = () => this.next();

    this.bindInputs();
    this.updateNextEnabled();
  },

  renderQuestion(q) {
    const s = this.state;
    if (q.type === 'multi') {
      const checked = new Set(s.values[q.id] || []);
      return `
        <li class="me-vi-q-item" data-qid="${q.id}">
          <div class="me-vi-q-text">${MeUI.esc(q.text)}</div>
          <div class="me-vi-options">
            ${q.options.map((opt, i) => `
              <label class="me-vi-checkbox">
                <input type="checkbox" data-qid="${q.id}" data-opt="${MeUI.esc(opt)}" ${checked.has(opt) ? 'checked' : ''}>
                <span>${MeUI.esc(opt)}</span>
              </label>
            `).join('')}
          </div>
          <div class="me-vi-error" data-error="${q.id}"></div>
        </li>
      `;
    }
    // single
    const value = s.values[q.id] || '';
    const otherVisible = q.allow_other && value === '기타';
    return `
      <li class="me-vi-q-item" data-qid="${q.id}">
        <div class="me-vi-q-text">${MeUI.esc(q.text)}</div>
        <div class="me-vi-options">
          ${q.options.map((opt, i) => `
            <label class="me-vi-radio">
              <input type="radio" name="vi_${q.id}" data-qid="${q.id}" data-opt="${MeUI.esc(opt)}" ${value === opt ? 'checked' : ''}>
              <span>${MeUI.esc(opt)}</span>
            </label>
          `).join('')}
        </div>
        ${q.allow_other ? `
          <input type="text" class="me-input me-vi-other" data-qid="${q.id}"
            placeholder="기타 내용을 입력해주세요 (200자 이내)"
            maxlength="200"
            value="${MeUI.esc(s.others[q.id] || '')}"
            style="${otherVisible ? '' : 'display:none'}">
        ` : ''}
        <div class="me-vi-error" data-error="${q.id}"></div>
      </li>
    `;
  },

  bindInputs() {
    const s = this.state;
    s.root.querySelectorAll('input[type="radio"]').forEach(r => {
      r.onchange = () => {
        const qid = r.dataset.qid;
        const opt = r.dataset.opt;
        s.values[qid] = opt;
        // toggle "기타" textarea visibility
        const otherInput = s.root.querySelector(`input.me-vi-other[data-qid="${qid}"]`);
        if (otherInput) {
          otherInput.style.display = opt === '기타' ? '' : 'none';
          if (opt !== '기타') delete s.others[qid];
        }
        this.clearError(qid);
        this.updateNextEnabled();
      };
    });
    s.root.querySelectorAll('input[type="checkbox"]').forEach(c => {
      c.onchange = () => {
        const qid = c.dataset.qid;
        const opt = c.dataset.opt;
        const arr = s.values[qid] || [];
        if (c.checked) {
          if (!arr.includes(opt)) arr.push(opt);
        } else {
          const idx = arr.indexOf(opt);
          if (idx >= 0) arr.splice(idx, 1);
        }
        // Q10 상호 배타: '해당없음'
        if (qid === 'q10') {
          if (opt === '해당없음' && c.checked) {
            // 다른 모두 해제
            s.values.q10 = ['해당없음'];
          } else if (opt !== '해당없음' && c.checked) {
            // '해당없음' 해제
            const noneIdx = arr.indexOf('해당없음');
            if (noneIdx >= 0) arr.splice(noneIdx, 1);
            s.values.q10 = arr;
          } else {
            s.values.q10 = arr;
          }
          // re-render to reflect mutual exclusion
          this.render();
          return;
        }
        s.values[qid] = arr;
        this.clearError(qid);
        this.updateNextEnabled();
      };
    });
    s.root.querySelectorAll('input.me-vi-other').forEach(t => {
      t.oninput = () => {
        const qid = t.dataset.qid;
        s.others[qid] = t.value;
        this.clearError(qid);
        this.updateNextEnabled();
      };
    });
  },

  showError(qid, msg) {
    const el = this.state.root.querySelector(`[data-error="${qid}"]`);
    if (el) { el.textContent = msg; el.classList.add('me-vi-error-shown'); }
  },

  clearError(qid) {
    const el = this.state.root.querySelector(`[data-error="${qid}"]`);
    if (el) { el.textContent = ''; el.classList.remove('me-vi-error-shown'); }
  },

  validateSection(section) {
    const s = this.state;
    const qs = s.data.questions.filter(q => q.section === section);
    let firstError = null;
    for (const q of qs) {
      if (q.type === 'single') {
        const v = s.values[q.id];
        if (!v) {
          this.showError(q.id, '선택해주세요');
          if (!firstError) firstError = q.id;
          continue;
        }
        if (q.allow_other && v === '기타') {
          const other = (s.others[q.id] || '').trim();
          if (!other) {
            this.showError(q.id, '내용을 입력해주세요');
            if (!firstError) firstError = q.id;
          }
        }
      } else {
        const arr = s.values[q.id] || [];
        if (arr.length === 0) {
          this.showError(q.id, '한 개 이상 선택해주세요');
          if (!firstError) firstError = q.id;
        }
      }
    }
    return firstError === null;
  },

  isSectionComplete(section) {
    const s = this.state;
    const qs = s.data.questions.filter(q => q.section === section);
    for (const q of qs) {
      if (q.type === 'single') {
        const v = s.values[q.id];
        if (!v) return false;
        if (q.allow_other && v === '기타') {
          const other = (s.others[q.id] || '').trim();
          if (!other) return false;
        }
      } else {
        const arr = s.values[q.id] || [];
        if (arr.length === 0) return false;
      }
    }
    return true;
  },

  updateNextEnabled() {
    const s = this.state;
    const btn = document.getElementById('meBtnNext');
    if (btn) btn.disabled = !this.isSectionComplete(s.section);
  },

  next() {
    const s = this.state;
    if (!this.validateSection(s.section)) return;

    if (s.section === 1) {
      s.section = 2;
      this.render();
    } else {
      this.submit();
    }
  },

  async submit() {
    const s = this.state;
    if (!this.validateSection(1) || !this.validateSection(2)) return;

    const answersOut = {};
    for (const q of s.data.questions) {
      if (q.type === 'multi') {
        answersOut[q.id] = { values: s.values[q.id] || [] };
      } else {
        const entry = { value: s.values[q.id] };
        if (q.allow_other && s.values[q.id] === '기타') {
          entry.other = (s.others[q.id] || '').trim();
        }
        answersOut[q.id] = entry;
      }
    }

    const btn = document.getElementById('meBtnNext');
    if (btn) { btn.disabled = true; btn.textContent = '저장 중...'; }
    const res = await MeAPI.post('/api/member_tests.php?action=submit', {
      test_type: 'voice_intake',
      answers: answersOut,
    });
    if (res.ok) {
      this.renderThanks(s.root);
    } else {
      if (btn) { btn.disabled = false; btn.textContent = '제출하기'; }
      alert(res.message || '저장에 실패했습니다');
    }
  },
};
```

- [ ] **Step 2: Append CSS to `public_html/assets/css/member.css`**

```css
/* === Voice Intake === */
.me-vi { background: var(--surface-card, #252525); border-radius: 12px; padding: 20px 18px; }
.me-vi-questions { list-style: none; margin: 0; padding: 0; counter-reset: viq; }
.me-vi-q-item { padding: 16px 0; border-bottom: 1px solid #3a3a3a; counter-increment: viq; }
.me-vi-q-item:last-child { border-bottom: none; }
.me-vi-q-text { color: var(--text); font-size: 14px; font-weight: 600; line-height: 1.5; margin-bottom: 12px; }
.me-vi-q-text::before { content: 'Q' counter(viq) '. '; color: var(--accent); margin-right: 4px; }
.me-vi-options { display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px; }
.me-vi-radio, .me-vi-checkbox {
  display: flex; align-items: center; gap: 10px; cursor: pointer;
  padding: 10px 12px; border-radius: 8px; border: 1px solid #3a3a3a; background: #1f1f1f;
  color: var(--text); font-size: 13px; line-height: 1.4; user-select: none;
}
.me-vi-radio:hover, .me-vi-checkbox:hover { border-color: #5a5a5a; }
.me-vi-radio input, .me-vi-checkbox input { accent-color: var(--accent); flex-shrink: 0; }
.me-vi-other { margin-top: 8px; }
.me-vi-error { color: #f3727f; font-size: 12px; min-height: 16px; margin-top: 6px; }
.me-vi-error-shown { font-weight: 500; }

/* === Voice Intake result view === */
.me-vi-result { background: var(--surface-card, #252525); border-radius: 12px; padding: 20px 18px; }
.me-vi-row { padding: 14px 0; border-bottom: 1px solid #3a3a3a; }
.me-vi-row:last-child { border-bottom: none; }
.me-vi-q { color: var(--text-secondary); font-size: 12px; margin-bottom: 6px; }
.me-vi-a { color: var(--text); font-size: 14px; line-height: 1.6; }
```

- [ ] **Step 3: Lint + tests**

```bash
cd /root/pt-dev && \
  node --check public_html/me/js/voice-intake-runner.js && \
  php tests/run_tests.php 2>&1 | tail -3
```

Expected: clean lint. `Total: 551  Pass: 551  Fail: 0`.

- [ ] **Step 4: Commit**

```bash
cd /root/pt-dev && git add public_html/me/js/voice-intake-runner.js public_html/assets/css/member.css && git commit -m "$(cat <<'EOF'
feat(pt): /me/js/voice-intake-runner.js + voice intake CSS

- 2섹션 폼 (Q1~Q6 / Q7~Q11) — 라디오/체크박스/기타 자유입력
- '기타' 선택 시 텍스트 입력란 표시 + 검증
- Q10 '해당없음' 상호 배타 (다른 항목 자동 해제 + 그 반대)
- 제출 후 감사 화면 + '내 응답 보기' (11행 펼침)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 라우터/대시보드 wire-up

**Files:**
- Modify: `public_html/me/index.php`
- Modify: `public_html/me/js/app.js`
- Modify: `public_html/me/js/dashboard.js`

DISC wire-up 과 평행 패턴.

- [ ] **Step 1: `me/index.php` — voice intake 스크립트 2개 추가**

Find the existing block:
```php
<script src="/me/js/disc-runner.js"></script>
<script src="/me/js/disc.js"></script>
<script src="/me/js/disc-result-view.js"></script>
```

Replace with:
```php
<script src="/me/js/disc-runner.js"></script>
<script src="/me/js/disc.js"></script>
<script src="/me/js/disc-result-view.js"></script>
<script src="/me/js/voice-intake-data.js"></script>
<script src="/me/js/voice-intake-runner.js"></script>
```

- [ ] **Step 2: `me/js/app.js` — render() 의 'test'/'result' 분기 확장**

Find the current `render()` body — currently has DISC branch. Replace `if (view === 'test')` and `else if (view === 'result')` clauses with:

```js
    } else if (view === 'test') {
      if (params.testType === 'disc') {
        MeDiscRunner.start(this.root, member);
      } else if (params.testType === 'voice_intake') {
        MeVoiceIntakeRunner.start(this.root, member);
      } else {
        MeTestRunner.start(this.root, params.testType, member);
      }
    } else if (view === 'result') {
      if (params.testType === 'disc') {
        MeDiscResultView.render(this.root, params.resultData);
      } else if (params.testType === 'voice_intake') {
        MeVoiceIntakeRunner.renderResult(this.root, params.resultData);
      } else {
        MeResultView.render(this.root, params.testType, params.resultData);
      }
    }
```

- [ ] **Step 3: `me/js/dashboard.js` — 3번째 카드 + loadCards 확장**

3a) Replace `loadCards()`:
```js
  async loadCards() {
    const [sensory, disc, voice] = await Promise.all([
      MeAPI.get('/api/member_tests.php?action=latest&test_type=sensory'),
      MeAPI.get('/api/member_tests.php?action=latest&test_type=disc'),
      MeAPI.get('/api/member_tests.php?action=latest&test_type=voice_intake'),
    ]);
    const cards = document.getElementById('meCards');
    cards.innerHTML = `
      ${this.renderSensoryCard(sensory.ok ? sensory.data.result : null)}
      ${this.renderDiscCard(disc.ok ? disc.data.result : null)}
      ${this.renderVoiceIntakeCard(voice.ok ? voice.data.result : null)}
    `;
    this.bindCards();
  },
```

3b) Add a new method `renderVoiceIntakeCard` directly after `renderDiscCard`:

```js
  renderVoiceIntakeCard(latest) {
    if (latest) {
      return `
        <div class="me-card">
          <div class="me-card-title">음성 케어 매칭 사전 질문</div>
          <div class="me-card-meta">최근 응답: ${MeUI.esc(MeUI.formatDate(latest.tested_at))}</div>
          <div class="me-card-result">응답 완료</div>
          <div class="me-card-actions">
            <button class="me-btn me-btn-primary" data-action="view-voice">내 응답 보기</button>
            <button class="me-btn me-btn-outline" data-action="retake-voice">다시 응답하기</button>
          </div>
        </div>
      `;
    }
    return `
      <div class="me-card">
        <div class="me-card-title">음성 케어 매칭 사전 질문</div>
        <div class="me-card-meta">미응답</div>
        <div class="me-card-desc">11문항으로 코치 매칭에 필요한 정보를 수집합니다 (5분 소요)</div>
        <div class="me-card-actions">
          <button class="me-btn me-btn-primary" data-action="start-voice">응답하기</button>
        </div>
      </div>
    `;
  },
```

3c) `bindCards()` 에 voice 액션 추가. 기존 본문의 `} else if (action === 'view-disc') { ... }` 직후에 추가:

```js
        } else if (action === 'start-voice' || action === 'retake-voice') {
          MeApp.go('test', { testType: 'voice_intake' });
        } else if (action === 'view-voice') {
          const res = await MeAPI.get('/api/member_tests.php?action=latest&test_type=voice_intake');
          if (res.ok && res.data.result) {
            MeApp.go('result', { testType: 'voice_intake', resultData: res.data.result.result_data });
          }
        }
```

- [ ] **Step 4: Lint + tests**

```bash
cd /root/pt-dev && \
  node --check public_html/me/js/app.js && \
  node --check public_html/me/js/dashboard.js && \
  php -l public_html/me/index.php && \
  php tests/run_tests.php 2>&1 | tail -3
```

Expected: clean. `Total: 551  Pass: 551  Fail: 0`.

- [ ] **Step 5: Commit**

```bash
cd /root/pt-dev && git add public_html/me/index.php public_html/me/js/app.js public_html/me/js/dashboard.js && git commit -m "$(cat <<'EOF'
feat(pt): /me/ voice_intake 라우팅 + 대시보드 3번째 카드

- index.php — voice-intake-data / voice-intake-runner 스크립트 추가
- app.js — test/result 분기에 testType==='voice_intake' 라우팅
- dashboard.js — renderVoiceIntakeCard 응답/미응답 분기 + 3개 카드 동시 fetch

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: 어드민/코치 차트 — voice_intake 분기

**Files:**
- Modify: `public_html/admin/js/pages/member-chart.js` (`formatTestResult` + 새 헬퍼)
- Modify: `public_html/coach/js/pages/member-chart.js` (동일)

`formatTestResult` 의 `isNewVoiceIntake` 분기 — 11행 펼친 카드. `short_label` 매핑은 inline.

- [ ] **Step 1: Replace `formatTestResult` in `admin/js/pages/member-chart.js`**

Find the existing `formatTestResult` method (last updated in DISC T7) and replace its body with:

```js
  formatTestResult(testType, data) {
    let parsed;
    try { parsed = typeof data === 'string' ? JSON.parse(data) : data; } catch { return UI.esc(String(data || '-')); }
    if (!parsed || typeof parsed !== 'object') return UI.esc(String(parsed || '-'));

    const isNewSensory      = testType === 'sensory'      && parsed.version === 1 && parsed.percents;
    const isNewDisc         = testType === 'disc'         && parsed.version === 1 && parsed.scores && parsed.primary;
    const isNewVoiceIntake  = testType === 'voice_intake' && parsed.version === 1 && parsed.answers;

    if (isNewSensory) {
      const p = parsed.percents;
      return `
        <div style="font-weight:700;margin-bottom:4px">${UI.esc(parsed.title || '')}</div>
        <div style="font-size:12px;color:var(--text-secondary)">
          청각 ${p.auditory ?? 0}%  ·  시각 ${p.visual ?? 0}%  ·  체각 ${p.kinesthetic ?? 0}%
        </div>
      `;
    }

    if (isNewDisc) {
      const s = parsed.scores;
      return `
        <div style="font-weight:700;margin-bottom:4px">${UI.esc(parsed.title || '')} (${UI.esc(parsed.primary || '')})</div>
        <div style="font-size:12px;color:var(--text-secondary)">
          D ${s.D ?? 0}  ·  I ${s.I ?? 0}  ·  S ${s.S ?? 0}  ·  C ${s.C ?? 0}
        </div>
      `;
    }

    if (isNewVoiceIntake) {
      return this.formatVoiceIntakeRows(parsed.answers);
    }

    // Legacy fallback
    if (Array.isArray(parsed)) return UI.esc(parsed.join(', '));
    return UI.esc(Object.entries(parsed).map(([k,v]) => `${k}: ${v}`).join(' | '));
  },

  formatVoiceIntakeRows(answers) {
    const meta = [
      ['q1','성별'],
      ['q2','연령대'],
      ['q3','거주지역'],
      ['q4','학습 목표'],
      ['q5','하루 투자 시간'],
      ['q6','훈련 시간대'],
      ['q7','지속 어려움'],
      ['q8','코칭 도움'],
      ['q9','코치 스타일'],
      ['q10','해당 사항'],
      ['q11','자기개방 편안함'],
    ];
    const rows = meta.map(([id, label]) => {
      const a = (answers && answers[id]) || {};
      let ansHtml;
      if (Array.isArray(a.values)) {
        ansHtml = a.values.map(v => UI.esc(v)).join('<br>');
      } else if (typeof a.value === 'string') {
        if (a.value === '기타' && typeof a.other === 'string' && a.other) {
          ansHtml = UI.esc(a.other);
        } else {
          ansHtml = UI.esc(a.value || '-');
        }
      } else {
        ansHtml = '-';
      }
      const qNum = id.toUpperCase();
      return `
        <tr>
          <td style="padding:4px 8px 4px 0;color:var(--text-secondary);font-size:12px;white-space:nowrap;vertical-align:top">${qNum}. ${UI.esc(label)}</td>
          <td style="padding:4px 0;font-size:13px;line-height:1.6">${ansHtml}</td>
        </tr>
      `;
    }).join('');
    return `<table style="width:100%;border-collapse:collapse">${rows}</table>`;
  },
```

- [ ] **Step 2: Inspect `member-chart.js` to confirm voice_intake will be listed**

```bash
grep -n "discResults\|sensoryResults\|test_type" /root/pt-dev/public_html/admin/js/pages/member-chart.js | head -20
```

Find the current grouping (probably filters `r.test_type === 'disc'` and `'sensory'`). We need to add a third group for `'voice_intake'`.

- [ ] **Step 3: Add voice_intake group rendering in admin chart's `loadTests`**

Find the `loadTests()` method body (around the area where `discResults` and `sensoryResults` are filtered). After the existing sensory section's `</h3>` block, add a new section. The pattern in admin is approximately:

```js
    const discResults = results.filter(r => r.test_type === 'disc');
    const sensoryResults = results.filter(r => r.test_type === 'sensory');
    const voiceResults = results.filter(r => r.test_type === 'voice_intake');
```

(add the third filter line)

Then in the HTML template (after the sensory block ending with `</h3>` ... 카드 매핑), add:

```js
      <h3 style="font-size:14px;font-weight:700;margin:20px 0 12px">음성 케어 매칭 사전 질문</h3>
      ${voiceResults.length === 0 ? '<div style="color:var(--text-secondary)">결과 없음</div>' :
        voiceResults.map(r => `
          <div class="card" style="margin-bottom:8px;padding:12px;background:var(--surface-card)">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
              <div style="flex:1;min-width:0">
                <span style="font-size:12px;color:var(--text-secondary)">${UI.esc(r.tested_at)}</span>
                <div style="margin-top:8px">${this.formatTestResult(r.test_type, r.result_data)}</div>
                ${r.memo ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:4px">${UI.esc(r.memo)}</div>` : ''}
              </div>
              <button class="btn btn-small btn-outline" onclick="App.pages['member-chart'].deleteTest(${r.id})">삭제</button>
            </div>
          </div>
        `).join('')
      }
```

(Place this directly after the sensory section's closing brace.)

- [ ] **Step 4: Mirror changes in `coach/js/pages/member-chart.js`**

Apply the same `formatTestResult` replacement (with `formatVoiceIntakeRows`) and add the voice_intake group section. Coach 의 차트 layout 은 admin 과 살짝 다를 수 있음 — 기존 sensory/disc 배치 패턴 그대로 따라하기. **삭제 버튼은 어드민 only** — coach 의 voice 카드는 삭제 버튼 비표시.

- [ ] **Step 5: Lint + tests**

```bash
cd /root/pt-dev && \
  node --check public_html/admin/js/pages/member-chart.js && \
  node --check public_html/coach/js/pages/member-chart.js && \
  php tests/run_tests.php 2>&1 | tail -3
```

Expected: clean. `Total: 551  Pass: 551  Fail: 0`.

- [ ] **Step 6: Commit**

```bash
cd /root/pt-dev && git add public_html/admin/js/pages/member-chart.js public_html/coach/js/pages/member-chart.js && git commit -m "$(cat <<'EOF'
feat(pt): member-chart voice_intake 11행 카드 렌더 + 신규 섹션

- formatTestResult — isNewVoiceIntake 분기 + formatVoiceIntakeRows() 헬퍼
- 11문항 short_label inline 매핑 (Q번호·라벨·답변 표 형식)
- '기타' 선택 시 other 텍스트 표시
- Q10 다중은 줄바꿈
- 어드민/코치 차트에 별도 "음성 케어 매칭 사전 질문" 섹션 추가
- 삭제 버튼은 어드민만 (sensory/disc 와 동일 정책)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: 수동 smoke + dev push

**Files:** none

- [ ] **Step 1: 전체 테스트 PASS 확인**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -5
```

Expected: `Total: ~551  Pass: ~551  Fail: 0`

- [ ] **Step 2: 브라우저 — 음성 설문 끝까지**

`https://dev-pt.soritune.com/me/` 회원 로그인 후:

- 대시보드 → "음성 케어 매칭 사전 질문" 카드 → "응답하기"
- 섹션 1 (Q1~Q6) 진행
  - Q1 라디오 1개 선택 → "다음" 버튼은 모든 6문항 충족 시 활성
  - Q4 에서 "기타" 선택 → 텍스트 입력란 등장 → 비우면 "다음" 비활성
  - 텍스트 입력 후 "다음 →"
- 섹션 2 (Q7~Q11) 진행
  - Q10 체크박스 여러 개 선택 → 정상
  - Q10 "해당없음" 체크 → 다른 8개 자동 해제 확인
  - Q10 다른 항목 다시 체크 → "해당없음" 자동 해제
  - "← 이전" 으로 섹션 1 돌아가서 Q1 변경 → 값 보존
  - "제출하기" → 감사 화면 → "메인으로"
- 대시보드 카드 — "최근 응답: 2026-05-08, 응답 완료" 표시
- "내 응답 보기" → 11행 펼친 화면 (원본 긴 질문문)
- "수정하려면 다시 응답" → 폼 다시 표시 (값 비어있는 새 응답)
- 두 번째 제출 → `test_results` 두 row

- [ ] **Step 3: 어드민 차트에서 voice_intake 섹션 확인**

dev-pt.soritune.com 어드민 → 회원 차트 → "테스트결과" 탭
- 기존 DISC 진단 / 오감각 테스트 섹션 + 신규 "음성 케어 매칭 사전 질문" 섹션
- 11행 펼친 표 형식 (Q번호 · short_label · 답변)
- "기타" 선택은 other 텍스트로 표시 ("기타" 가 아닌)
- Q10 다중은 줄바꿈
- 두 row 모두 카드 (`tested_at DESC`)
- 코치 (해당 회원 활성 order 보유) 도 동일 표시 (삭제 버튼 없이)

- [ ] **Step 4: 모바일 1회**

Chrome devtools iPhone emulation → `/me/` voice intake 한 번 끝까지
- 라디오·체크박스 터치 영역 충분
- "기타" 텍스트 입력 키보드 표시
- 11행 결과 화면 줄바꿈 OK

- [ ] **Step 5: 사용자 확인 받고 dev push**

여기서 멈추고 사용자에게 "DEV 검수 끝났으면 dev push 해도 될까요?" 확인. 사용자 OK 후:

```bash
cd /root/pt-dev && git push origin dev
```

운영 반영(main 머지 + prod pull + PROD ENUM 마이그) 은 사용자가 추가로 명시 요청한 경우만.

---

## Self-Review

**1. Spec coverage**

| Spec section | Plan task |
|---|---|
| 4.1 라우팅 (대시보드 3번째 카드) | T5 (dashboard.js) |
| 4.2 voice-intake-runner.js 별도 모듈 | T4 |
| 4.3 VoiceIntake::validate, submit dispatch | T1, T2 |
| 4.4 신규 파일 5개 + 수정 5개 | T1~T6 |
| 5.1 ENUM 마이그 | T1 (Step 1~2) |
| 5.2 result_data v1 schema | T1 (validate 빌드) |
| 5.3 필드 규칙 (single/multi/기타) | T1 (검증 + 단위테스트) |
| 5.4 검증 규칙 + Q10 상호 배타 | T1 (단위테스트 포함) |
| 5.6 latest 쿼리 | T2 (latest 화이트리스트만 확장) |
| 6 11문항 메타 (id, type, allow_other, section, options) | T1 (PHP) + T3 (JS) |
| 7 UI 2섹션 폼 + 검증 | T4 |
| 7.4 진행 상태 보존 (세션 메모리) | T4 |
| 7.5 PHP↔JS parity | T3 |
| 8.1 제출 후 감사 화면 | T4 (renderThanks) |
| 8.2 "내 응답 보기" 11행 펼친 | T4 (renderResult) |
| 9 어드민 차트 카드 | T6 (formatVoiceIntakeRows) |
| 10 보안 (requireMember) | T2 (기존 그대로) |
| 11.1 PHP 검증 단위 | T1 |
| 11.2 PHP↔JS parity | T3 |
| 11.3 Submit | T2 |
| 11.4 latest 보강 | T2 |
| 11.5 통합 smoke | T7 |
| 11.6 회귀 가드 | T2 (sensory/disc 회귀 테스트 포함) |
| 13 배포 (DEV → 사용자 확인 → PROD) | T7 (DEV push 만, PROD 별도) |

모든 spec 요구사항 매핑됨.

**2. Placeholder scan**

- "TBD"/"TODO"/"later" — 검색 결과 없음 ✓
- 모든 코드 step 에 실제 코드 ✓
- T6 Step 3 의 admin chart 그룹 추가 — 정확한 라인 위치는 grep 결과에 따라 결정. 코드 자체는 명시.
- T6 Step 4 의 coach 미러링은 admin 과 같은 패턴 — coach 파일 layout 차이 가능성 있어 implementer 가 grep 으로 확인 후 적용 (이미 sensory/disc T7 에서 이 처리 패턴 검증됨)

**3. Type / signature consistency**

- `VoiceIntake::validate(array $rawAnswers): array` — T1 정의, T2 사용 일치 ✓
- `MeVoiceIntakeRunner.start(root, member)` — T4 정의, T5 호출 일치 ✓
- `MeVoiceIntakeRunner.renderResult(root, resultData)` — T4 정의, T5 호출 일치 ✓
- `MeVoiceIntakeRunner.renderThanks(root)` — T4 정의, runner 내부에서만 호출 ✓
- `result_data.answers.qN.value` (single) / `.values` (multi) / `.other` (기타 시) — T1·T4·T6 동일 ✓
- `VoiceIntakeData.questions[i].id/type/allow_other/section/short_label/text/options` — T1·T3·T6 동일 ✓
- short_label 은 PHP·JS 양쪽에 존재하지만 T6 의 admin/coach 차트는 inline 매핑 (PHP 메타와 별도) — spec §12.3 의 known limitation 문서화됨 ✓

**4. Scope check**

7 task. 단일 spec, plug-in 작업. PHP backend(T1~T2) → JS frontend(T3~T4) → wire-up(T5) → admin/coach 통합(T6) → smoke(T7). 분해 불필요.

---

## Execution Handoff

Plan 작성·자체검토 완료. 저장: `docs/superpowers/plans/2026-05-08-pt-voice-intake.md`.

**실행 방식 두 가지:**

1. **Subagent-Driven** (recommended) — task 마다 fresh subagent, 사이에 리뷰
2. **Inline Execution** — 현 세션 batch 실행

어떤 방식?
