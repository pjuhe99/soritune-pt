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
          AND o.coupon_issued     = 0
          AND o.special_case      = 0
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
