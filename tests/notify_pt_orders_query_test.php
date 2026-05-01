<?php
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
        $db->prepare("INSERT INTO coaches (login_id, password_hash, coach_name, korean_name, kakao_room_url, status)
                      VALUES (?, '', ?, ?, ?, 'active')")
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
    t_assert_true(isset($byM[$m1]) && ($byM[$m1]['phone'] ?? '') === '01011112222',
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
