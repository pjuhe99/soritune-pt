# PT 카톡방 입장 리마인드 알림톡 시나리오 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PT 알림톡 인프라에 새 시나리오 `pt_kakao_room_remind`를 추가한다. 매일 19시 KST에 이번 달 cohort 소리튜닝 음성PT 진행중 회원 중 1:1PT 카톡방 미입장자에게 리마인드 알림톡을 발송한다.

**Architecture:** 기존 dispatcher/scenario_registry/solapi_client는 그대로 유지하고 (1) 새 데이터 소스 어댑터 `source_pt_orders_query`, (2) 시나리오 정의 PHP 파일, (3) scenario_registry의 description 옵셔널 type 검증을 추가한다. UI는 이미 boot에서 복사된 description 렌더링 인프라가 있어 변경 불필요.

**Tech Stack:** PHP 8.x · MySQL/MariaDB · 기존 PT notify 인프라 · Solapi 알림톡

**Spec:** `docs/superpowers/specs/2026-05-01-pt-kakao-room-remind-design.md`

---

## File Structure

| 종류 | 경로 | 책임 |
|---|---|---|
| Create | `public_html/includes/notify/source_pt_orders_query.php` | PT orders SQL 조회 어댑터 (cohort_mode 처리, missing-data 통합 skip) |
| Create | `public_html/includes/notify/scenarios/pt_kakao_room_remind.php` | 시나리오 정의 (key/name/description/source/template/schedule/cooldown/max_attempts) |
| Create | `tests/notify_pt_orders_query_test.php` | 어댑터 단위 테스트 (12 케이스) |
| Create | `tests/notify_scenario_registry_test.php` | scenario_registry 옵셔널 description 검증 테스트 (4 케이스) |
| Modify | `public_html/includes/notify/scenario_registry.php` | `notifyValidateScenario()`에 description string 타입 검증 1줄 |
| Modify | `public_html/includes/notify/dispatcher.php` | `require_once` 1줄 + `notifyFetchRows()` match arm 1줄 |

DB 변경 없음. UI/CSS 변경 없음 (이미 boot에서 복사됨).

---

## Task 1: `scenario_registry.php` 옵셔널 description 검증

**Files:**
- Modify: `public_html/includes/notify/scenario_registry.php` (line 35-52, `notifyValidateScenario`)
- Test: `tests/notify_scenario_registry_test.php`

- [ ] **Step 1: 실패하는 테스트 작성**

```php
<?php
// tests/notify_scenario_registry_test.php
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
t_assert_throws(function() use ($base) {
    notifyValidateScenario($base);
    throw new RuntimeException('__no_throw__');
}, 'RuntimeException', 'description 없으면 통과 (sentinel throw 확인)');
// ↑ 위는 약간 트릭: 통과 → sentinel throw → RuntimeException 잡힘. 즉 실제 validate는 통과.

// 2) description string 통과
t_assert_throws(function() use ($base) {
    $def = $base; $def['description'] = '리마인드 시나리오';
    notifyValidateScenario($def);
    throw new RuntimeException('__no_throw__');
}, 'RuntimeException', 'description string 통과');

// 3) description 배열 → throw
t_assert_throws(function() use ($base) {
    $def = $base; $def['description'] = ['a', 'b'];
    notifyValidateScenario($def);
}, 'RuntimeException', 'description 배열이면 throw');

// 4) description 정수 → throw
t_assert_throws(function() use ($base) {
    $def = $base; $def['description'] = 42;
    notifyValidateScenario($def);
}, 'RuntimeException', 'description 정수면 throw');
```

- [ ] **Step 2: 테스트 실행 → 3, 4번 FAIL 확인**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -40
```

기대: 1/2번은 PASS, 3/4번은 FAIL (현재 description 타입 검증 없음).

- [ ] **Step 3: 최소 구현 추가**

`public_html/includes/notify/scenario_registry.php`의 `notifyValidateScenario` 함수 끝부분(`}` 직전)에 추가:

```php
    if (array_key_exists('description', $def) && !is_string($def['description'])) {
        throw new RuntimeException("시나리오 '{$keyLabel}': 'description'은 string이어야 함");
    }
```

- [ ] **Step 4: 테스트 재실행 → 4 케이스 모두 PASS**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -20
```

기대: 4/4 PASS, 기존 122 테스트도 회귀 없음.

- [ ] **Step 5: 커밋**

```bash
cd /root/pt-dev && git add public_html/includes/notify/scenario_registry.php tests/notify_scenario_registry_test.php
git commit -m "feat(notify): scenario_registry에 description 옵셔널 string 타입 검증"
```

---

## Task 2: 어댑터 `source_pt_orders_query.php` (TDD)

**Files:**
- Create: `public_html/includes/notify/source_pt_orders_query.php`
- Test: `tests/notify_pt_orders_query_test.php`

- [ ] **Step 1: 실패하는 테스트 작성 (12 케이스)**

```php
<?php
// tests/notify_pt_orders_query_test.php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/notify/source_pt_orders_query.php';

t_section('notifySourcePtOrdersQuery — 12 시나리오');

$db = getDB();
$db->beginTransaction();

try {
    // 시드 헬퍼
    $insMember = function(string $sid, ?string $phone) use ($db): int {
        $db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?, ?, ?)")
           ->execute([$sid, "M_{$sid}", $phone]);
        return (int)$db->lastInsertId();
    };
    $insCoach = function(string $cname, ?string $kakao) use ($db): int {
        $db->prepare("INSERT INTO coaches (login_id, password_hash, coach_name, korean_name, birthdate, hired_on, role, evaluation, kakao_room_url, status)
                      VALUES (?, '', ?, ?, '1990-01-01', '2024-01-01', 'pt', 'a', ?, 'active')")
           ->execute(["__t_{$cname}", $cname, "k_{$cname}", $kakao]);
        return (int)$db->lastInsertId();
    };
    $insOrder = function(int $mid, ?int $cid, string $product, string $status, int $joined, ?string $cohort) use ($db): int {
        $db->prepare("INSERT INTO orders (member_id, coach_id, product_name, product_type, start_date, end_date, amount, status, kakao_room_joined, cohort_month)
                      VALUES (?, ?, ?, 'period', '2026-05-01', '2026-05-31', 0, ?, ?, ?)")
           ->execute([$mid, $cid, $product, $status, $joined, $cohort]);
        return (int)$db->lastInsertId();
    };

    $coachOK   = $insCoach('TC_OK',   'https://open.kakao.com/o/sTEST111');
    $coachNoKa = $insCoach('TC_NoKa', null);

    $m1 = $insMember('__t_m1__', '01011112222');   // matched
    $m2 = $insMember('__t_m2__', null);             // phone NULL → skip
    $m3 = $insMember('__t_m3__', '01033334444');   // coach NULL → skip
    $m4 = $insMember('__t_m4__', '01055556666');   // coach 있는데 kakao 없음 → skip
    $m5 = $insMember('__t_m5__', '01077778888');   // status='환불' → 미포함
    $m6 = $insMember('__t_m6__', '01099990000');   // 다른 cohort → 미포함
    $m7 = $insMember('__t_m7__', '01012121212');   // 다른 product → 미포함
    $m8 = $insMember('__t_m8__', '01023232323');   // joined=1 → 미포함
    $m9 = $insMember('__t_m9__', '01034343434');   // 같은 회원 음성PT 2건 → 1행

    $insOrder($m1, $coachOK,   '소리튜닝 음성PT', '진행중', 0, '2026-05');
    $insOrder($m2, $coachOK,   '소리튜닝 음성PT', '진행중', 0, '2026-05');
    $insOrder($m3, null,        '소리튜닝 음성PT', '진행중', 0, '2026-05');
    $insOrder($m4, $coachNoKa, '소리튜닝 음성PT', '진행중', 0, '2026-05');
    $insOrder($m5, $coachOK,   '소리튜닝 음성PT', '환불',  0, '2026-05');
    $insOrder($m6, $coachOK,   '소리튜닝 음성PT', '진행중', 0, '2026-04');
    $insOrder($m7, $coachOK,   '소리튜닝 화상PT', '진행중', 0, '2026-05');
    $insOrder($m8, $coachOK,   '소리튜닝 음성PT', '진행중', 1, '2026-05');
    $insOrder($m9, $coachOK,   '소리튜닝 음성PT', '진행중', 0, '2026-05');
    $insOrder($m9, $coachOK,   '소리튜닝 음성PT', '진행중', 0, '2026-05');

    $cfg = [
        'product_name' => '소리튜닝 음성PT',
        'status' => ['진행중'],
        'kakao_room_joined' => 0,
        'cohort_mode' => '2026-05',  // 고정값으로 테스트
    ];

    $rows = notifySourcePtOrdersQuery($cfg);
    $byM = [];
    foreach ($rows as $r) {
        // row_key: pt_orders:{cohort}:{member_id}
        $parts = explode(':', $r['row_key']);
        $byM[(int)end($parts)] = $r;
    }

    // 1) matched
    t_assert_true(isset($byM[$m1]) && $byM[$m1]['phone'] === '01011112222',
        '1) matched: phone 채워짐');
    t_assert_eq('M___t_m1__', $byM[$m1]['name'] ?? null, '1) matched: name');
    t_assert_eq('TC_OK', $byM[$m1]['columns']['담당 코치'] ?? null, '1) matched: 코치명');
    t_assert_eq('https://open.kakao.com/o/sTEST111', $byM[$m1]['columns']['채팅방 링크'] ?? null,
        '1) matched: 카톡방 링크');

    // 2) phone NULL → row 포함, phone=''
    t_assert_true(isset($byM[$m2]), '2) phone NULL: row 포함');
    t_assert_eq('', $byM[$m2]['phone'] ?? 'X', '2) phone NULL: phone 빈 값');

    // 3) coach NULL → row 포함, phone='' 강제
    t_assert_true(isset($byM[$m3]), '3) coach NULL: row 포함');
    t_assert_eq('', $byM[$m3]['phone'] ?? 'X', '3) coach NULL: phone 빈 값으로 강제');

    // 4) kakao_room_url NULL → row 포함, phone='' 강제
    t_assert_true(isset($byM[$m4]), '4) kakao NULL: row 포함');
    t_assert_eq('', $byM[$m4]['phone'] ?? 'X', '4) kakao NULL: phone 빈 값으로 강제');

    // 5) status='환불' → row 미포함
    t_assert_true(!isset($byM[$m5]), '5) status 필터: 환불 미포함');

    // 6) cohort_month 다른 달 → row 미포함
    t_assert_true(!isset($byM[$m6]), '6) cohort 필터: 다른 달 미포함');

    // 7) product_name 다른 → row 미포함
    t_assert_true(!isset($byM[$m7]), '7) product 필터: 화상PT 미포함');

    // 8) joined=1 → row 미포함
    t_assert_true(!isset($byM[$m8]), '8) joined 필터: 입장 완료 미포함');

    // 9) 같은 회원 2건 → 1행만
    $m9rows = array_filter($rows, fn($r) => str_ends_with($r['row_key'], ":{$m9}"));
    t_assert_eq(1, count($m9rows), '9) DISTINCT 회원 dedup');

    // 10) cohort_mode='current_month_kst' 동작 (현재 월 가져오기 검증)
    $cfgKst = $cfg; $cfgKst['cohort_mode'] = 'current_month_kst';
    $kstMonth = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m');
    $rowsKst = notifySourcePtOrdersQuery($cfgKst);
    if ($kstMonth === '2026-05') {
        t_assert_true(count($rowsKst) > 0, '10) current_month_kst (현재 2026-05) → 행 있음');
    } else {
        t_assert_eq(0, count($rowsKst), "10) current_month_kst ({$kstMonth}) → 시드와 불일치, 0행");
    }

    // 11) cohort_mode invalid 형식 → throw
    t_assert_throws(function() use ($cfg) {
        $bad = $cfg; $bad['cohort_mode'] = '2026-5';  // 0 패딩 누락
        notifySourcePtOrdersQuery($bad);
    }, 'RuntimeException', '11) cohort_mode 형식 위반 throw');

    t_assert_throws(function() use ($cfg) {
        $bad = $cfg; $bad['cohort_mode'] = 'abc';
        notifySourcePtOrdersQuery($bad);
    }, 'RuntimeException', '11b) cohort_mode 임의 문자열 throw');

    // 12) 필수 필드 누락 → throw
    t_assert_throws(function() use ($cfg) {
        $bad = $cfg; unset($bad['product_name']);
        notifySourcePtOrdersQuery($bad);
    }, 'RuntimeException', '12) product_name 누락 throw');

} finally {
    $db->rollBack();
}
```

- [ ] **Step 2: 테스트 실행 → fatal error (함수 미정의)**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -10
```

기대: `Call to undefined function notifySourcePtOrdersQuery()` 또는 require 실패.

- [ ] **Step 3: 어댑터 구현**

`public_html/includes/notify/source_pt_orders_query.php` 신규 생성:

```php
<?php
/**
 * PT 알림톡 어댑터: orders SQL 직접 조회.
 *
 * cfg 구조:
 *   ['type' => 'pt_orders_query',
 *    'product_name' => string,           // 정확 일치
 *    'status' => string[],               // ['진행중'] 등
 *    'kakao_room_joined' => 0|1,         // 0이면 미입장만
 *    'cohort_mode' => 'current_month_kst' | 'YYYY-MM']
 *
 * 반환 row 형태 (dispatcher 인터페이스):
 *   ['row_key' => 'pt_orders:{cohort}:{member_id}',
 *    'phone'   => string (빈 값이면 dispatcher가 phone_invalid skip),
 *    'name'    => string (회원명),
 *    'columns' => ['회원'=>..., '담당 코치'=>..., '채팅방 링크'=>...]]
 *
 * 미매칭 통합 정책: phone NULL / coach_id NULL / kakao_room_url NULL이면
 * row는 그대로 반환하되 phone=''로 강제. dispatcher가 phone_invalid로 일괄 skip.
 */

require_once __DIR__ . '/../db.php';

function notifySourcePtOrdersQuery(array $cfg): array {
    foreach (['product_name', 'status', 'kakao_room_joined', 'cohort_mode'] as $f) {
        if (!array_key_exists($f, $cfg)) {
            throw new RuntimeException("source.pt_orders_query: '{$f}' 누락");
        }
    }
    if (!is_array($cfg['status']) || empty($cfg['status'])) {
        throw new RuntimeException("source.pt_orders_query: 'status'는 비어있지 않은 배열");
    }

    $cohortMonth = notifyPtOrdersResolveCohort((string)$cfg['cohort_mode']);

    $statusPlaceholders = implode(',', array_fill(0, count($cfg['status']), '?'));

    $sql = "
        SELECT
          o.member_id,
          m.name             AS member_name,
          m.phone            AS member_phone,
          c.coach_name       AS coach_name,
          c.kakao_room_url   AS kakao_room_url,
          o.cohort_month
        FROM orders o
        JOIN members m  ON m.id = o.member_id
        LEFT JOIN coaches c ON c.id = o.coach_id
        WHERE o.product_name      = ?
          AND o.kakao_room_joined = ?
          AND o.cohort_month      = ?
          AND o.status IN ({$statusPlaceholders})
        GROUP BY o.member_id
        ORDER BY m.name, o.member_id
    ";

    $params = [
        (string)$cfg['product_name'],
        (int)$cfg['kakao_room_joined'],
        $cohortMonth,
    ];
    foreach ($cfg['status'] as $s) $params[] = (string)$s;

    $db = getDB();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $phone = trim((string)($row['member_phone'] ?? ''));
        $coach = trim((string)($row['coach_name'] ?? ''));
        $kakao = trim((string)($row['kakao_room_url'] ?? ''));

        // 통합 skip 정책: phone/coach/kakao 중 하나라도 비면 phone='' 강제
        if ($phone === '' || $coach === '' || $kakao === '') {
            $phone = '';
        }

        $results[] = [
            'row_key' => sprintf('pt_orders:%s:%d', $cohortMonth, (int)$row['member_id']),
            'phone'   => $phone,
            'name'    => (string)($row['member_name'] ?? ''),
            'columns' => [
                '회원'         => (string)($row['member_name'] ?? ''),
                '담당 코치'    => $coach,
                '채팅방 링크'  => $kakao,
            ],
        ];
    }
    return $results;
}

/**
 * cohort_mode 해석. 서버 timezone에 의존하지 않게 명시적으로 KST 사용.
 * - 'current_month_kst' → 현재 KST 기준 'YYYY-MM'
 * - 'YYYY-MM'           → 그대로 (정규식 검증)
 */
function notifyPtOrdersResolveCohort(string $mode): string {
    if ($mode === 'current_month_kst') {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m');
    }
    if (preg_match('/^\d{4}-\d{2}$/', $mode) === 1) {
        return $mode;
    }
    throw new RuntimeException("source.pt_orders_query: cohort_mode 형식 위반 '{$mode}' (current_month_kst 또는 YYYY-MM)");
}
```

- [ ] **Step 4: 테스트 재실행 → 12+ 케이스 모두 PASS**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -30
```

기대: 모든 어댑터 테스트 PASS, 기존 회귀 없음.

- [ ] **Step 5: 커밋**

```bash
cd /root/pt-dev && git add public_html/includes/notify/source_pt_orders_query.php tests/notify_pt_orders_query_test.php
git commit -m "feat(notify): source_pt_orders_query 어댑터 + 12 케이스 테스트

PT orders/members/coaches JOIN으로 발송 후보 행 생성. 미매칭(phone/coach/kakao_url
누락)은 phone='' 강제로 dispatcher가 phone_invalid skip하도록 통합. cohort_mode는
current_month_kst(KST 명시) 또는 YYYY-MM 고정값."
```

---

## Task 3: dispatcher에 신규 source 통합

**Files:**
- Modify: `public_html/includes/notify/dispatcher.php` (line 9-11 require 영역, line 304-311 `notifyFetchRows` match)

- [ ] **Step 1: require_once 1줄 추가**

`public_html/includes/notify/dispatcher.php` 상단 require 블록에 1줄 추가 (line 10 다음):

```php
require_once __DIR__ . '/source_pt_sheet_member.php';
require_once __DIR__ . '/source_pt_orders_query.php';   // ← 추가
require_once __DIR__ . '/solapi_client.php';
```

- [ ] **Step 2: `notifyFetchRows` match에 한 줄 추가**

기존:
```php
return match ($type) {
    'google_sheet' => notifySourceGoogleSheet($def['source']),
    'pt_sheet_member' => notifySourcePtSheetMember($def['source']),
    default => throw new RuntimeException("미지원 source.type: '{$type}'"),
};
```

변경:
```php
return match ($type) {
    'google_sheet' => notifySourceGoogleSheet($def['source']),
    'pt_sheet_member' => notifySourcePtSheetMember($def['source']),
    'pt_orders_query' => notifySourcePtOrdersQuery($def['source']),
    default => throw new RuntimeException("미지원 source.type: '{$type}'"),
};
```

- [ ] **Step 3: PHP 신택스 확인 + 기존 테스트 회귀 없음 확인**

```bash
cd /root/pt-dev && php -l public_html/includes/notify/dispatcher.php && php tests/run_tests.php 2>&1 | tail -5
```

기대: `No syntax errors detected`, 모든 테스트 PASS (기존 122 + 신규 16+).

- [ ] **Step 4: 커밋**

```bash
cd /root/pt-dev && git add public_html/includes/notify/dispatcher.php
git commit -m "feat(notify): dispatcher에 pt_orders_query source 분기 추가"
```

---

## Task 4: 시나리오 정의 파일

**Files:**
- Create: `public_html/includes/notify/scenarios/pt_kakao_room_remind.php`

- [ ] **Step 1: 시나리오 파일 작성**

```php
<?php
/**
 * 시나리오: 1:1PT 카톡방 입장 리마인드 (음성PT)
 *
 * 매일 19시 KST. 이번 달 cohort 소리튜닝 음성PT 진행중 회원 중 카톡방
 * 미입장자에게 리마인드 알림톡 발송. 입장이 체크되면 다음 발송에서 자동 제외.
 *
 * 운영 적용 가이드:
 *   - is_active=0 기본. 어드민 알림톡 페이지에서 토글 ON.
 *   - cohort_mode='current_month_kst': 매월 자동으로 그 달 cohort로 이동.
 *   - cooldown 23시간: 매일 1회 도배 방지 (cron 분 단위 흔들림 흡수).
 *   - max_attempts 0: 입장할 때까지 무제한 시도.
 */
return [
    'key'         => 'pt_kakao_room_remind',
    'name'        => '카톡방 입장 리마인드 (음성PT)',
    'description' =>
          "매일 19시, 이번 달 cohort 소리튜닝 음성PT 진행중인 회원 중\n"
        . "1:1PT 카톡방 미입장자에게 리마인드 알림톡 발송.\n"
        . "회원 phone, 담당 코치, 코치 카톡방 링크가 모두 있어야 발송.\n"
        . "입장하면 다음 날부터 자동 제외.",

    'source' => [
        'type'              => 'pt_orders_query',
        'product_name'      => '소리튜닝 음성PT',
        'status'            => ['진행중'],
        'kakao_room_joined' => 0,
        'cohort_mode'       => 'current_month_kst',
    ],

    'template' => [
        'templateId'   => 'KA01TP260429031809566vKO0c8WyDAl',
        'fallback_lms' => false,
        'variables' => [
            // notify_functions.php::notifyRenderVariables 형식: '#{변수}' => 'col:컬럼' | 'const:값'
            '#{회원}'        => 'col:회원',
            '#{담당 코치}'   => 'col:담당 코치',
            '#{채팅방 링크}' => 'col:채팅방 링크',
        ],
    ],

    'schedule'       => '0 19 * * *',  // 매일 19:00 KST
    'cooldown_hours' => 23,
    'max_attempts'   => 0,
];
```

- [ ] **Step 2: 시나리오 로드 검증 (CLI 1줄)**

```bash
cd /root/pt-dev && php -r "
require 'public_html/includes/db.php';
require 'public_html/includes/notify/scenario_registry.php';
\$s = notifyLoadScenarios();
var_dump(isset(\$s['pt_kakao_room_remind']));
echo \$s['pt_kakao_room_remind']['name'] . PHP_EOL;
"
```

기대:
```
bool(true)
카톡방 입장 리마인드 (음성PT)
```

(에러 발생 시 시나리오 파일 typo 또는 description 검증 위반 — Task 1 회귀 의심)

- [ ] **Step 3: notify_scenario_state UPSERT 확인 (DEV DB)**

```bash
cd /root/pt-dev && set -a && . ./.db_credentials && set +a && \
  php -r "
require 'public_html/includes/db.php';
require 'public_html/includes/notify/scenario_registry.php';
notifyEnsureScenarioStates(getDB(), notifyLoadScenarios());
" && \
  mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT scenario_key, is_active, last_run_status FROM notify_scenario_state WHERE scenario_key='pt_kakao_room_remind';"
```

기대: 1행, `is_active=0`.

- [ ] **Step 4: 커밋**

```bash
cd /root/pt-dev && git add public_html/includes/notify/scenarios/pt_kakao_room_remind.php
git commit -m "feat(notify/scenarios): pt_kakao_room_remind 시나리오 추가

매일 19시 KST. 이번 달 cohort 소리튜닝 음성PT 진행중 회원 중 카톡방
미입장자 리마인드. cooldown 23h, max_attempts 0(무제한)."
```

---

## Task 5: kongtest29 사전 확인 + DEV DB 시드

**Files:** DEV DB만 변경. 코드 변경 없음. 시드 SQL은 `migrations/`에 추가하지 않음 (테스트 시드, 일회성).

- [ ] **Step 1: kongtest29 사전 상태 확인**

```bash
cd /root/pt-dev && set -a && . ./.db_credentials && set +a && \
  mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT m.id, m.soritune_id, m.name, m.phone,
       o.id AS order_id, o.product_name, o.status, o.cohort_month,
       o.kakao_room_joined, o.coach_id, c.coach_name, c.kakao_room_url
  FROM members m
  LEFT JOIN orders o ON o.member_id = m.id
  LEFT JOIN coaches c ON c.id = o.coach_id
 WHERE m.soritune_id = 'kongtest29';"
```

기대: kongtest29 회원 정보 + 주문 상태 출력.

- [ ] **Step 2: kongtest29 보정 필요 여부 판단 + 보정 SQL 적용**

조건 ALL 충족 여부 확인:
- 음성PT 주문이 status='진행중' + cohort_month='2026-05' + kakao_room_joined=0
- coach_id 매칭됨 + 코치 kakao_room_url 있음
- members.phone 있음

**조건 미충족 시** (Step 1 결과 보고 운영자 결정):
- 회원 phone 없음 → `UPDATE members SET phone='01099999999' WHERE soritune_id='kongtest29';`
- 적합 주문 없음 → `INSERT INTO orders (member_id, coach_id, product_name, product_type, start_date, end_date, amount, status, kakao_room_joined, cohort_month) VALUES ((SELECT id FROM members WHERE soritune_id='kongtest29'), (SELECT id FROM coaches WHERE coach_name='Kel'), '소리튜닝 음성PT', 'period', '2026-05-01', '2026-05-31', 0, '진행중', 0, '2026-05');`
- 코치가 카톡방 URL 없는 코치 → 코치 변경 또는 그 코치에 kakao_room_url 추가 (이미 30 active 중 28개 있음, Frida/Jenny만 없음)

**보정이 끝나면 다시 Step 1 쿼리로 검증**.

- [ ] **Step 3: 일괄 시드 적용 — kongtest29 외 모두 입장 체크**

```bash
cd /root/pt-dev && set -a && . ./.db_credentials && set +a && \
  mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
UPDATE orders o
LEFT JOIN members m ON m.id = o.member_id
   SET o.kakao_room_joined    = 1,
       o.kakao_room_joined_at = COALESCE(o.kakao_room_joined_at, NOW()),
       o.kakao_room_joined_by = NULL
 WHERE o.kakao_room_joined = 0
   AND (m.soritune_id IS NULL OR m.soritune_id <> 'kongtest29');
SELECT ROW_COUNT() AS rows_updated;"
```

기대: 수천 건 update. (DEV는 PROD 복사본이므로 수치 환경 의존)

- [ ] **Step 4: 시드 결과 검증**

```bash
cd /root/pt-dev && set -a && . ./.db_credentials && set +a && \
  mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT m.soritune_id, COUNT(*) AS unjoined
  FROM orders o LEFT JOIN members m ON m.id=o.member_id
 WHERE o.kakao_room_joined = 0
 GROUP BY m.soritune_id;"
```

기대: kongtest29만 (또는 m.soritune_id IS NULL 고아가 있을 수 있음 — 별도 데이터 정합성 이슈, 본 작업 범위 외).

- [ ] **Step 5: 시나리오 SQL이 정확히 kongtest29 1건만 잡는지 검증**

```bash
cd /root/pt-dev && set -a && . ./.db_credentials && set +a && \
  mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT m.soritune_id, m.name, m.phone, c.coach_name, c.kakao_room_url
  FROM orders o
  JOIN members m ON m.id = o.member_id
  LEFT JOIN coaches c ON c.id = o.coach_id
 WHERE o.product_name = '소리튜닝 음성PT'
   AND o.status = '진행중'
   AND o.kakao_room_joined = 0
   AND o.cohort_month = '2026-05'
 GROUP BY o.member_id;"
```

기대: kongtest29 1행, phone/coach/kakao 모두 채워짐.

- [ ] **Step 6: 어댑터 직접 호출로 한 번 더 확인**

```bash
cd /root/pt-dev && php -r "
require 'public_html/includes/db.php';
require 'public_html/includes/notify/source_pt_orders_query.php';
\$rows = notifySourcePtOrdersQuery([
    'product_name' => '소리튜닝 음성PT',
    'status' => ['진행중'],
    'kakao_room_joined' => 0,
    'cohort_mode' => '2026-05',
]);
echo 'rows: ' . count(\$rows) . PHP_EOL;
foreach (\$rows as \$r) {
    echo '  ' . \$r['name'] . ' | phone=' . \$r['phone'] . ' | 코치=' . \$r['columns']['담당 코치'] . PHP_EOL;
}
"
```

기대: `rows: 1` + kongtest29 정보.

(DB 시드 변경은 코드가 아니므로 git 커밋 없음.)

---

## Task 6: 전체 회귀 테스트 + 어드민 dry_run 미리보기 사전 확인

- [ ] **Step 1: 전체 자동 테스트 실행**

```bash
cd /root/pt-dev && php tests/run_tests.php 2>&1 | tail -10
```

기대: 모든 테스트 PASS (122 기존 + 신규 16+ ≈ 140+).

- [ ] **Step 2: 어드민 알림톡 페이지 description 표시 확인**

```bash
cd /root/pt-dev && curl -s -b cookies.txt "https://dev-pt.soritune.com/api/notify.php?action=list" 2>&1 | head -200
```

(또는 사용자가 브라우저에서 https://dev-pt.soritune.com/admin/ 로그인 후 알림톡 메뉴 확인 — Task 7 매뉴얼 검증과 일부 중복)

기대: 응답 JSON에 `pt_kakao_room_remind` 시나리오 + `description` 필드 채워짐.

- [ ] **Step 3: dry_run 미리보기 CLI 호출 (어드민 UI 의존 없이)**

```bash
cd /root/pt-dev && php -r "
require 'public_html/includes/db.php';
require 'public_html/includes/notify/dispatcher.php';
\$db = getDB();
\$scenarios = notifyLoadScenarios();
\$def = \$scenarios['pt_kakao_room_remind'];
\$batchId = notifyRunScenario(\$db, \$def, 'manual', 'cli-test', true);  // dry_run=true
echo 'batch_id: ' . \$batchId . PHP_EOL;
" && \
  set -a && . /root/pt-dev/.db_credentials && set +a && \
  mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT id, status, target_count, sent_count, skipped_count, dry_run
  FROM notify_batch
 WHERE scenario_key='pt_kakao_room_remind'
 ORDER BY id DESC LIMIT 1;
SELECT row_key, name, phone, status, skip_reason, rendered_text
  FROM notify_message
 WHERE batch_id=(SELECT MAX(id) FROM notify_batch WHERE scenario_key='pt_kakao_room_remind');"
```

기대:
- 배치: `target_count=1`, `dry_run=1`, `status='completed'`
- 메시지: 1행, kongtest29, status='dry_run', rendered_text에 변수 3개 JSON 형태로 채워짐.

⚠ kongtest29 phone/coach/kakao 중 누락이 있어 phone=''로 강제됐다면 status='skipped', skip_reason='phone_invalid'. 이 경우 Task 5 Step 2로 돌아가 보정.

(CLI 검증 후 따로 커밋할 코드 변경은 없음.)

---

## Task 7: 사용자 매뉴얼 검증 게이트 (DEV)

**책임:** 사용자가 직접 브라우저에서 검증. 이 task는 사용자 액션 대기.

- [ ] **Step 1: 사용자에게 매뉴얼 검증 시나리오 안내**

검증 시나리오 (https://dev-pt.soritune.com/admin/, admin/soritune!):

1. **알림톡 메뉴 진입** → `pt_kakao_room_remind` 카드 표시
2. **카드에 description 표시 확인**: "매일 19시, ... 입장하면 다음 날부터 자동 제외." (4줄, 회색)
3. **카드 메타 표시 확인**: 스케줄 `0 19 * * *`, cooldown 23h, max attempts 0, 다음 예정 시각, is_active=OFF (체크박스 OFF)
4. **"미리보기" 버튼 클릭** → dry_run 1건 (kongtest29) 결과 표시. 변수 3개 채워짐.
5. **활성 토글 ON → 다시 OFF** (실제 발송은 안 함, DEV에 cron 없으므로). 토글 동작만 확인.
6. **(선택) 다른 시나리오 카드(`form_reminder_ot` 등)도 description 정상 표시되는지 회귀 확인**

- [ ] **Step 2: 사용자 응답 대기**

검증 통과 → Task 8(dev push)로.
이슈 발견 → 해당 task로 돌아가 fix-up 커밋 후 재검증.

(코드/DB 변경 없음, 커밋 없음.)

---

## Task 8: dev push 게이트 (사용자 명시 승인 필요)

**Memory 규칙**: dev push 후 사용자 운영 반영 명시 요청 전까지 main 머지 금지.

- [ ] **Step 1: 사용자 승인 확인**

사용자가 "dev push 진행" 같은 명시 응답.

- [ ] **Step 2: dev push**

```bash
cd /root/pt-dev && git push origin dev 2>&1 | tail -5
```

기대: `dev -> dev` 출력. (Task 1~4의 commit 4개 + Step 1 spec 정정 commit + brainstorm-time spec commit이 origin/dev에 반영)

- [ ] **Step 3: push 결과 확인**

```bash
cd /root/pt-dev && git log --oneline origin/dev -8
```

기대: 최신 4 commits(Task 1-4)가 origin/dev에 보임.

(여기서 일단 멈춤. PROD 반영은 사용자 명시 요청 후 별도 진행.)

---

## Task 9: PROD 배포 게이트 (사용자 명시 승인 후만)

**Memory 규칙**: 사용자가 "운영 반영해줘" 같은 명시 요청한 경우에만 진행.

- [ ] **Step 1: main 머지 + push**

```bash
cd /root/pt-dev && git checkout main && git merge dev --no-ff -m "merge: dev → main: PT 카톡방 리마인드 알림톡 시나리오" && git push origin main && git checkout dev && git log --oneline -3 main
```

- [ ] **Step 2: pt-prod git pull**

```bash
cd /root/pt-prod && git pull origin main 2>&1 | tail -5 && git log --oneline -2
```

- [ ] **Step 3: PROD 서버 timezone 확인 (cron 등록 전 안전 점검)**

```bash
date && timedatectl 2>/dev/null | grep -i 'time zone' || cat /etc/timezone 2>/dev/null
```

기대: KST 또는 Asia/Seoul. 만약 UTC면 `0 19 * * *` 대신 `0 10 * * *`로 등록 (UTC 10:00 = KST 19:00).

- [ ] **Step 4: dispatcher cron 등록**

기존 crontab에 dispatcher 라인 없는지 먼저 확인:

```bash
crontab -l 2>/dev/null | grep notify_dispatch
```

빈 결과면 추가:

```bash
( crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php /root/pt-prod/cron/notify_dispatch.php >> /root/pt-prod/cron/logs/notify_dispatch.log 2>&1" ) | crontab -
```

(이미 라인 있으면 스킵 — 동일 라인 두 번 등록되지 않게)

확인:

```bash
crontab -l | grep notify_dispatch
mkdir -p /root/pt-prod/cron/logs
```

- [ ] **Step 5: PROD scenario_state UPSERT (시나리오 PROD DB에 등록)**

```bash
cd /root/pt-prod && php -r "
require 'public_html/includes/db.php';
require 'public_html/includes/notify/scenario_registry.php';
notifyEnsureScenarioStates(getDB(), notifyLoadScenarios());
" && \
  set -a && . /root/pt-prod/.db_credentials && set +a && \
  mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT scenario_key, is_active FROM notify_scenario_state WHERE scenario_key='pt_kakao_room_remind';"
```

기대: 1행, `is_active=0` (기본 OFF).

- [ ] **Step 6: PROD 어드민에서 토글 ON (사용자가 직접)**

사용자에게 안내:
> https://pt.soritune.com/admin/ → 알림톡 메뉴 → `pt_kakao_room_remind` 카드의 활성 토글 ON.

- [ ] **Step 7: 19:00 직후 PROD 배치 결과 확인 (사용자 + 자동 알림 X, 사후 점검)**

```bash
cd /root/pt-prod && set -a && . ./.db_credentials && set +a && \
  mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT id, started_at, finished_at, status, target_count, sent_count, failed_count, skipped_count, dry_run
  FROM notify_batch
 WHERE scenario_key='pt_kakao_room_remind'
 ORDER BY id DESC LIMIT 5;"
```

이슈가 보이면 (`status='failed'` 등) `notify_message` 테이블에서 `fail_reason`/`skip_reason` 확인.

(이 단계는 19:00 이후 사용자 또는 별도 follow-up 세션에서 확인.)

---

## Task 10: 메모리 업데이트

- [ ] **Step 1: 새 memory 파일 작성**

`/root/.claude/projects/-root/memory/project_pt_kakao_room_remind_completed.md`:

```markdown
---
name: PT 카톡방 입장 리마인드 알림톡 시나리오
description: pt.soritune.com — 새 시나리오 pt_kakao_room_remind. 매일 19시 KST 이번 달 cohort 음성PT 카톡방 미입장자 리마인드. PT 알림톡 인프라 첫 운영 시나리오.
type: project
---
## 완료 상태 (2026-05-01 PROD 배포)

- **PROD HEAD**: <main 머지 커밋 SHA>
- **DEV HEAD**: <dev 최신 SHA>
- **PROD cron**: `* * * * * /usr/bin/php /root/pt-prod/cron/notify_dispatch.php` 등록
- **PROD 토글**: 사용자가 어드민에서 ON 결정
- **자동 테스트**: 신규 16 케이스 + 기존 122 = 138+ PASS

## 인사이트

- description 인프라(API list 응답, notify.js 렌더, notify-desc CSS)는 boot에서 그대로 복사돼 이미 지원됨. UI 변경 없이 시나리오 PHP에 description 추가만으로 표시 가능.
- 시나리오 variables 키는 솔라피 `#{변수}` 형식. notify_functions.php::notifyRenderVariables가 'col:'/'const:' prefix 강제, 알 수 없는 prefix는 fail-loud throw.
- pt_orders_query 어댑터는 missing-data(phone/coach/kakao_url 누락)를 phone='' 강제로 dispatcher의 phone_invalid skip 분기로 통합 → 단순화.
- cohort_mode='current_month_kst'로 매월 자동 이동. 서버 timezone 의존 없이 명시적 Asia/Seoul.
```

- [ ] **Step 2: MEMORY.md 인덱스에 추가**

`/root/.claude/projects/-root/memory/MEMORY.md` "완료된 작업" 섹션 상단에:

```
- [PT 카톡방 입장 리마인드 알림톡](project_pt_kakao_room_remind_completed.md) — 2026-05-01 PROD 배포 완료. PT 알림톡 첫 운영 시나리오. 매일 19시, 이번 달 cohort 음성PT 카톡방 미입장자 리마인드. 새 어댑터 source_pt_orders_query, 16 신규 테스트.
```

(`pt_notify_wip` 항목은 별개 작업이므로 그대로 유지.)

---

## Self-Review

### Spec coverage

- §2 시나리오 정의 → Task 4 ✓
- §3 어댑터 → Task 2, dispatcher 통합 → Task 3 ✓
- §3.7 스모크 9 케이스 → Task 2 12+ 케이스 ✓
- §4.2 scenario_registry description 검증 → Task 1 ✓
- §4.1 description UI는 이미 인프라 있음 → Task 6 Step 2 회귀 확인만
- §5 DEV DB 시드 → Task 5 ✓
- §6 PROD cron → Task 9 Step 4 ✓
- §6 KST 타임존 안전점검 → Task 9 Step 3 ✓

### Placeholder scan

- Task 9 Step 1의 머지 커밋 SHA, Task 10 Step 1의 SHA는 실행 시점에서야 결정되므로 `<...>` placeholder는 의도적. 실행 단계에서 채움.
- 그 외 TBD 없음.

### Type/이름 일관성

- 어댑터 함수명 `notifySourcePtOrdersQuery` → Task 2/3/5/6 모두 동일.
- cohort_mode 두 모드(`current_month_kst` / `YYYY-MM`) → Task 2 Step 1/3, Task 4, Task 5 Step 6 일치.
- row 형태 4 키 (`row_key`/`phone`/`name`/`columns`) → Task 2 테스트와 구현 일치, dispatcher 인터페이스 (line 154-227) 일치.
- 시나리오 key `pt_kakao_room_remind` → 모든 task 일치.
