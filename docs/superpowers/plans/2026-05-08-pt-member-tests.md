# PT 회원 페이지 — 오감각 테스트 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PT 회원이 `pt.soritune.com/me/` 에서 소리튠 ID 또는 휴대폰번호로 로그인 후 오감각 테스트를 직접 응시하면 결과가 본인 차트에 저장되어 코치/어드민이 조회할 수 있게 한다 (구글폼 대체).

**Architecture:** 마케팅 페이지(test.soritune.com/sensestest)의 questions/categories 를 PT 내부로 복사 → PT 다크 테마로 재스타일링. 시험 러너는 questions/categories/scoring 을 데이터로 받아 돌아가는 범용 JS 엔진 (DISC 후속 spec 에서 같은 인프라 재사용). 채점은 서버에서 단일 source of truth (클라는 즉시 표시용 보조). 기존 `test_results` 테이블 재사용 (스키마 변경 없음).

**Tech Stack:** PHP 8 (PDO), Vanilla JS SPA, MySQL JSON 컬럼, 자체 PHP 테스트 러너 (`tests/_bootstrap.php` + `php tests/run_tests.php`).

**Spec:** `docs/superpowers/specs/2026-05-08-pt-member-tests-design.md`

**Working dir:** `/root/pt-dev` (DEV_PT, dev 브랜치). 모든 변경은 dev 에서. PROD 반영은 사용자 명시 요청 시.

---

## Conventions / Useful References

**테스트 실행**
```bash
cd /root/pt-dev && php tests/run_tests.php
```

**테스트 어설션 helpers** (in `tests/_bootstrap.php`):
- `t_section(string)` — 섹션 헤더
- `t_assert_eq($expected, $actual, $label)` — 정확 일치
- `t_assert_true(bool, $label)`
- `t_assert_throws(callable, $exceptionClass, $label)`
- `t_make_order($db, $opts)` — order + member 시드. 트랜잭션 내부에서 호출 후 caller 가 ROLLBACK

**기존 helpers** (in `public_html/includes/helpers.php`):
- `jsonSuccess(array $data, string $message='')` — 200 + `{ok:true, message, data}` 후 exit
- `jsonError(string $message, int $httpCode=400)` — 에러 + exit
- `getJsonInput(): array` — `php://input` JSON 디코드
- `normalizePhone(?string): ?string` — 한국 휴대폰 정규화 (`+82`/`82` → `010` prefix, 기호 제거). Spec 의 단순 regex 대신 본 함수 재사용.

**기존 auth helpers** (in `public_html/includes/auth.php`):
- `startAuthSession()` — `PT_SESSION` 쿠키, lifetime 24h
- `getCurrentUser(): ?array` — `$_SESSION['pt_user']` 반환
- `requireAdmin()` / `requireCoach()` / `requireAnyAuth()` — 401 가드

**JS API/UI helpers** (admin/coach 에만 정의됨, `/me/` 는 자체 정의):
- `API.get(url)` / `API.post(url, body)` — fetch 래퍼
- `UI.esc(str)` — HTML escape

**CSS 토큰** (`public_html/assets/css/style.css`):
- `--bg #121212`, `--surface #181818`, `--surface-card #252525`
- `--accent #FF5E00`, `--accent-border #E65500`, `--accent-dark #cc4b00`
- `--text #ffffff`, `--text-secondary #b3b3b3`, `--text-muted #cbcbcb`

---

## File Structure

**Create:**
- `public_html/includes/tests/sensory_meta.php` — 48문항 + 8 카테고리 + scoring 함수 (PHP 측 단일 source of truth)
- `public_html/api/member_auth.php` — login / logout / me
- `public_html/api/member_tests.php` — submit / latest / history (self)
- `public_html/me/index.php` — 회원 포털 entry shell
- `public_html/me/js/app.js` — SPA 라우터 + API/UI helpers
- `public_html/me/js/dashboard.js` — 시험 카드 대시보드
- `public_html/me/js/test-runner.js` — 범용 시험 러너
- `public_html/me/js/sensory.js` — 오감각 questions + categories + scoring (JS 측, PHP 와 동일)
- `public_html/me/js/result-view.js` — 회원용 결과 렌더 (특징·학습법 포함)
- `public_html/assets/css/member.css` — `/me/` 전용 스타일
- `tests/sensory_scoring_test.php` — PHP 채점 단위 테스트
- `tests/member_auth_test.php` — 로그인 매칭 로직 테스트
- `tests/member_tests_submit_test.php` — submit API 테스트
- `tests/member_tests_self_test.php` — 회원 본인 조회 테스트
- `tests/sensory_php_js_parity_test.php` — PHP↔JS questions 동기화 가드

**Modify:**
- `public_html/includes/auth.php` — `requireMember()` 헬퍼 추가
- `public_html/admin/js/pages/member-chart.js` — `formatTestData` → `formatSensoryResult` + legacy fallback (line ~370~423)
- `public_html/coach/js/pages/member-chart.js` — 동일 변경

---

## Task 1: PHP Sensory meta + scoring + tests

**Files:**
- Create: `public_html/includes/tests/sensory_meta.php`
- Create: `tests/sensory_scoring_test.php`

문항 48개와 카테고리 8개를 PHP 배열로 정의 + 채점 함수 `Sensory::score(array $answers)` 구현. 답변 검증 실패 시 `InvalidArgumentException`.

- [ ] **Step 1: questions/categories 데이터 + 채점 헤더만 작성 (test 가 import 가능하도록)**

Create `public_html/includes/tests/sensory_meta.php`:

```php
<?php
declare(strict_types=1);

/**
 * Sensory test meta — 단일 source of truth (PHP 측).
 * JS 측: public_html/me/js/sensory.js — questions/categories 같은 데이터로 동기화
 * 동기화 가드: tests/sensory_php_js_parity_test.php
 *
 * 변경 이력:
 *   v1 (2026-05-08): 마케팅 페이지(test.soritune.com/sensestest)의 48문항 + 8카테고리 그대로 복사
 */

final class Sensory
{
    public const VERSION = 1;

    /**
     * 48 questions, each tagged with type ∈ {'청각형','시각형','체각형'}.
     * 표시 순서는 마케팅 페이지와 동일.
     */
    public static function questions(): array
    {
        return [
            ['type' => '체각형', 'text' => '나는 힘이 별로 없고 쉽게 지친다'],
            ['type' => '청각형', 'text' => '때때로 나는 목소리 톤만으로 사람의 성격을 알 수 있다'],
            ['type' => '시각형', 'text' => '나는 종종 옷 입는 스타일을 통해 사람의 성격을 알 수 있다'],
            ['type' => '체각형', 'text' => '나는 스트레칭이나 운동을 즐긴다'],
            ['type' => '체각형', 'text' => '나는 내 침대에서 잠만 잘 수 있다'],
            ['type' => '체각형', 'text' => '나는 패션보다 편안한 것이 더 중요하다'],
            ['type' => '시각형', 'text' => '나는 영화 감상을 좋아한다'],
            ['type' => '시각형', 'text' => '나는 사람들 얼굴을 잘 기억한다'],
            ['type' => '시각형', 'text' => '나는 박물관 가는 것을 좋아한다'],
            ['type' => '시각형', 'text' => '나는 지저분한 것을 싫어한다'],
            ['type' => '체각형', 'text' => '나는 인조 섬유로 만든 옷을 싫어한다'],
            ['type' => '시각형', 'text' => '좋은 조명은 아름다운 집의 비밀이다'],
            ['type' => '청각형', 'text' => '나는 음악을 좋아한다'],
            ['type' => '시각형', 'text' => '나는 하늘 보는 것을 즐긴다'],
            ['type' => '체각형', 'text' => '나는 악수를 통해 사람의 성격을 알 수 있다'],
            ['type' => '청각형', 'text' => '나는 종종 혼자 콧노래를 흥얼거린다'],
            ['type' => '시각형', 'text' => '나는 미술 전시를 좋아한다'],
            ['type' => '체각형', 'text' => '나는 편한 옷만 입는다'],
            ['type' => '청각형', 'text' => '나는 깊은 대화와 토론을 즐긴다'],
            ['type' => '체각형', 'text' => '나는 더위가 좋다'],
            ['type' => '체각형', 'text' => '때때로 사람 사이에 손길이 말보다 더 분명한 의미를 전달한다'],
            ['type' => '시각형', 'text' => '내가 물건을 살 때, 색은 중요한 사항이다'],
            ['type' => '청각형', 'text' => '시끄러운 환경에서는 집중할 수가 없다'],
            ['type' => '청각형', 'text' => '걷는 소리를 듣고 사람을 알아볼 수 있다'],
            ['type' => '청각형', 'text' => '나는 다른 사람의 말투와 억양을 쉽게 따라 할 수 있다'],
            ['type' => '청각형', 'text' => '아주 조용하지 않으면 잠을 잘 수가 없어요'],
            ['type' => '시각형', 'text' => '나는 외모를 가꾸는 편이다'],
            ['type' => '청각형', 'text' => '나는 운전할 때 항상 음악이나 라디오를 듣는다'],
            ['type' => '체각형', 'text' => '나는 마사지를 좋아한다'],
            ['type' => '체각형', 'text' => '나는 내가 좋아하는 노래를 들으면 가만히 있기가 힘들다'],
            ['type' => '청각형', 'text' => '나는 빗소리를 너무 좋아한다'],
            ['type' => '시각형', 'text' => '나는 사람들이 보는 것을 즐긴다'],
            ['type' => '청각형', 'text' => '혼자있을 때, 나는 단지 사람의 목소리를 듣기 위해 텔레비전을 켠다'],
            ['type' => '체각형', 'text' => '나는 활동적인 사람이고 신체 활동을 즐긴다'],
            ['type' => '체각형', 'text' => '나는 춤추는 것을 좋아한다'],
            ['type' => '시각형', 'text' => '옷을 그냥 보기만 해도 살지 안 살지 결정할 수 있다'],
            ['type' => '청각형', 'text' => '옛날 멜로디를 들으면 과거의 추억이 떠오른다'],
            ['type' => '시각형', 'text' => '나는 그림, 사진, 영화 등 시각적인 모든 것을 감상한다'],
            ['type' => '시각형', 'text' => '나는 먹으면서 텔레비전 보는 것을 좋아한다'],
            ['type' => '청각형', 'text' => '나는 친구들과 동료들의 목소리를 쉽게 떠올릴 수 있고, 그들의 소리가 머릿속에서 들리는 듯하다'],
            ['type' => '청각형', 'text' => '나는 문자나 이메일보다는 전화로 말하는 것을 더 좋아한다'],
            ['type' => '시각형', 'text' => '나는 아름다운 것들에 둘러싸여 있는 것을 좋아한다'],
            ['type' => '체각형', 'text' => '스트레스를 받거나 걱정을 할 때 가슴에 신체적 압박감이 느껴진다'],
            ['type' => '체각형', 'text' => '나는 종종 뜨거운 물로 목욕을 하고 그것을 즐긴다'],
            ['type' => '청각형', 'text' => '나는 독서보다 오디오북을 더 좋아한다'],
            ['type' => '시각형', 'text' => '나는 플래너를 사용해서 프로젝트를 계속 추적하고 계획한다'],
            ['type' => '체각형', 'text' => '직장에서 안 좋은 하루를 보내면, 나는 긴장되고 긴장을 풀 수 없다'],
            ['type' => '청각형', 'text' => '나는 혼자 있을 때 혼잣말을 한다'],
        ];
    }

    /**
     * 8 categories — key 는 "auditory_bit,visual_bit,kinesthetic_bit" (각 비트는 50% 초과 여부).
     */
    public static function categories(): array
    {
        return [
            "0,0,0" => [
                'title'    => '균형형',
                'subtitle' => '어떤 감각도 아직 예민하지 않은 상태',
                'content'  => "<b>특징:</b>\n<ul>\n<li>어떤 감각도 아직 예민하지 않은 상태로, 특정 감각이 두드러지지 않습니다</li>\n<li>한 가지 방식에 의존한 학습보다는 모든 감각을 고르게 활용하는 훈련이 필요합니다</li>\n<li>시각·청각·체각을 골고루 자극하는 복합적인 학습을 통해 감각을 깨워야 합니다</li>\n</ul>",
                'learning' => "<b>추천 학습법:</b>\n<ul>\n<li>시각·청각·체각을 골고루 이용하는 복합 학습법이 가장 효과적</li>\n<li>영상을 보면서(시각) + 소리를 듣고 따라 하면서(청각) + 몸으로 리듬을 느끼며(체각) 훈련</li>\n<li>한 가지 감각에만 의존하지 말고 다양한 방식을 번갈아 사용하세요</li>\n</ul>",
                'courses'  => '소리튜닝 (정확한 소리 분석 및 훈련) / 소리블록 (패턴 기반 문장 확장 훈련) / 부트캠프 (종합적 훈련이 필요한 학습자에게 최적)',
            ],
            "0,0,1" => [
                'title'    => '체각형 우세 학습자',
                'subtitle' => '몸을 움직이며 학습하는 스타일',
                'content'  => "<b>특징:</b>\n<ul>\n<li>움직이면서 배우는 스타일, 필기보다는 실습이 중요</li>\n<li>제스처, 롤플레잉, 몸으로 익히는 학습법이 효과적</li>\n<li>체험형 학습이 중요하며, 실전 상황에 익숙해져야 함</li>\n</ul>",
                'learning' => "<b>추천 학습법:</b>\n<ul>\n<li>실전 회화 & 롤플레잉 → 직접 대화하면서 문장 익히기</li>\n<li>몸으로 익히는 발음 트레이닝 → 손동작 활용, 입 모양 체크</li>\n<li>음성 따라 말하기 연습 → 실전적인 발음 & 억양 훈련</li>\n<li>훈련 시 박수를 치며 리듬을 몸으로 느끼며 훈련하면 효과적입니다</li>\n</ul>",
                'courses'  => '소리블록 (문장을 직접 만들어보고 확장하는 과정) / 1:1 화상PT (실제 대화 & 피드백 제공) / 부트캠프 (체계적인 실전 훈련)',
            ],
            "0,1,0" => [
                'title'    => '시각형 우세 학습자',
                'subtitle' => '영상, 이미지 활용 학습이 효과적',
                'content'  => "<b>특징:</b>\n<ul>\n<li>비주얼 자료가 학습에 중요한 역할</li>\n<li>영상을 보면서 배우거나, 정리하면서 학습하는 것이 효과적</li>\n<li>패턴을 분석하거나 시각적으로 기억하는 것이 유리함</li>\n<li>그동안 쉐도잉 방식의 영어 훈련을 시도했지만 효과를 보지 못했을 가능성이 있습니다 — 시각형은 소리만 듣고 따라 하는 방식이 잘 맞지 않을 수 있습니다</li>\n</ul>",
                'learning' => "<b>추천 학습법:</b>\n<ul>\n<li>영상 & 이미지 학습법 → 소리튜닝 훈련 시 입 모양 영상 분석</li>\n<li>마인드맵 & 정리 노트 활용 → 문장 구조를 시각적으로 정리</li>\n<li>패턴 분석 → 텍스트와 소리를 함께 학습</li>\n<li>소리튜닝을 할 때 반드시 1:1 코칭을 병행해야 효과적입니다</li>\n</ul>",
                'courses'  => '1:1 코칭 병행 필수 (시각형에게 가장 중요!) / 소리튜닝 (영상 자료 기반 발음 분석) / 소리블록 (문장 패턴 분석 & 확장) / 1:1 음성PT (소리 튜닝을 듣고 피드백 받기)',
            ],
            "0,1,1" => [
                'title'    => '시각 + 체각 혼합 학습자',
                'subtitle' => '눈으로 보고, 몸으로 익히는 스타일',
                'content'  => "<b>특징:</b>\n<ul>\n<li>이미지를 보고 이해하고, 직접 경험하며 배우는 스타일</li>\n<li>정적인 필기보다는 손으로 정리하거나 실습하며 학습하는 것이 효과적</li>\n<li>제스처 & 영상 자료를 적극 활용하는 학습법이 적합</li>\n</ul>",
                'learning' => "<b>추천 학습법:</b>\n<ul>\n<li>영상 보면서 따라 하는 실습형 학습</li>\n<li>필기 & 정리하며 배우기 (손으로 직접 정리)</li>\n<li>제스처 활용한 말하기 연습</li>\n</ul>",
                'courses'  => '소리튜닝 (입 모양, 제스처 활용한 발음 분석) / 소리블록 (패턴 연습 & 실전 대화 훈련) / 부트캠프 (실전 롤플레잉 연습)',
            ],
            "1,0,0" => [
                'title'    => '청각형 우세 학습자',
                'subtitle' => '소리를 활용한 학습이 효과적',
                'content'  => "<b>특징:</b>\n<ul>\n<li>듣기와 말하기 중심 학습이 효과적</li>\n<li>글을 읽는 것보다 소리를 듣고 따라 하는 방식이 적합</li>\n<li>반복 청취 & 쉐도잉이 핵심</li>\n<li>소리튜닝 학습 시 가장 빠르게 성장할 수 있는 유형입니다</li>\n</ul>",
                'learning' => "<b>추천 학습법:</b>\n<ul>\n<li>리스닝 집중 학습</li>\n<li>소리 분석 후 따라 말하기</li>\n<li>리듬 & 억양 중심 말하기 훈련</li>\n<li>소리블록만으로도 소리가 바뀔 수 있습니다 — 소리블록 훈련을 적극 활용하세요</li>\n</ul>",
                'courses'  => '소리블록 (청각형에게 가장 효과적!) / 소리튜닝 (소리 분석 및 훈련) / 1:1 음성PT (발음 & 억양 교정) / 부트캠프 (실전 훈련 & 소리 튜닝 적용)',
            ],
            "1,0,1" => [
                'title'    => '청각 + 체각 혼합 학습자',
                'subtitle' => '소리를 듣고 몸을 움직이며 학습하는 스타일',
                'content'  => "<b>특징:</b>\n<ul>\n<li>소리를 듣고 따라 하면서 익히는 방식이 효과적</li>\n<li>필기보다는 몸으로 익히는 액션 기반 학습법이 적합</li>\n<li>리듬, 억양을 자연스럽게 익히는 학습이 필요</li>\n</ul>",
                'learning' => "<b>추천 학습법:</b>\n<ul>\n<li>리듬 & 박자 맞춰 말하기 연습</li>\n<li>제스처 활용 학습</li>\n<li>실제 대화 롤플레잉 훈련</li>\n</ul>",
                'courses'  => '소리튜닝 (억양 & 리듬 분석) / 1:1 화상PT (실전 롤플레잉 연습) / 부트캠프 (강한 실전 적용 훈련)',
            ],
            "1,1,0" => [
                'title'    => '청각 + 시각 혼합 학습자',
                'subtitle' => '소리와 이미지를 결합한 학습법이 효과적',
                'content'  => "<b>특징:</b>\n<ul>\n<li>듣기 + 시각적 자료 활용이 중요</li>\n<li>영상 & 오디오 학습법이 효과적</li>\n<li>글보다는 이미지 & 음성을 통한 학습이 적합</li>\n</ul>",
                'learning' => "<b>추천 학습법:</b>\n<ul>\n<li>영상 + 오디오 활용 학습</li>\n<li>소리튜닝 후 패턴 정리하여 분석</li>\n<li>쉐도잉 & 패턴 학습법 활용</li>\n</ul>",
                'courses'  => '소리튜닝 (영상 + 음성 결합 학습) / 소리블록 (패턴 기반 확장 학습) / 부트캠프 (실전 적용 연습)',
            ],
            "1,1,1" => [
                'title'    => '완전한 멀티 감각형 학습자',
                'subtitle' => '모든 감각이 골고루 발달 — 훈련 시 빠른 성장이 가능',
                'content'  => "<b>특징:</b>\n<ul>\n<li>모든 감각이 골고루 발달되어 있어 어떤 학습 방식이든 잘 받아들일 수 있습니다</li>\n<li>훈련을 시작하면 다른 유형보다 빠르게 성장할 수 있는 잠재력이 있습니다</li>\n<li>다양한 감각을 동시에 활용하는 종합 훈련이 가장 효과적입니다</li>\n</ul>",
                'learning' => "<b>추천 학습법:</b>\n<ul>\n<li>다양한 감각을 동시에 활용하는 종합 훈련을 권장합니다</li>\n<li>영상 + 오디오 + 실전 연습을 병행하여 모든 감각을 자극하세요</li>\n<li>골고루 발달된 감각을 최대한 활용하면 빠른 성장이 가능합니다</li>\n</ul>",
                'courses'  => '소리튜닝 + 소리블록 병행 / 1:1 음성PT & 화상PT 조합 / 부트캠프 (올인원 학습 과정으로 최적화!)',
            ],
        ];
    }

    /**
     * 응답 배열을 받아 result_data v1 JSON 빌드.
     *
     * @param int[] $answers 정확히 48개, 각 값 ∈ {0,1}
     * @return array result_data (version, answers, scores, percents, key, title, subtitle, submitted_at)
     * @throws InvalidArgumentException 길이/값 검증 실패
     */
    public static function score(array $answers): array
    {
        $questions = self::questions();
        $expected = count($questions);
        if (count($answers) !== $expected) {
            throw new InvalidArgumentException("answers length must be {$expected}");
        }
        foreach ($answers as $i => $a) {
            if ($a !== 0 && $a !== 1) {
                throw new InvalidArgumentException("answers[{$i}] must be 0 or 1");
            }
        }

        $typeMap = ['청각형' => 'auditory', '시각형' => 'visual', '체각형' => 'kinesthetic'];
        $scores = [
            'auditory'    => ['checked' => 0, 'total' => 0],
            'visual'      => ['checked' => 0, 'total' => 0],
            'kinesthetic' => ['checked' => 0, 'total' => 0],
        ];

        foreach ($questions as $i => $q) {
            $key = $typeMap[$q['type']];
            $scores[$key]['total']++;
            if ($answers[$i] === 1) $scores[$key]['checked']++;
        }

        $percents = [];
        foreach ($scores as $k => $s) {
            $percents[$k] = $s['total'] > 0 ? (int)round($s['checked'] / $s['total'] * 100) : 0;
        }

        // key: auditory, visual, kinesthetic 순서 (마케팅 페이지와 동일)
        $bits = [];
        foreach (['auditory', 'visual', 'kinesthetic'] as $k) {
            $ratio = $scores[$k]['total'] > 0 ? $scores[$k]['checked'] / $scores[$k]['total'] : 0;
            $bits[] = $ratio > 0.5 ? '1' : '0';
        }
        $key = implode(',', $bits);

        $cat = self::categories()[$key];

        return [
            'version'      => self::VERSION,
            'answers'      => array_values(array_map('intval', $answers)),
            'scores'       => $scores,
            'percents'     => $percents,
            'key'          => $key,
            'title'        => $cat['title'],
            'subtitle'     => $cat['subtitle'],
            'submitted_at' => date('c'),
        ];
    }
}
```

- [ ] **Step 2: Write failing tests**

Create `tests/sensory_scoring_test.php`:

```php
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
t_assert_eq(12, $typeCounts['청각형'], '청각형=12');
t_assert_eq(14, $typeCounts['시각형'], '시각형=14');
t_assert_eq(22, $typeCounts['체각형'], '체각형=22');

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
// 청각형 12문항 중 6개 체크 → 50% → bit=0
$answers = [];
$auditoryChecked = 0;
foreach (Sensory::questions() as $q) {
    if ($q['type'] === '청각형') {
        $answers[] = $auditoryChecked < 6 ? 1 : 0;
        $auditoryChecked++;
    } else {
        $answers[] = 0;
    }
}
$r = Sensory::score($answers);
t_assert_eq('0,0,0', $r['key'], '청각 6/12=50% → bit=0 → key=0,0,0');
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
```

- [ ] **Step 3: Run tests — should PASS now (we wrote impl in Step 1)**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -40
```

Expected: All asserts in `>>> sensory_scoring_test.php` section pass. `Total ... Fail: 0`.

- [ ] **Step 4: Commit**

```bash
cd /root/pt-dev && git add public_html/includes/tests/sensory_meta.php tests/sensory_scoring_test.php && git commit -m "$(cat <<'EOF'
feat(pt): 오감각 시험 PHP meta + 채점 단일 source

- 48문항 + 8카테고리 + Sensory::score() — 마케팅 페이지 v1 그대로 복사
- result_data v1 JSON (version/answers/scores/percents/key/title/subtitle/submitted_at)
- 50% 경계는 0 (>50% 만 1)
- 길이/값 검증 InvalidArgumentException

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: requireMember() helper

**Files:**
- Modify: `public_html/includes/auth.php` (append after `requireAnyAuth()`, line 49)

`requireMember()` 가드 추가 — 비로그인 또는 다른 role 이면 401 + JSON.

- [ ] **Step 1: Add helper**

In `public_html/includes/auth.php`, append after the existing `requireAnyAuth()` function:

```php
function requireMember(): array {
    $user = getCurrentUser();
    if (!$user || ($user['role'] ?? null) !== 'member') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => '회원 로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $user;
}
```

- [ ] **Step 2: Smoke check (no test yet — covered by member_auth_test in T3)**

```bash
cd /root/pt-dev && php -l public_html/includes/auth.php
```

Expected: `No syntax errors detected ...`

- [ ] **Step 3: Commit**

```bash
cd /root/pt-dev && git add public_html/includes/auth.php && git commit -m "feat(pt): requireMember() 가드 추가 — admin/coach 와 동일 패턴

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: api/member_auth.php — login + logout + me + tests

**Files:**
- Create: `public_html/api/member_auth.php`
- Create: `tests/member_auth_test.php`

회원 로그인은 비밀번호 없이 ID 또는 폰 룩업. spec 6.1 자동 판별 로직.

- [ ] **Step 1: Write failing tests**

Create `tests/member_auth_test.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';

/**
 * lookupMemberByInput() — api/member_auth.php 내부 함수를 테스트.
 * 호출 가능하도록 require 후 테스트한다.
 */
require_once __DIR__ . '/../public_html/api/member_auth.php';

t_section('lookupMemberByInput — 빈 입력');
$db = getDB();
t_assert_eq(null, lookupMemberByInput($db, ''), '빈 문자열');
t_assert_eq(null, lookupMemberByInput($db, '   '), '공백만');

t_section('lookupMemberByInput — soritune_id 매칭');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_login_a', '회원A', '01011112222']);
$idA = (int)$db->lastInsertId();

$m = lookupMemberByInput($db, 't_login_a');
t_assert_eq($idA, $m['id'] ?? null, 'soritune_id 정확 매칭');
$db->rollBack();

t_section('lookupMemberByInput — phone 정규화');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_login_b', '회원B', '01033334444']);
$idB = (int)$db->lastInsertId();

$m = lookupMemberByInput($db, '010-3333-4444');
t_assert_eq($idB, $m['id'] ?? null, 'phone 하이픈 정규화');

$m = lookupMemberByInput($db, '+82 10 3333 4444');
t_assert_eq($idB, $m['id'] ?? null, 'phone +82 정규화');

$m = lookupMemberByInput($db, '01033334444');
t_assert_eq($idB, $m['id'] ?? null, 'phone digits-only');
$db->rollBack();

t_section('lookupMemberByInput — 매칭 없음');
$db->beginTransaction();
$m = lookupMemberByInput($db, 'nonexistent_user_xyz');
t_assert_eq(null, $m, 'soritune_id 없음');
$m = lookupMemberByInput($db, '01099998888');
t_assert_eq(null, $m, 'phone 없음');
$db->rollBack();

t_section('lookupMemberByInput — 다중 매칭 → created_at DESC LIMIT 1');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone, created_at) VALUES (?, ?, ?, ?)")
   ->execute(['t_dup_old', '오래된회원', '01055556666', '2024-01-01 00:00:00']);
$db->prepare("INSERT INTO members (soritune_id, name, phone, created_at) VALUES (?, ?, ?, ?)")
   ->execute(['t_dup_new', '최신회원',   '01055556666', '2026-01-01 00:00:00']);
$idNew = (int)$db->lastInsertId();

$m = lookupMemberByInput($db, '01055556666');
t_assert_eq($idNew, $m['id'] ?? null, '같은 폰 → 최신 회원 선택');
$db->rollBack();

t_section('lookupMemberByInput — 병합된 회원 follow-through');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_primary', 'Primary회원', '01077778888']);
$idPrimary = (int)$db->lastInsertId();
$db->prepare("INSERT INTO members (soritune_id, name, phone, merged_into) VALUES (?, ?, ?, ?)")
   ->execute(['t_merged', '병합된회원', '01099990000', $idPrimary]);

$m = lookupMemberByInput($db, 't_merged');
t_assert_eq($idPrimary, $m['id'] ?? null, 'merged → primary follow');
$db->rollBack();
```

- [ ] **Step 2: Run tests — should FAIL (file/function not exist)**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -20
```

Expected: include error or `lookupMemberByInput` undefined.

- [ ] **Step 3: Implement member_auth.php**

Create `public_html/api/member_auth.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * 입력값(소리튠 ID 또는 휴대폰) → members row 또는 null.
 * 다중 매칭은 created_at DESC LIMIT 1.
 * merged_into 가 있으면 primary 까지 follow (최대 5단).
 *
 * 별도 함수로 분리한 이유: 단위 테스트 가능 + 향후 다른 곳에서도 재사용
 */
function lookupMemberByInput(PDO $db, string $raw): ?array
{
    $input = trim($raw);
    if ($input === '') return null;

    $digitsOnly = preg_replace('/\D/', '', $input);
    $isPhoneLike = $digitsOnly !== '' && strlen($digitsOnly) >= 8;

    // phone 정규화 — 기존 helpers.php 의 normalizePhone() 재사용 (+82/82 → 010 처리)
    $phoneCandidate = $isPhoneLike ? normalizePhone($input) : null;

    $bySoritune = function () use ($db, $input): ?array {
        $stmt = $db->prepare(
            "SELECT id, soritune_id, name, phone, merged_into
             FROM members WHERE soritune_id = ?
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$input]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };

    $byPhone = function () use ($db, $phoneCandidate): ?array {
        if ($phoneCandidate === null) return null;
        $stmt = $db->prepare(
            "SELECT id, soritune_id, name, phone, merged_into
             FROM members WHERE phone = ?
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$phoneCandidate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };

    $member = $isPhoneLike ? ($byPhone() ?? $bySoritune()) : ($bySoritune() ?? $byPhone());
    if ($member === null) return null;

    // merged_into follow-through (최대 5단 — 무한루프 방지)
    $hops = 0;
    while ($member['merged_into'] !== null && $hops < 5) {
        $stmt = $db->prepare(
            "SELECT id, soritune_id, name, phone, merged_into FROM members WHERE id = ?"
        );
        $stmt->execute([$member['merged_into']]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$next) break;
        $member = $next;
        $hops++;
    }

    return $member;
}

// 테스트에서 require 했을 때는 라우팅 분기 실행 안 함
if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'member_auth.php') {
    return;
}

header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'login': {
        $body = getJsonInput();
        $input = (string)($body['input'] ?? '');
        if (trim($input) === '') {
            jsonError('소리튠 아이디 또는 휴대폰번호를 입력해주세요', 400);
        }
        $member = lookupMemberByInput($db, $input);
        if (!$member) {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'code' => 'NOT_FOUND',
                'message' => '입력하신 정보로 등록된 회원을 찾을 수 없습니다. 고객센터로 문의해주세요.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        startAuthSession();
        session_regenerate_id(true);
        $_SESSION['pt_user'] = [
            'id'          => (int)$member['id'],
            'role'        => 'member',
            'soritune_id' => $member['soritune_id'],
            'name'        => $member['name'],
        ];
        jsonSuccess([
            'member' => [
                'id'          => (int)$member['id'],
                'soritune_id' => $member['soritune_id'],
                'name'        => $member['name'],
            ],
        ], '로그인 성공');
    }

    case 'logout': {
        startAuthSession();
        $_SESSION = [];
        session_destroy();
        jsonSuccess([], '로그아웃 되었습니다');
    }

    case 'me': {
        $user = getCurrentUser();
        if (!$user || ($user['role'] ?? null) !== 'member') {
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'code' => 'UNAUTHENTICATED',
                'message' => '회원 로그인이 필요합니다',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        jsonSuccess([
            'member' => [
                'id'          => (int)$user['id'],
                'soritune_id' => $user['soritune_id'] ?? null,
                'name'        => $user['name'] ?? null,
            ],
        ]);
    }

    default:
        jsonError('알 수 없는 액션입니다', 400);
}
```

**Note:** The `if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'member_auth.php') return;` guard lets the test `require_once` the file just to load `lookupMemberByInput()` without triggering the action switch. When served by Apache, `SCRIPT_NAME` ends in `member_auth.php`, so the switch runs.

- [ ] **Step 4: Run tests — should PASS**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -30
```

Expected: All `lookupMemberByInput` asserts pass.

- [ ] **Step 5: Commit**

```bash
cd /root/pt-dev && git add public_html/api/member_auth.php tests/member_auth_test.php && git commit -m "$(cat <<'EOF'
feat(pt): /api/member_auth.php — login/logout/me + lookupMemberByInput

- soritune_id / phone 자동 판별 (8자리+ 숫자면 phone 우선)
- normalizePhone() 재사용 (+82 / 하이픈 정규화)
- 다중 매칭 → created_at DESC LIMIT 1
- merged_into → primary follow-through (최대 5단)
- session_regenerate_id 로 fixation 방지
- 매칭 실패 → NOT_FOUND + 고객센터 안내 메시지

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: api/member_tests.php — submit + tests

**Files:**
- Create: `public_html/api/member_tests.php`
- Create: `tests/member_tests_submit_test.php`

회원이 시험 결과를 제출 → 서버에서 재채점 → `test_results` insert.

- [ ] **Step 1: Write failing tests**

Create `tests/member_tests_submit_test.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';
require_once __DIR__ . '/../public_html/includes/tests/sensory_meta.php';

/**
 * memberTestsSubmitImpl(PDO $db, array $user, array $input): array
 *   $user: ['id' => int, 'role' => 'member', ...] (세션 가정)
 *   반환: jsonSuccess 호출 직전의 data 또는 throw RuntimeException(message, code)
 *
 * api/member_tests.php 가 라우팅에서 호출. 분리한 이유: 세션·HTTP 우회 단위테스트.
 */
require_once __DIR__ . '/../public_html/api/member_tests.php';

function _seed_member(PDO $db, string $sori = 'm_test'): int
{
    $db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
       ->execute([$sori . '_' . uniqid(), '테스트회원', '01000000000']);
    return (int)$db->lastInsertId();
}

t_section('memberTestsSubmitImpl — 정상 sensory submit');
$db = getDB();
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$answers = array_fill(0, 48, 0);
$res = memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => $answers]);

t_assert_true(isset($res['result_id']), 'result_id present');
t_assert_eq(1, $res['result_data']['version'], 'version=1');
t_assert_eq('0,0,0', $res['result_data']['key'], 'all-0 → key=0,0,0');
t_assert_eq('균형형', $res['result_data']['title'], 'title=균형형');

$row = $db->prepare("SELECT * FROM test_results WHERE id = ?");
$row->execute([$res['result_id']]);
$saved = $row->fetch(PDO::FETCH_ASSOC);
t_assert_eq($mid, (int)$saved['member_id'], 'member_id matches session');
t_assert_eq('sensory', $saved['test_type'], 'test_type=sensory');
$db->rollBack();

t_section('memberTestsSubmitImpl — 위조한 key 무시');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
// 클라가 위조한 key/title 보내도 서버 재계산 결과만 저장
$res = memberTestsSubmitImpl($db, $user, [
    'test_type' => 'sensory',
    'answers'   => array_fill(0, 48, 0),
    'key'       => '1,1,1',           // 위조
    'title'     => '완전한 멀티 감각형 학습자', // 위조
]);
t_assert_eq('0,0,0', $res['result_data']['key'], '위조 key 무시 → 서버 재계산');
$db->rollBack();

t_section('memberTestsSubmitImpl — 길이 검증');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 47, 0)]),
    InvalidArgumentException::class,
    'length=47 throws'
);
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 49, 0)]),
    InvalidArgumentException::class,
    'length=49 throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — 값 검증');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$bad = array_fill(0, 48, 0);
$bad[10] = 2;
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => $bad]),
    InvalidArgumentException::class,
    'value=2 throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — test_type 화이트리스트');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
t_assert_throws(
    fn() => memberTestsSubmitImpl($db, $user, ['test_type' => 'unknown', 'answers' => array_fill(0, 48, 0)]),
    InvalidArgumentException::class,
    'unknown test_type throws'
);
$db->rollBack();

t_section('memberTestsSubmitImpl — 같은 회원 같은 날 두 번 → 두 row');
$db->beginTransaction();
$mid = _seed_member($db);
$user = ['id' => $mid, 'role' => 'member'];
$r1 = memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 48, 0)]);
$r2 = memberTestsSubmitImpl($db, $user, ['test_type' => 'sensory', 'answers' => array_fill(0, 48, 1)]);
t_assert_true($r2['result_id'] > $r1['result_id'], 'second id > first');

$cnt = $db->prepare("SELECT COUNT(*) FROM test_results WHERE member_id = ? AND test_type = 'sensory'");
$cnt->execute([$mid]);
t_assert_eq(2, (int)$cnt->fetchColumn(), '두 row 존재');
$db->rollBack();
```

- [ ] **Step 2: Run tests — should FAIL (file not exist)**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -10
```

Expected: include error.

- [ ] **Step 3: Implement member_tests.php (submit only — latest/history in T5)**

Create `public_html/api/member_tests.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/tests/sensory_meta.php';

/**
 * Submit 본 로직 — 세션·HTTP 우회해서 단위테스트 가능하도록 분리.
 *
 * @param PDO   $db
 * @param array $user  ['id' => int, 'role' => 'member']
 * @param array $input ['test_type' => 'sensory', 'answers' => int[]]
 * @return array       ['result_id' => int, 'result_data' => array]
 * @throws InvalidArgumentException 검증 실패
 */
function memberTestsSubmitImpl(PDO $db, array $user, array $input): array
{
    $testType = $input['test_type'] ?? '';
    if (!in_array($testType, ['sensory'], true)) {
        // DISC 는 별도 spec — 본 spec 에서는 sensory 만
        throw new InvalidArgumentException("test_type must be 'sensory'");
    }

    $answers = $input['answers'] ?? null;
    if (!is_array($answers)) {
        throw new InvalidArgumentException('answers must be an array');
    }
    // 정수 캐스팅 후 Sensory::score 가 길이/값 검증
    $answers = array_map(static fn($a) => is_int($a) ? $a : (is_numeric($a) ? (int)$a : -1), $answers);

    $resultData = Sensory::score($answers); // throws InvalidArgumentException

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

// 테스트가 require 했을 때는 라우팅 실행 안 함
if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'member_tests.php') {
    return;
}

header('Content-Type: application/json; charset=utf-8');
$user = requireMember();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'submit': {
        $input = getJsonInput();
        try {
            $out = memberTestsSubmitImpl($db, $user, $input);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 400);
        }
        jsonSuccess($out, '결과가 저장되었습니다');
    }

    default:
        jsonError('알 수 없는 액션입니다', 400);
}
```

- [ ] **Step 4: Run tests — should PASS**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -30
```

Expected: All `memberTestsSubmitImpl` asserts pass.

- [ ] **Step 5: Commit**

```bash
cd /root/pt-dev && git add public_html/api/member_tests.php tests/member_tests_submit_test.php && git commit -m "$(cat <<'EOF'
feat(pt): /api/member_tests.php submit — 회원 셀프 시험 결과 저장

- 서버 재채점 (Sensory::score) — 클라가 보낸 key/title 무시 (위조 방지)
- test_type 화이트리스트 (현재 sensory 만)
- requireMember() 가드 + InvalidArgumentException → 400
- memberTestsSubmitImpl() 분리로 세션 우회 단위테스트 가능

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: member_tests.php — latest + history (self) + tests

**Files:**
- Modify: `public_html/api/member_tests.php` (add `latest` and `history` cases)
- Create: `tests/member_tests_self_test.php`

회원 본인이 자기 결과 조회.

- [ ] **Step 1: Write failing tests**

Create `tests/member_tests_self_test.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';
require_once __DIR__ . '/../public_html/api/member_tests.php';

t_section('memberTestsLatestImpl — 결과 없음');
$db = getDB();
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_a', '회원A', '01011110001']);
$mid = (int)$db->lastInsertId();
$user = ['id' => $mid, 'role' => 'member'];
$res = memberTestsLatestImpl($db, $user, 'sensory');
t_assert_eq(null, $res['result'], '미응시 → result=null');
$db->rollBack();

t_section('memberTestsLatestImpl — 본인 최신 row');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_b', '회원B', '01011110002']);
$mid = (int)$db->lastInsertId();
$user = ['id' => $mid, 'role' => 'member'];

$db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at) VALUES (?, ?, ?, ?)")
   ->execute([$mid, 'sensory', json_encode(['version'=>1,'key'=>'0,0,0','title'=>'균형형']), '2026-04-01']);
$db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at) VALUES (?, ?, ?, ?)")
   ->execute([$mid, 'sensory', json_encode(['version'=>1,'key'=>'1,1,1','title'=>'완전한 멀티 감각형 학습자']), '2026-05-08']);

$res = memberTestsLatestImpl($db, $user, 'sensory');
t_assert_eq('1,1,1', $res['result']['result_data']['key'], 'latest 가 더 최근');
t_assert_eq('2026-05-08', $res['result']['tested_at'], 'tested_at 최신');
$db->rollBack();

t_section('memberTestsLatestImpl — 다른 회원 결과 안 보임');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_c1', '회원C1', '01011110003']);
$midC1 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_c2', '회원C2', '01011110004']);
$midC2 = (int)$db->lastInsertId();

// C2 만 결과 있음
$db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at) VALUES (?, ?, ?, ?)")
   ->execute([$midC2, 'sensory', json_encode(['version'=>1,'key'=>'1,1,1','title'=>'완전한 멀티 감각형 학습자']), '2026-05-08']);

// C1 으로 조회 → null
$user = ['id' => $midC1, 'role' => 'member'];
$res = memberTestsLatestImpl($db, $user, 'sensory');
t_assert_eq(null, $res['result'], 'C1 으로 조회 — C2 결과 안 보임');
$db->rollBack();

t_section('memberTestsLatestImpl — sensory/disc 분리');
$db->beginTransaction();
$db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
   ->execute(['t_self_d', '회원D', '01011110005']);
$mid = (int)$db->lastInsertId();
$user = ['id' => $mid, 'role' => 'member'];
$db->prepare("INSERT INTO test_results (member_id, test_type, result_data, tested_at) VALUES (?, ?, ?, ?)")
   ->execute([$mid, 'sensory', json_encode(['version'=>1,'key'=>'1,1,1','title'=>'X']), '2026-05-08']);

$res = memberTestsLatestImpl($db, $user, 'sensory');
t_assert_true($res['result'] !== null, 'sensory 있음');
$res = memberTestsLatestImpl($db, $user, 'disc');
t_assert_eq(null, $res['result'], 'disc 없음');
$db->rollBack();
```

- [ ] **Step 2: Run tests — should FAIL (function not exist)**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -10
```

Expected: `memberTestsLatestImpl` undefined.

- [ ] **Step 3: Add `memberTestsLatestImpl` + extend switch**

Edit `public_html/api/member_tests.php` — add `memberTestsLatestImpl()` above the `if (basename(...))` guard:

```php
/**
 * 회원 본인의 최신 결과 1건 조회.
 *
 * @return array ['result' => array|null]
 */
function memberTestsLatestImpl(PDO $db, array $user, string $testType): array
{
    if (!in_array($testType, ['sensory', 'disc'], true)) {
        throw new InvalidArgumentException("test_type must be sensory|disc");
    }
    $stmt = $db->prepare(
        "SELECT id, member_id, test_type, result_data, tested_at, memo, created_at
         FROM test_results
         WHERE member_id = ? AND test_type = ?
         ORDER BY tested_at DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([(int)$user['id'], $testType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['result' => null];

    $row['result_data'] = json_decode($row['result_data'], true);
    return ['result' => $row];
}
```

In the switch, add a `case 'latest':` block before `default`:

```php
    case 'latest': {
        $testType = (string)($_GET['test_type'] ?? '');
        try {
            $out = memberTestsLatestImpl($db, $user, $testType);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 400);
        }
        jsonSuccess($out);
    }
```

- [ ] **Step 4: Run tests — should PASS**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -30
```

Expected: All `memberTestsLatestImpl` asserts pass.

- [ ] **Step 5: Commit**

```bash
cd /root/pt-dev && git add public_html/api/member_tests.php tests/member_tests_self_test.php && git commit -m "$(cat <<'EOF'
feat(pt): member_tests.php latest — 회원 본인 최신 결과 조회

- 본인 (member_id = 세션 id) row 만 — URL 파라미터 무시
- tested_at DESC, id DESC tiebreaker
- 결과 없음 → result=null

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: /me/index.php entry shell

**Files:**
- Create: `public_html/me/index.php`

회원 포털 entry — 로그인 상태에 따라 같은 SPA 가 분기. admin/coach 와 같은 패턴.

- [ ] **Step 1: Create file**

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
$user = getCurrentUser();
$isLoggedIn = $user && ($user['role'] ?? null) === 'member';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SoriTune PT — 회원</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.min.css">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/member.css">
<script>
if (location.hostname.startsWith('dev-')) {
  document.addEventListener('DOMContentLoaded', () => {
    const badge = document.createElement('div');
    badge.id = 'dev-badge';
    badge.textContent = 'DEV';
    badge.style.cssText = 'position:fixed;top:0;left:50%;transform:translateX(-50%);z-index:99999;background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:2px 12px;border-radius:0 0 6px 6px;letter-spacing:1px;pointer-events:none;opacity:0.9;';
    document.body.appendChild(badge);
  });
}
window.__BOOT__ = { isLoggedIn: <?= $isLoggedIn ? 'true' : 'false' ?> };
</script>
</head>
<body class="me-body">
<div id="meRoot"></div>
<script src="/me/js/app.js"></script>
<script src="/me/js/dashboard.js"></script>
<script src="/me/js/test-runner.js"></script>
<script src="/me/js/sensory.js"></script>
<script src="/me/js/result-view.js"></script>
<script>MeApp.init();</script>
</body>
</html>
```

- [ ] **Step 2: PHP lint**

```bash
cd /root/pt-dev && php -l public_html/me/index.php
```

Expected: `No syntax errors detected ...`

- [ ] **Step 3: Commit**

```bash
cd /root/pt-dev && git add public_html/me/index.php && git commit -m "feat(pt): /me/ entry shell — 회원 포털 SPA

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: /me/js/app.js — router + helpers

**Files:**
- Create: `public_html/me/js/app.js`

SPA 라우터 + 자체 API/UI 헬퍼. admin/js/app.js 와 같은 스타일이지만 `/me/` 자체 namespace `MeApp`.

- [ ] **Step 1: Create file**

```js
'use strict';

const MeAPI = {
  async request(url, options = {}) {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
      ...options,
    });
    let data = {};
    try { data = await res.json(); } catch (_) { data = { ok: false, message: 'Invalid response' }; }
    return data;
  },
  get(url) { return this.request(url); },
  post(url, body) { return this.request(url, { method: 'POST', body: JSON.stringify(body || {}) }); },
};

const MeUI = {
  esc(s) {
    const el = document.createElement('span');
    el.textContent = s == null ? '' : String(s);
    return el.innerHTML;
  },
  formatDate(d) { return d ? String(d).split(' ')[0] : '-'; },
};

const MeApp = {
  state: { member: null, view: 'login' },
  root: null,

  async init() {
    this.root = document.getElementById('meRoot');
    const res = await MeAPI.get('/api/member_auth.php?action=me');
    if (res.ok) {
      this.state.member = res.data.member;
      this.go('dashboard');
    } else {
      this.go('login');
    }
  },

  go(view, params = {}) {
    this.state.view = view;
    this.state.params = params;
    this.render();
  },

  render() {
    const { view, member, params } = this.state;
    if (view === 'login') {
      this.root.innerHTML = this.renderLogin();
      this.bindLogin();
    } else if (view === 'dashboard') {
      MeDashboard.render(this.root, member);
    } else if (view === 'test') {
      MeTestRunner.start(this.root, params.testType, member);
    } else if (view === 'result') {
      MeResultView.render(this.root, params.testType, params.resultData);
    }
  },

  renderLogin() {
    return `
      <div class="me-login-wrap">
        <div class="me-login-card">
          <div class="me-login-logo">SoriTune PT</div>
          <div class="me-login-subtitle">회원 페이지</div>
          <form id="meLoginForm">
            <input type="text" id="meLoginInput" class="me-input" placeholder="소리튠 아이디 또는 휴대폰번호" autocomplete="username" autofocus>
            <button type="submit" class="me-btn me-btn-primary">시작하기</button>
            <div id="meLoginError" class="me-login-error"></div>
          </form>
        </div>
      </div>
    `;
  },

  bindLogin() {
    const form = document.getElementById('meLoginForm');
    const errEl = document.getElementById('meLoginError');
    form.addEventListener('submit', async e => {
      e.preventDefault();
      errEl.textContent = '';
      const input = document.getElementById('meLoginInput').value.trim();
      if (!input) {
        errEl.textContent = '소리튠 아이디 또는 휴대폰번호를 입력해주세요';
        return;
      }
      const res = await MeAPI.post('/api/member_auth.php?action=login', { input });
      if (res.ok) {
        this.state.member = res.data.member;
        this.go('dashboard');
      } else {
        errEl.textContent = res.message || '로그인에 실패했습니다';
      }
    });
  },

  async logout() {
    await MeAPI.post('/api/member_auth.php?action=logout');
    this.state.member = null;
    this.go('login');
  },
};
```

- [ ] **Step 2: Lint**

```bash
node --check /root/pt-dev/public_html/me/js/app.js
```

Expected: no output (syntax OK).

- [ ] **Step 3: Commit**

```bash
cd /root/pt-dev && git add public_html/me/js/app.js && git commit -m "feat(pt): /me/js/app.js — SPA 라우터 + API/UI 헬퍼 + 로그인 폼

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: /me/js/dashboard.js — 시험 카드

**Files:**
- Create: `public_html/me/js/dashboard.js`

로그인 후 메인. 시험 카드 2장 (오감각·DISC). DISC 는 본 spec scope 밖이라 "준비 중" 상태로 비활성화.

- [ ] **Step 1: Create file**

```js
'use strict';

const MeDashboard = {
  async render(root, member) {
    root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <div class="me-greet">안녕하세요, <strong>${MeUI.esc(member.name)}</strong>님</div>
          <button class="me-btn me-btn-ghost" id="meLogoutBtn">로그아웃</button>
        </header>
        <main class="me-cards" id="meCards">
          <div class="me-card-loading">불러오는 중...</div>
        </main>
      </div>
    `;
    document.getElementById('meLogoutBtn').onclick = () => MeApp.logout();
    await this.loadCards();
  },

  async loadCards() {
    const sensory = await MeAPI.get('/api/member_tests.php?action=latest&test_type=sensory');
    const cards = document.getElementById('meCards');
    cards.innerHTML = `
      ${this.renderSensoryCard(sensory.ok ? sensory.data.result : null)}
      ${this.renderDiscCard()}
    `;
    this.bindCards();
  },

  renderSensoryCard(latest) {
    if (latest) {
      return `
        <div class="me-card">
          <div class="me-card-title">오감각 테스트</div>
          <div class="me-card-meta">최근 응시: ${MeUI.esc(MeUI.formatDate(latest.tested_at))}</div>
          <div class="me-card-result">${MeUI.esc(latest.result_data?.title || '-')}</div>
          <div class="me-card-actions">
            <button class="me-btn me-btn-primary" data-action="view-sensory">내 결과 보기</button>
            <button class="me-btn me-btn-outline" data-action="retake-sensory">다시 보기</button>
          </div>
        </div>
      `;
    }
    return `
      <div class="me-card">
        <div class="me-card-title">오감각 테스트</div>
        <div class="me-card-meta">미응시</div>
        <div class="me-card-desc">48개 문항으로 나의 학습 감각 유형을 알아봅니다 (5분 소요)</div>
        <div class="me-card-actions">
          <button class="me-btn me-btn-primary" data-action="start-sensory">시험 시작하기</button>
        </div>
      </div>
    `;
  },

  renderDiscCard() {
    return `
      <div class="me-card me-card-disabled">
        <div class="me-card-title">DISC 진단</div>
        <div class="me-card-meta">준비 중</div>
        <div class="me-card-desc">곧 응시 가능합니다</div>
      </div>
    `;
  },

  bindCards() {
    document.querySelectorAll('[data-action]').forEach(btn => {
      btn.onclick = async () => {
        const action = btn.dataset.action;
        if (action === 'start-sensory' || action === 'retake-sensory') {
          MeApp.go('test', { testType: 'sensory' });
        } else if (action === 'view-sensory') {
          const res = await MeAPI.get('/api/member_tests.php?action=latest&test_type=sensory');
          if (res.ok && res.data.result) {
            MeApp.go('result', { testType: 'sensory', resultData: res.data.result.result_data });
          }
        }
      };
    });
  },
};
```

- [ ] **Step 2: Lint + commit**

```bash
node --check /root/pt-dev/public_html/me/js/dashboard.js && \
cd /root/pt-dev && git add public_html/me/js/dashboard.js && git commit -m "feat(pt): /me/js/dashboard.js — 시험 카드 (오감각 + DISC 준비중)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: /me/js/test-runner.js + sensory.js + parity test

**Files:**
- Create: `public_html/me/js/test-runner.js`
- Create: `public_html/me/js/sensory.js`
- Create: `tests/sensory_php_js_parity_test.php`

범용 시험 러너 + 오감각 데이터(JS 측) + PHP↔JS 동기화 가드.

- [ ] **Step 1: Create test-runner.js**

```js
'use strict';

/**
 * 범용 시험 러너 — 오감각 / DISC 모두 같은 인터페이스.
 *   MeTestRunner.start(root, testType, member)
 *
 * 데이터 모듈(예: SensoryData)이 다음을 제공해야 함:
 *   .questions: [{type, text}, ...]
 *   .totalSteps: 5
 *   .scoreClient(answers): { percents, key, title, subtitle, ... }  // 즉시 표시용
 */
const MeTestRunner = {
  state: null,

  start(root, testType, member) {
    const data = this.dataFor(testType);
    if (!data) { root.innerHTML = '<div class="me-error">알 수 없는 시험입니다</div>'; return; }

    this.state = {
      root, testType, member, data,
      perStep: Math.ceil(data.questions.length / data.totalSteps),
      currentStep: 0,
      checked: new Set(),
    };
    this.render();
  },

  dataFor(testType) {
    if (testType === 'sensory') return window.SensoryData;
    return null;
  },

  render() {
    const s = this.state;
    s.root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 대시보드</button>
          <div class="me-greet">${s.testType === 'sensory' ? '오감각 테스트' : '시험'}</div>
        </header>
        <main class="me-test">
          <div class="me-progress" id="meProgress"></div>
          <div class="me-step-header">
            <h2 id="meStepTitle"></h2>
            <span id="meStepDesc"></span>
          </div>
          <ul class="me-questions" id="meQuestions"></ul>
          <div class="me-test-actions">
            <button class="me-btn me-btn-ghost" id="meBtnPrev">이전</button>
            <button class="me-btn me-btn-primary" id="meBtnNext">다음</button>
          </div>
        </main>
      </div>
    `;
    document.getElementById('meBackBtn').onclick = () => MeApp.go('dashboard');
    document.getElementById('meBtnPrev').onclick = () => this.prev();
    document.getElementById('meBtnNext').onclick = () => this.next();
    this.renderStep();
  },

  renderStep() {
    const s = this.state;
    const start = s.currentStep * s.perStep;
    const end = Math.min(start + s.perStep, s.data.questions.length);
    const stepQs = s.data.questions.slice(start, end);

    document.getElementById('meStepTitle').textContent = `STEP ${s.currentStep + 1} / ${s.data.totalSteps}`;
    document.getElementById('meStepDesc').textContent =
      `${start + 1}~${end}번 문항 (총 ${s.data.questions.length}문항)`;

    const list = document.getElementById('meQuestions');
    list.innerHTML = stepQs.map((q, i) => {
      const idx = start + i;
      const checked = s.checked.has(idx);
      return `
        <li class="me-q ${checked ? 'me-q-checked' : ''}" data-idx="${idx}">
          <span class="me-q-mark"></span>
          <span class="me-q-text">${MeUI.esc(q.text)}</span>
        </li>
      `;
    }).join('');
    list.querySelectorAll('.me-q').forEach(li => {
      li.onclick = () => {
        const idx = Number(li.dataset.idx);
        if (s.checked.has(idx)) { s.checked.delete(idx); li.classList.remove('me-q-checked'); }
        else { s.checked.add(idx); li.classList.add('me-q-checked'); }
      };
    });

    const prog = document.getElementById('meProgress');
    prog.innerHTML = '';
    for (let i = 0; i < s.data.totalSteps; i++) {
      const dot = document.createElement('div');
      dot.className = 'me-progress-step' + (i < s.currentStep ? ' done' : i === s.currentStep ? ' active' : '');
      prog.appendChild(dot);
    }

    document.getElementById('meBtnPrev').style.visibility = s.currentStep === 0 ? 'hidden' : 'visible';
    document.getElementById('meBtnNext').textContent = s.currentStep === s.data.totalSteps - 1 ? '결과 보기' : '다음';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  },

  prev() { if (this.state.currentStep > 0) { this.state.currentStep--; this.renderStep(); } },

  async next() {
    const s = this.state;
    if (s.currentStep < s.data.totalSteps - 1) {
      s.currentStep++;
      this.renderStep();
    } else {
      await this.submit();
    }
  },

  async submit() {
    const s = this.state;
    const answers = s.data.questions.map((_, i) => s.checked.has(i) ? 1 : 0);
    const btn = document.getElementById('meBtnNext');
    btn.disabled = true;
    btn.textContent = '저장 중...';
    const res = await MeAPI.post('/api/member_tests.php?action=submit', {
      test_type: s.testType,
      answers,
    });
    if (res.ok) {
      MeApp.go('result', { testType: s.testType, resultData: res.data.result_data });
    } else {
      btn.disabled = false;
      btn.textContent = '결과 보기';
      alert(res.message || '저장에 실패했습니다');
    }
  },
};
```

- [ ] **Step 2: Create sensory.js — same data as PHP**

The questions array MUST exactly match `Sensory::questions()` in `public_html/includes/tests/sensory_meta.php` (same order, same `type`, same `text`). Categories also same.

```js
'use strict';

/**
 * Sensory questions — JS 측. PHP 측: includes/tests/sensory_meta.php.
 * 동기화 가드: tests/sensory_php_js_parity_test.php
 */
const SensoryData = {
  totalSteps: 5,

  questions: [
    {type:'체각형', text:'나는 힘이 별로 없고 쉽게 지친다'},
    {type:'청각형', text:'때때로 나는 목소리 톤만으로 사람의 성격을 알 수 있다'},
    {type:'시각형', text:'나는 종종 옷 입는 스타일을 통해 사람의 성격을 알 수 있다'},
    {type:'체각형', text:'나는 스트레칭이나 운동을 즐긴다'},
    {type:'체각형', text:'나는 내 침대에서 잠만 잘 수 있다'},
    {type:'체각형', text:'나는 패션보다 편안한 것이 더 중요하다'},
    {type:'시각형', text:'나는 영화 감상을 좋아한다'},
    {type:'시각형', text:'나는 사람들 얼굴을 잘 기억한다'},
    {type:'시각형', text:'나는 박물관 가는 것을 좋아한다'},
    {type:'시각형', text:'나는 지저분한 것을 싫어한다'},
    {type:'체각형', text:'나는 인조 섬유로 만든 옷을 싫어한다'},
    {type:'시각형', text:'좋은 조명은 아름다운 집의 비밀이다'},
    {type:'청각형', text:'나는 음악을 좋아한다'},
    {type:'시각형', text:'나는 하늘 보는 것을 즐긴다'},
    {type:'체각형', text:'나는 악수를 통해 사람의 성격을 알 수 있다'},
    {type:'청각형', text:'나는 종종 혼자 콧노래를 흥얼거린다'},
    {type:'시각형', text:'나는 미술 전시를 좋아한다'},
    {type:'체각형', text:'나는 편한 옷만 입는다'},
    {type:'청각형', text:'나는 깊은 대화와 토론을 즐긴다'},
    {type:'체각형', text:'나는 더위가 좋다'},
    {type:'체각형', text:'때때로 사람 사이에 손길이 말보다 더 분명한 의미를 전달한다'},
    {type:'시각형', text:'내가 물건을 살 때, 색은 중요한 사항이다'},
    {type:'청각형', text:'시끄러운 환경에서는 집중할 수가 없다'},
    {type:'청각형', text:'걷는 소리를 듣고 사람을 알아볼 수 있다'},
    {type:'청각형', text:'나는 다른 사람의 말투와 억양을 쉽게 따라 할 수 있다'},
    {type:'청각형', text:'아주 조용하지 않으면 잠을 잘 수가 없어요'},
    {type:'시각형', text:'나는 외모를 가꾸는 편이다'},
    {type:'청각형', text:'나는 운전할 때 항상 음악이나 라디오를 듣는다'},
    {type:'체각형', text:'나는 마사지를 좋아한다'},
    {type:'체각형', text:'나는 내가 좋아하는 노래를 들으면 가만히 있기가 힘들다'},
    {type:'청각형', text:'나는 빗소리를 너무 좋아한다'},
    {type:'시각형', text:'나는 사람들이 보는 것을 즐긴다'},
    {type:'청각형', text:'혼자있을 때, 나는 단지 사람의 목소리를 듣기 위해 텔레비전을 켠다'},
    {type:'체각형', text:'나는 활동적인 사람이고 신체 활동을 즐긴다'},
    {type:'체각형', text:'나는 춤추는 것을 좋아한다'},
    {type:'시각형', text:'옷을 그냥 보기만 해도 살지 안 살지 결정할 수 있다'},
    {type:'청각형', text:'옛날 멜로디를 들으면 과거의 추억이 떠오른다'},
    {type:'시각형', text:'나는 그림, 사진, 영화 등 시각적인 모든 것을 감상한다'},
    {type:'시각형', text:'나는 먹으면서 텔레비전 보는 것을 좋아한다'},
    {type:'청각형', text:'나는 친구들과 동료들의 목소리를 쉽게 떠올릴 수 있고, 그들의 소리가 머릿속에서 들리는 듯하다'},
    {type:'청각형', text:'나는 문자나 이메일보다는 전화로 말하는 것을 더 좋아한다'},
    {type:'시각형', text:'나는 아름다운 것들에 둘러싸여 있는 것을 좋아한다'},
    {type:'체각형', text:'스트레스를 받거나 걱정을 할 때 가슴에 신체적 압박감이 느껴진다'},
    {type:'체각형', text:'나는 종종 뜨거운 물로 목욕을 하고 그것을 즐긴다'},
    {type:'청각형', text:'나는 독서보다 오디오북을 더 좋아한다'},
    {type:'시각형', text:'나는 플래너를 사용해서 프로젝트를 계속 추적하고 계획한다'},
    {type:'체각형', text:'직장에서 안 좋은 하루를 보내면, 나는 긴장되고 긴장을 풀 수 없다'},
    {type:'청각형', text:'나는 혼자 있을 때 혼잣말을 한다'},
  ],
};
window.SensoryData = SensoryData;
```

- [ ] **Step 3: Create parity test (PHP 측 questions == JS 측 questions)**

Create `tests/sensory_php_js_parity_test.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/tests/sensory_meta.php';

/**
 * JS 파일을 텍스트로 파싱해 questions 배열을 PHP 로 복원.
 * `{type:'X', text:'Y'},` 패턴 매칭. text 안에 작은따옴표가 들어있지 않아야 함 — 본 데이터셋은 충족.
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
```

- [ ] **Step 4: Lint JS + run tests**

```bash
cd /root/pt-dev && \
  node --check public_html/me/js/test-runner.js && \
  node --check public_html/me/js/sensory.js && \
  php tests/run_tests.php 2>&1 | tail -15
```

Expected: no JS syntax errors. Parity test PASS (count match + 0 diffs).

- [ ] **Step 5: Commit**

```bash
cd /root/pt-dev && git add public_html/me/js/test-runner.js public_html/me/js/sensory.js tests/sensory_php_js_parity_test.php && git commit -m "$(cat <<'EOF'
feat(pt): /me/js test-runner + sensory data + parity guard

- 범용 TestRunner (DISC 까지 같은 인터페이스)
- sensory.js — PHP meta 와 동일한 48문항
- PHP↔JS parity 테스트 — 한쪽만 수정되면 즉시 fail

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: /me/js/result-view.js — 결과 화면

**Files:**
- Create: `public_html/me/js/result-view.js`

회원용 결과 — 점수 그래프 + 유형 + 특징 + 추천학습법 (추천강의·공유 제외).

- [ ] **Step 1: Create file**

categories 데이터는 PHP 의 `Sensory::categories()` 와 동일한 구조. JS 에서는 결과 화면용 표시만 필요하니 통째로 inline.

```js
'use strict';

const SENSORY_CATEGORIES = {
  "0,0,0": {
    title: "균형형",
    subtitle: "어떤 감각도 아직 예민하지 않은 상태",
    content: "<b>특징:</b>\n<ul>\n<li>어떤 감각도 아직 예민하지 않은 상태로, 특정 감각이 두드러지지 않습니다</li>\n<li>한 가지 방식에 의존한 학습보다는 모든 감각을 고르게 활용하는 훈련이 필요합니다</li>\n<li>시각·청각·체각을 골고루 자극하는 복합적인 학습을 통해 감각을 깨워야 합니다</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>시각·청각·체각을 골고루 이용하는 복합 학습법이 가장 효과적</li>\n<li>영상을 보면서(시각) + 소리를 듣고 따라 하면서(청각) + 몸으로 리듬을 느끼며(체각) 훈련</li>\n<li>한 가지 감각에만 의존하지 말고 다양한 방식을 번갈아 사용하세요</li>\n</ul>",
  },
  "0,0,1": {
    title: "체각형 우세 학습자",
    subtitle: "몸을 움직이며 학습하는 스타일",
    content: "<b>특징:</b>\n<ul>\n<li>움직이면서 배우는 스타일, 필기보다는 실습이 중요</li>\n<li>제스처, 롤플레잉, 몸으로 익히는 학습법이 효과적</li>\n<li>체험형 학습이 중요하며, 실전 상황에 익숙해져야 함</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>실전 회화 & 롤플레잉 → 직접 대화하면서 문장 익히기</li>\n<li>몸으로 익히는 발음 트레이닝 → 손동작 활용, 입 모양 체크</li>\n<li>음성 따라 말하기 연습 → 실전적인 발음 & 억양 훈련</li>\n<li>훈련 시 박수를 치며 리듬을 몸으로 느끼며 훈련하면 효과적입니다</li>\n</ul>",
  },
  "0,1,0": {
    title: "시각형 우세 학습자",
    subtitle: "영상, 이미지 활용 학습이 효과적",
    content: "<b>특징:</b>\n<ul>\n<li>비주얼 자료가 학습에 중요한 역할</li>\n<li>영상을 보면서 배우거나, 정리하면서 학습하는 것이 효과적</li>\n<li>패턴을 분석하거나 시각적으로 기억하는 것이 유리함</li>\n<li>그동안 쉐도잉 방식의 영어 훈련을 시도했지만 효과를 보지 못했을 가능성이 있습니다 — 시각형은 소리만 듣고 따라 하는 방식이 잘 맞지 않을 수 있습니다</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>영상 & 이미지 학습법 → 소리튜닝 훈련 시 입 모양 영상 분석</li>\n<li>마인드맵 & 정리 노트 활용 → 문장 구조를 시각적으로 정리</li>\n<li>패턴 분석 → 텍스트와 소리를 함께 학습</li>\n<li>소리튜닝을 할 때 반드시 1:1 코칭을 병행해야 효과적입니다</li>\n</ul>",
  },
  "0,1,1": {
    title: "시각 + 체각 혼합 학습자",
    subtitle: "눈으로 보고, 몸으로 익히는 스타일",
    content: "<b>특징:</b>\n<ul>\n<li>이미지를 보고 이해하고, 직접 경험하며 배우는 스타일</li>\n<li>정적인 필기보다는 손으로 정리하거나 실습하며 학습하는 것이 효과적</li>\n<li>제스처 & 영상 자료를 적극 활용하는 학습법이 적합</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>영상 보면서 따라 하는 실습형 학습</li>\n<li>필기 & 정리하며 배우기 (손으로 직접 정리)</li>\n<li>제스처 활용한 말하기 연습</li>\n</ul>",
  },
  "1,0,0": {
    title: "청각형 우세 학습자",
    subtitle: "소리를 활용한 학습이 효과적",
    content: "<b>특징:</b>\n<ul>\n<li>듣기와 말하기 중심 학습이 효과적</li>\n<li>글을 읽는 것보다 소리를 듣고 따라 하는 방식이 적합</li>\n<li>반복 청취 & 쉐도잉이 핵심</li>\n<li>소리튜닝 학습 시 가장 빠르게 성장할 수 있는 유형입니다</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>리스닝 집중 학습</li>\n<li>소리 분석 후 따라 말하기</li>\n<li>리듬 & 억양 중심 말하기 훈련</li>\n<li>소리블록만으로도 소리가 바뀔 수 있습니다 — 소리블록 훈련을 적극 활용하세요</li>\n</ul>",
  },
  "1,0,1": {
    title: "청각 + 체각 혼합 학습자",
    subtitle: "소리를 듣고 몸을 움직이며 학습하는 스타일",
    content: "<b>특징:</b>\n<ul>\n<li>소리를 듣고 따라 하면서 익히는 방식이 효과적</li>\n<li>필기보다는 몸으로 익히는 액션 기반 학습법이 적합</li>\n<li>리듬, 억양을 자연스럽게 익히는 학습이 필요</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>리듬 & 박자 맞춰 말하기 연습</li>\n<li>제스처 활용 학습</li>\n<li>실제 대화 롤플레잉 훈련</li>\n</ul>",
  },
  "1,1,0": {
    title: "청각 + 시각 혼합 학습자",
    subtitle: "소리와 이미지를 결합한 학습법이 효과적",
    content: "<b>특징:</b>\n<ul>\n<li>듣기 + 시각적 자료 활용이 중요</li>\n<li>영상 & 오디오 학습법이 효과적</li>\n<li>글보다는 이미지 & 음성을 통한 학습이 적합</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>영상 + 오디오 활용 학습</li>\n<li>소리튜닝 후 패턴 정리하여 분석</li>\n<li>쉐도잉 & 패턴 학습법 활용</li>\n</ul>",
  },
  "1,1,1": {
    title: "완전한 멀티 감각형 학습자",
    subtitle: "모든 감각이 골고루 발달 — 훈련 시 빠른 성장이 가능",
    content: "<b>특징:</b>\n<ul>\n<li>모든 감각이 골고루 발달되어 있어 어떤 학습 방식이든 잘 받아들일 수 있습니다</li>\n<li>훈련을 시작하면 다른 유형보다 빠르게 성장할 수 있는 잠재력이 있습니다</li>\n<li>다양한 감각을 동시에 활용하는 종합 훈련이 가장 효과적입니다</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>다양한 감각을 동시에 활용하는 종합 훈련을 권장합니다</li>\n<li>영상 + 오디오 + 실전 연습을 병행하여 모든 감각을 자극하세요</li>\n<li>골고루 발달된 감각을 최대한 활용하면 빠른 성장이 가능합니다</li>\n</ul>",
  },
};

const MeResultView = {
  render(root, testType, resultData) {
    if (testType !== 'sensory') {
      root.innerHTML = '<div class="me-error">알 수 없는 결과입니다</div>';
      return;
    }
    const cat = SENSORY_CATEGORIES[resultData.key] || SENSORY_CATEGORIES["0,0,0"];
    const p = resultData.percents || { auditory: 0, visual: 0, kinesthetic: 0 };

    root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 대시보드</button>
        </header>
        <main class="me-result">
          <div class="me-result-top">
            <div class="me-result-top-label">나의 감각 유형</div>
            <h2>${MeUI.esc(resultData.title || cat.title)}</h2>
            <p>${MeUI.esc(resultData.subtitle || cat.subtitle)}</p>
          </div>
          <div class="me-score">
            ${this.bar('청각', 'auditory', p.auditory || 0)}
            ${this.bar('시각', 'visual',   p.visual   || 0)}
            ${this.bar('체각', 'kinesthetic', p.kinesthetic || 0)}
          </div>
          <div class="me-divider"></div>
          <section class="me-info">
            <div class="me-info-label">특징</div>
            ${cat.content.replace(/<b>특징:<\/b>\n?/, '')}
          </section>
          <div class="me-divider"></div>
          <section class="me-info">
            <div class="me-info-label">추천 학습법</div>
            ${cat.learning.replace(/<b>추천 학습법:<\/b>\n?/, '')}
          </section>
          <div class="me-result-actions">
            <button class="me-btn me-btn-outline" id="meRetry">다시 하기</button>
            <button class="me-btn me-btn-primary" id="meBackDash">대시보드</button>
          </div>
        </main>
      </div>
    `;
    document.getElementById('meBackBtn').onclick = () => MeApp.go('dashboard');
    document.getElementById('meBackDash').onclick = () => MeApp.go('dashboard');
    document.getElementById('meRetry').onclick = () => MeApp.go('test', { testType });

    // bar fill animation
    setTimeout(() => {
      document.querySelectorAll('.me-score-fill').forEach(el => {
        el.style.width = el.dataset.width;
      });
    }, 50);
  },

  bar(label, key, pct) {
    return `
      <div class="me-score-row">
        <span class="me-score-label">${label}</span>
        <div class="me-score-bar"><div class="me-score-fill me-score-${key}" data-width="${pct}%" style="width:0%"></div></div>
        <span class="me-score-pct">${pct}%</span>
      </div>
    `;
  },
};
```

- [ ] **Step 2: Lint + commit**

```bash
node --check /root/pt-dev/public_html/me/js/result-view.js && \
cd /root/pt-dev && git add public_html/me/js/result-view.js && git commit -m "$(cat <<'EOF'
feat(pt): /me/js/result-view.js — 회원용 결과 화면 (특징·학습법 포함, 추천강의·공유 제외)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: assets/css/member.css — /me/ 전용 스타일

**Files:**
- Create: `public_html/assets/css/member.css`

PT 다크 토큰 재사용 + `/me/` 전용 클래스 (me-*).

- [ ] **Step 1: Create file**

```css
/* /me/ — 회원 포털 전용 스타일. PT 다크 토큰 (--bg, --accent, ...) 재사용 */
.me-body { background: var(--bg); color: var(--text); margin: 0; padding: 0; min-height: 100vh; font-family: 'Pretendard', 'Pretendard Variable', -apple-system, sans-serif; }

/* === Login === */
.me-login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
.me-login-card { background: var(--surface, #181818); border-radius: 12px; padding: 40px 28px; box-shadow: rgba(0,0,0,0.5) 0px 8px 24px; max-width: 380px; width: 100%; }
.me-login-logo { font-size: 24px; font-weight: 700; color: var(--accent); text-align: center; }
.me-login-subtitle { font-size: 13px; color: var(--text-secondary); text-align: center; margin-top: 6px; margin-bottom: 28px; }
.me-login-error { color: #f3727f; font-size: 13px; margin-top: 12px; min-height: 18px; text-align: center; }
.me-input { width: 100%; box-sizing: border-box; background: #1f1f1f; color: #fff; border: 1px solid #4d4d4d; border-radius: 6px; padding: 12px 14px; font-size: 15px; font-family: inherit; outline: none; }
.me-input:focus { border-color: var(--accent); }

/* === Buttons === */
.me-btn { display: inline-block; box-sizing: border-box; padding: 12px 20px; border: none; border-radius: 9999px; font-size: 14px; font-weight: 700; font-family: inherit; cursor: pointer; transition: transform 0.1s, box-shadow 0.2s; }
.me-btn-primary { background: var(--accent); color: #000; width: 100%; margin-top: 14px; }
.me-btn-primary:hover { box-shadow: 0 4px 14px rgba(255,94,0,0.35); }
.me-btn-outline { background: transparent; color: var(--text); border: 1px solid #7c7c7c; }
.me-btn-outline:hover { border-color: var(--accent); color: var(--accent); }
.me-btn-ghost { background: transparent; color: var(--text-secondary); padding: 8px 14px; }
.me-btn-ghost:hover { color: var(--text); }
.me-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* === Shell === */
.me-shell { max-width: 720px; margin: 0 auto; padding: 24px 16px 60px; }
.me-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; gap: 12px; }
.me-greet { color: var(--text); font-size: 15px; }

/* === Dashboard cards === */
.me-cards { display: grid; gap: 16px; grid-template-columns: 1fr; }
@media (min-width: 640px) { .me-cards { grid-template-columns: 1fr 1fr; } }
.me-card { background: var(--surface-card, #252525); border-radius: 12px; padding: 24px 20px; display: flex; flex-direction: column; gap: 8px; box-shadow: rgba(0,0,0,0.3) 0px 8px 8px; }
.me-card-disabled { opacity: 0.5; pointer-events: none; }
.me-card-title { font-size: 16px; font-weight: 700; color: var(--text); }
.me-card-meta { font-size: 12px; color: var(--text-secondary); }
.me-card-result { font-size: 15px; font-weight: 600; color: var(--accent); margin-top: 4px; }
.me-card-desc { font-size: 13px; color: var(--text-muted); margin: 4px 0 8px; line-height: 1.5; }
.me-card-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
.me-card-loading { color: var(--text-secondary); padding: 20px; text-align: center; }

/* === Test runner === */
.me-test { background: var(--surface-card, #252525); border-radius: 12px; padding: 24px 20px; }
.me-progress { display: flex; gap: 6px; margin-bottom: 20px; }
.me-progress-step { flex: 1; height: 4px; border-radius: 2px; background: #3a3a3a; }
.me-progress-step.active { background: var(--accent); }
.me-progress-step.done { background: #ff9a52; }
.me-step-header { text-align: center; margin-bottom: 18px; }
.me-step-header h2 { color: var(--accent); font-size: 16px; margin: 0 0 4px; font-weight: 700; }
.me-step-header span { color: var(--text-secondary); font-size: 12px; }
.me-questions { list-style: none; margin: 0; padding: 0; }
.me-q { display: flex; align-items: center; gap: 12px; padding: 14px 14px; margin-bottom: 8px; border-radius: 10px; border: 1px solid #3a3a3a; background: #1f1f1f; cursor: pointer; user-select: none; transition: border-color 0.15s, background 0.15s; }
.me-q:hover { border-color: #5a5a5a; }
.me-q-checked { border-color: var(--accent); background: #2a1f15; }
.me-q-mark { width: 22px; height: 22px; border-radius: 6px; border: 2px solid #5a5a5a; flex-shrink: 0; position: relative; }
.me-q-checked .me-q-mark { background: var(--accent); border-color: var(--accent); }
.me-q-checked .me-q-mark::after { content: ''; position: absolute; left: 6px; top: 2px; width: 6px; height: 11px; border: solid #000; border-width: 0 2px 2px 0; transform: rotate(45deg); }
.me-q-text { color: var(--text); font-size: 14px; line-height: 1.5; }
.me-test-actions { display: flex; gap: 10px; margin-top: 24px; }
.me-test-actions .me-btn { flex: 1; }

/* === Result === */
.me-result { background: var(--surface-card, #252525); border-radius: 12px; padding: 0 0 24px; overflow: hidden; }
.me-result-top { padding: 32px 20px 24px; background: linear-gradient(135deg, var(--accent), #ff8c3a); color: #000; text-align: center; }
.me-result-top-label { font-size: 12px; letter-spacing: 0.05em; opacity: 0.85; }
.me-result-top h2 { font-size: 22px; font-weight: 700; margin: 8px 0 4px; }
.me-result-top p { font-size: 13px; margin: 0; opacity: 0.85; }
.me-score { padding: 24px 20px 0; }
.me-score-row { display: flex; align-items: center; margin-bottom: 14px; gap: 10px; }
.me-score-label { width: 40px; flex-shrink: 0; font-size: 13px; font-weight: 600; color: var(--text-secondary); text-align: right; }
.me-score-bar { flex: 1; height: 10px; background: #3a3a3a; border-radius: 5px; overflow: hidden; }
.me-score-fill { height: 100%; border-radius: 5px; transition: width 0.8s ease; }
.me-score-fill.me-score-auditory    { background: var(--accent); }
.me-score-fill.me-score-visual      { background: #ffaa4d; }
.me-score-fill.me-score-kinesthetic { background: var(--accent-dark, #cc4b00); }
.me-score-pct { width: 40px; flex-shrink: 0; text-align: right; font-size: 13px; font-weight: 700; color: var(--text); }
.me-divider { height: 1px; background: #3a3a3a; margin: 20px 20px; }
.me-info { padding: 0 20px; color: var(--text-muted); line-height: 1.7; font-size: 14px; }
.me-info-label { font-size: 11px; letter-spacing: 0.08em; font-weight: 700; color: var(--accent); text-transform: uppercase; margin-bottom: 8px; }
.me-info ul { padding-left: 18px; margin: 0; }
.me-info li { margin-bottom: 4px; }
.me-result-actions { display: flex; gap: 10px; padding: 24px 20px 0; }
.me-result-actions .me-btn { flex: 1; }

.me-error { padding: 40px; text-align: center; color: #f3727f; }
```

- [ ] **Step 2: Commit**

```bash
cd /root/pt-dev && git add public_html/assets/css/member.css && git commit -m "feat(pt): assets/css/member.css — /me/ 전용 스타일 (PT 다크 토큰)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 12: 어드민/코치 member-chart 결과 카드 렌더링

**Files:**
- Modify: `public_html/admin/js/pages/member-chart.js` (line 414~423: replace `formatTestData`, and line 388/403: change call sites)
- Modify: `public_html/coach/js/pages/member-chart.js` (mirror admin change)

기존 `formatTestData()` (단순 dump) 를 `formatTestResult(testType, data)` 로 교체. 신규 schema → 카드형, legacy → fallback.

- [ ] **Step 1: Read coach version to confirm parallel structure**

```bash
grep -n "formatTestData\|tests\.php\|test_result" /root/pt-dev/public_html/coach/js/pages/member-chart.js | head -10
```

Expected: same `formatTestData(data)` function and call sites as admin. (If structure differs, replicate the admin change pattern accordingly.)

- [ ] **Step 2: Replace `formatTestData` in admin/js/pages/member-chart.js**

Find the existing function (line 414~423):

```js
  formatTestData(data) {
    try {
      const parsed = typeof data === 'string' ? JSON.parse(data) : data;
      if (Array.isArray(parsed)) return parsed.join(', ');
      if (typeof parsed === 'object') {
        return Object.entries(parsed).map(([k,v]) => `${k}: ${v}`).join(' | ');
      }
      return String(parsed);
    } catch { return String(data || '-'); }
  },
```

Replace with:

```js
  formatTestResult(testType, data) {
    let parsed;
    try { parsed = typeof data === 'string' ? JSON.parse(data) : data; } catch { return UI.esc(String(data || '-')); }
    if (!parsed || typeof parsed !== 'object') return UI.esc(String(parsed || '-'));

    // Legacy free-form (어드민이 손으로 입력했던 옛 row): version 없음 또는 percents 없음
    const isNewSensory = testType === 'sensory' && parsed.version === 1 && parsed.percents;
    if (!isNewSensory) {
      // Legacy fallback: 기존 dump 식
      if (Array.isArray(parsed)) return UI.esc(parsed.join(', '));
      return UI.esc(Object.entries(parsed).map(([k,v]) => `${k}: ${v}`).join(' | '));
    }

    const p = parsed.percents;
    return `
      <div style="font-weight:700;margin-bottom:4px">${UI.esc(parsed.title || '')}</div>
      <div style="font-size:12px;color:var(--text-secondary)">
        청각 ${p.auditory ?? 0}%  ·  시각 ${p.visual ?? 0}%  ·  체각 ${p.kinesthetic ?? 0}%
      </div>
    `;
  },
```

Then change the two call sites (line ~388 and ~403):

`${UI.esc(this.formatTestData(r.result_data))}` → `${this.formatTestResult(r.test_type, r.result_data)}`

(formatTestResult 내부에서 이미 escape 하므로 외부 `UI.esc()` 제거)

- [ ] **Step 3: Mirror change in coach/js/pages/member-chart.js**

Apply the same replacement (function definition + call sites). Coach 의 함수는 결과를 표시만 하고 "+ 결과 추가" / "삭제" 가 없을 수 있음 — 호출 부위만 동일하게 바꾸면 됨.

- [ ] **Step 4: JS lint**

```bash
cd /root/pt-dev && node --check public_html/admin/js/pages/member-chart.js && node --check public_html/coach/js/pages/member-chart.js
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
cd /root/pt-dev && git add public_html/admin/js/pages/member-chart.js public_html/coach/js/pages/member-chart.js && git commit -m "$(cat <<'EOF'
feat(pt): member-chart 테스트결과 카드 렌더링 (신규 schema + legacy fallback)

- formatTestData → formatTestResult(testType, data)
- 신규 sensory schema (version=1, percents) → 유형명 + 점수 3축
- legacy free-form JSON (version 없음) → 기존 dump 식 fallback (호환)
- 어드민/코치 동일 변경

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: 수동 smoke + 전체 테스트 + dev push

**Files:** none

DEV 환경에서 회원 셀프 응시 끝까지 한 번 + 어드민 차트에서 결과 카드 확인.

- [ ] **Step 1: 전체 테스트 PASS 확인**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -5
```

Expected: `Total: ...  Pass: N  Fail: 0`

- [ ] **Step 2: DEV 시드 회원 확보**

DEV DB 에 시드 회원 1명을 sql 로 직접 추가하거나, 기존 회원 1명의 soritune_id 를 메모.

```bash
source /root/pt-dev/.db_credentials && \
mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id, soritune_id, name, phone FROM members WHERE merged_into IS NULL ORDER BY id DESC LIMIT 3;"
```

Expected: 3 rows. 그 중 한 명의 `soritune_id` 와 `phone` 를 메모.

- [ ] **Step 3: 브라우저에서 https://dev-pt.soritune.com/me/ 접속**

- 로그인 화면 → 메모한 `soritune_id` 입력 → "시작하기"
- 대시보드 → "오감각 테스트" 카드 → "시험 시작하기"
- 5단계 진행 (몇 개 체크) → "결과 보기" 클릭
- 결과 화면 — 점수 그래프 + 유형 + 특징 + 추천학습법 보임
- "다시 하기" → 두 번째 응시 → 결과 다른지 확인
- "대시보드" → 카드에 최신 응시일·유형 표시
- "내 결과 보기" → 결과 화면 다시 표시
- "로그아웃" → 로그인 화면 복귀

- [ ] **Step 4: 매칭 실패 케이스**

`/me/` → "nonexistent_xyz_99" 입력 → "고객센터로 문의해주세요" 메시지 표시

- [ ] **Step 5: 폰 정규화 케이스**

`/me/` → 메모한 회원 phone 을 `010-xxxx-xxxx` 형식으로 입력 → 로그인 성공

- [ ] **Step 6: 어드민 차트에서 결과 카드 확인**

dev-pt.soritune.com 어드민으로 로그인 → 회원관리 → 해당 회원 차트 → "테스트결과" 탭
- 두 row 모두 카드형 (유형명 + 청각/시각/체각 %) 표시
- 코치 (해당 회원 활성 order 보유) 도 같은 화면 — 동일 표시
- (옵션) legacy free-form JSON row 가 이미 있다면 dump 식 fallback 으로 표시되는지 확인

- [ ] **Step 7: 모바일 (or devtools 모바일 모드) 1회 검증**

Chrome devtools → iPhone 14 Pro emulation → `/me/` → 시험 한 번 끝까지 — 폰트 깨짐 / 버튼 영역 OK / 다크 테마 OK

- [ ] **Step 8: dev push (사용자 확인 받고)**

여기서 멈추고 사용자에게 "DEV 검수 끝났으니 dev push 해도 되겠습니까?" 확인.
사용자가 OK 하면:

```bash
cd /root/pt-dev && git push origin dev
```

운영 반영(main 머지 + prod pull)은 사용자가 명시 요청 시 별도 단계.

---

## Self-Review

**1. Spec coverage check**

| Spec section | Plan task |
|---|---|
| 4.1 URL 진입 (/me/) | T6 (index.php), T7 (router) |
| 4.2 세션 모델 (PT_SESSION + role=member) | T2 (requireMember), T3 (login에서 세션 채움) |
| 4.3 시험 러너 프레임워크 (범용) | T9 (test-runner.js) |
| 4.4 파일 레이아웃 | T1, T6, T7, T8, T9, T10, T11 — 모두 매핑 |
| 5.1 test_results 재사용 | T4 (INSERT 그대로) |
| 5.2 result_data v1 schema | T1 (Sensory::score 빌드) |
| 5.5 latest 쿼리 (tested_at DESC, id DESC) | T5 (memberTestsLatestImpl) |
| 6.1 로그인 자동 판별 + 폰 정규화 | T3 (lookupMemberByInput + normalizePhone 재사용) |
| 6.1 다중 매칭 → 최신 1명 | T3 (created_at DESC LIMIT 1 + 테스트) |
| 6.1 merged_into follow-through | T3 (5단 hop) |
| 6.2 API: login/logout/me | T3 |
| 6.3 매칭 실패 안내 (텍스트) | T3 (NOT_FOUND 메시지) + T7 (UI 표시) |
| 7.1 5단계 / 10문항씩 | T9 (test-runner.js renderStep) |
| 7.2 questions 데이터 (48개) | T1 (PHP) + T9 (JS) + T9 (parity test) |
| 7.3 채점 50% 초과 | T1 (Sensory::score) + 단위테스트 |
| 7.4 categories 8개 | T1 (PHP) + T10 (JS, 결과 화면용) |
| 7.5 이중 채점 (서버 단일 source) | T4 (서버 재계산) + 위조 무시 테스트 |
| 7.6 PHP↔JS 동기화 가드 | T9 (parity test) |
| 7.7 submit API + 검증 | T4 |
| 7.9 version 필드 | T1 (Sensory::VERSION = 1) |
| 8.1 회원 결과 화면 (full) | T10 (result-view.js) |
| 8.2 대시보드 카드 | T8 (dashboard.js) |
| 8.3 어드민/코치 카드형 + legacy fallback | T12 (member-chart.js) |
| 9 보안 (requireMember, regenerate_id) | T2, T3 |
| 10.1~10.6 PHP 테스트 | T1, T3, T4, T5, T9 |
| 10.7 수동 smoke | T13 |
| 12 배포 — DEV → 사용자 확인 → PROD | T13 (DEV push만, PROD 별도) |

모든 spec 요구사항이 task 에 매핑됨.

**2. Placeholder scan**

- "TBD" / "TODO" / "later" — 검색 결과 없음 ✓
- "Add appropriate ..." — 없음 ✓
- 모든 코드 step 에 실제 코드 포함 ✓

**3. Type / signature consistency**

- `lookupMemberByInput(PDO $db, string $raw): ?array` — T3 정의, T3 테스트 호출 일치 ✓
- `memberTestsSubmitImpl(PDO $db, array $user, array $input): array` — T4 정의, T4 테스트 일치 ✓
- `memberTestsLatestImpl(PDO $db, array $user, string $testType): array` — T5 정의, T5 테스트 일치 ✓
- `Sensory::score(array $answers): array` — T1 정의, T1·T4 모두 사용 일치 ✓
- JS: `MeApp.go(view, params)`, `MeAPI.get/post`, `MeUI.esc/formatDate` — T7 정의, T8/T9/T10 사용 일치 ✓
- JS: `MeTestRunner.start(root, testType, member)` — T9 정의, T7 호출 일치 ✓
- JS: `MeResultView.render(root, testType, resultData)` — T10 정의, T7 호출 일치 ✓
- JS: `MeDashboard.render(root, member)` — T8 정의, T7 호출 일치 ✓
- 데이터: `result_data.percents = {auditory, visual, kinesthetic}` — T1·T10·T12 모두 동일 키 ✓
- 데이터: `result_data.key = "a,v,k"` — T1·T10 동일 ✓

**4. Scope check**

단일 spec, 단일 plan. 13 task 로 PHP 백엔드 → JS 프론트 → 어드민 통합 → smoke 순차. 분해 불필요.

---

## Execution Handoff

Plan 작성·자체검토 완료. 저장: `docs/superpowers/plans/2026-05-08-pt-member-tests.md`.

**실행 방식 두 가지:**

1. **Subagent-Driven** (recommended) — task 마다 fresh subagent 가 구현, 사이에 리뷰. 컨텍스트 절약.
2. **Inline Execution** — 현 세션에서 batch 로 실행, 체크포인트마다 리뷰.

어떤 방식으로 진행할까요?
