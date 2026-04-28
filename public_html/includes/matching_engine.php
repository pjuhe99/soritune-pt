<?php
/**
 * 매칭 엔진 — 매칭대기 orders 분류 + 분배 + drafts INSERT
 *
 * 흐름:
 *   1) 매칭대기 orders 전체 로드 (active draft에 이미 들어가있는 건 제외)
 *   2) 각 order에 대해 _classifyOrder() — '이전 코치' or '신규 풀' 분류
 *   3) '신규 풀' 묶음에 _distributeNewPool() — capacity_snapshot 기반 슬롯 셔플 zip
 *   4) 결과를 coach_assignment_drafts에 batch INSERT
 *   5) coach_assignment_runs 통계 컬럼 update
 *
 * 반환: ['total'=>N, 'prev_coach'=>N, 'new_pool'=>N, 'matched'=>N, 'unmatched'=>N]
 */

require_once __DIR__ . '/db.php';

/**
 * Run matching for a newly-created batch.
 *
 * @param PDO    $db
 * @param int    $batchId            coach_assignment_runs.id
 * @param string $baseMonth          YYYY-MM (사용된 final_allocation의 기준월, 메모용)
 * @param array  $capacitySnapshot   [['coach_id'=>1,'coach_name'=>'Tia','final_allocation'=>10], ...]
 *                                   coaches.status='active' 만 포함되어 있다고 가정
 * @return array stats
 */
function runMatchingForBatch(PDO $db, int $batchId, string $baseMonth, array $capacitySnapshot): array {
    $orders = _fetchUnmatchedOrders($db);
    $prevCoachRows = [];
    $newPoolRows   = [];

    foreach ($orders as $o) {
        $cls = _classifyOrder($db, $o);
        if ($cls['source'] === 'previous_coach') {
            $prevCoachRows[] = array_merge(['order_id' => (int)$o['id']], $cls);
        } else {
            // unresolved → 신규 풀로 보냄. 단, 참고 정보(prev_coach_id, prev_end_date)는 유지.
            $newPoolRows[] = array_merge(['order_id' => (int)$o['id']], $cls);
        }
    }

    $distributed = _distributeNewPool($newPoolRows, $capacitySnapshot);
    $allRows = array_merge($prevCoachRows, $distributed);

    _insertDrafts($db, $batchId, $allRows);
    $stats = _summarize($allRows);

    $upd = $db->prepare("
        UPDATE coach_assignment_runs
           SET total_orders     = ?,
               prev_coach_count = ?,
               new_pool_count   = ?,
               matched_count    = ?,
               unmatched_count  = ?
         WHERE id = ?
    ");
    $upd->execute([
        $stats['total'], $stats['prev_coach'], $stats['new_pool'],
        $stats['matched'], $stats['unmatched'], $batchId
    ]);

    return $stats;
}

/**
 * 매칭대기이고 active draft batch에 들어가있지 않은 orders.
 */
function _fetchUnmatchedOrders(PDO $db): array {
    $stmt = $db->prepare("
        SELECT o.id, o.member_id, o.coach_id, o.product_name, o.start_date, o.end_date, o.status
          FROM orders o
         WHERE o.status = '매칭대기'
           AND NOT EXISTS (
                 SELECT 1 FROM coach_assignment_drafts d
                          JOIN coach_assignment_runs r ON r.id = d.batch_id
                  WHERE d.order_id = o.id AND r.status = 'draft'
           )
         ORDER BY o.start_date, o.id
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * 단일 order에 대해 이전 코치 / 신규 풀 분류.
 *
 * 반환:
 *   ['source'=>'previous_coach',   'proposed_coach_id'=>$cid, 'prev_coach_id'=>$cid, 'prev_end_date'=>$d, 'reason'=>...]
 *   ['source'=>'new_pool',         'proposed_coach_id'=>null, 'prev_coach_id'=>$pid|null, 'prev_end_date'=>$d|null, 'reason'=>...]
 */
function _classifyOrder(PDO $db, array $current): array {
    $stmt = $db->prepare("
        SELECT id, coach_id, end_date
          FROM orders
         WHERE member_id = ?
           AND id        != ?
           AND status NOT IN ('환불','중단')
         ORDER BY end_date DESC
         LIMIT 1
    ");
    $stmt->execute([$current['member_id'], $current['id']]);
    $prev = $stmt->fetch();

    if (!$prev) {
        return [
            'source' => 'new_pool',
            'proposed_coach_id' => null,
            'prev_coach_id'     => null,
            'prev_end_date'     => null,
            'reason'            => '이전 PT 이력 없음 → 신규 풀',
        ];
    }

    $prevCoachId  = $prev['coach_id'] ? (int)$prev['coach_id'] : null;
    $prevEndDate  = $prev['end_date'];

    // gap_days = current.start_date - prev.end_date
    $gapDays = (int)((strtotime($current['start_date']) - strtotime($prevEndDate)) / 86400);

    if ($prevCoachId === null) {
        return [
            'source' => 'new_pool',
            'proposed_coach_id' => null,
            'prev_coach_id'     => null,
            'prev_end_date'     => $prevEndDate,
            'reason'            => "직전 PT의 코치 미지정 → 신규 풀 (gap {$gapDays}일)",
        ];
    }

    $coachStmt = $db->prepare("SELECT status FROM coaches WHERE id = ?");
    $coachStmt->execute([$prevCoachId]);
    $coachRow = $coachStmt->fetch();
    $coachStatus = $coachRow['status'] ?? null;

    if ($coachStatus !== 'active') {
        return [
            'source' => 'new_pool',
            'proposed_coach_id' => null,
            'prev_coach_id'     => $prevCoachId,
            'prev_end_date'     => $prevEndDate,
            'reason'            => "이전 코치 inactive → 신규 풀 (gap {$gapDays}일)",
        ];
    }

    if ($gapDays >= 365) {
        return [
            'source' => 'new_pool',
            'proposed_coach_id' => null,
            'prev_coach_id'     => $prevCoachId,
            'prev_end_date'     => $prevEndDate,
            'reason'            => "이전 PT 종료 후 {$gapDays}일 경과 → 신규 풀",
        ];
    }

    return [
        'source' => 'previous_coach',
        'proposed_coach_id' => $prevCoachId,
        'prev_coach_id'     => $prevCoachId,
        'prev_end_date'     => $prevEndDate,
        'reason'            => "직전 PT (~{$prevEndDate}, gap {$gapDays}일) 담당",
    ];
}

/**
 * 신규 풀을 capacity 슬롯 셔플 zip으로 분배.
 * 풀 < 슬롯: 일부 슬롯 빈 채로 종료 (코치별 정원 미달).
 * 풀 > 슬롯: 남은 풀은 source='unmatched', proposed_coach_id=null.
 *
 * 입력 row 형태(_classifyOrder의 'new_pool' 결과 + order_id):
 *   ['order_id'=>N, 'source'=>'new_pool', 'proposed_coach_id'=>null,
 *    'prev_coach_id'=>X, 'prev_end_date'=>Y, 'reason'=>...]
 *
 * 반환: 같은 형식이지만 분배 후 source/proposed_coach_id/reason 갱신됨.
 */
function _distributeNewPool(array $pool, array $capacitySnapshot): array {
    if (empty($pool)) return [];

    // 1) capacity 슬롯 만들기
    $slots = [];
    foreach ($capacitySnapshot as $c) {
        $cap = (int)$c['final_allocation'];
        for ($i = 0; $i < $cap; $i++) {
            $slots[] = (int)$c['coach_id'];
        }
    }

    // 2) 풀 셔플 + 슬롯 셔플
    shuffle($pool);
    shuffle($slots);

    // 3) zip
    $result = [];
    $slotIdx = 0;
    foreach ($pool as $row) {
        if ($slotIdx < count($slots)) {
            $row['source']            = 'new_pool';
            $row['proposed_coach_id'] = $slots[$slotIdx];
            $row['reason']            = '신규 풀 무작위 추첨';
            $slotIdx++;
        } else {
            $row['source']            = 'unmatched';
            $row['proposed_coach_id'] = null;
            $row['reason']            = '이번 batch 신규 capacity 부족';
        }
        $result[] = $row;
    }

    return $result;
}

function _insertDrafts(PDO $db, int $batchId, array $rows): void {
    if (empty($rows)) return;
    $stmt = $db->prepare("
        INSERT INTO coach_assignment_drafts
          (batch_id, order_id, proposed_coach_id, source, prev_coach_id, prev_end_date, reason)
        VALUES (?,?,?,?,?,?,?)
    ");
    foreach ($rows as $r) {
        $stmt->execute([
            $batchId,
            (int)$r['order_id'],
            $r['proposed_coach_id'],
            $r['source'],
            $r['prev_coach_id'],
            $r['prev_end_date'],
            $r['reason'],
        ]);
    }
}

function _summarize(array $rows): array {
    $total = count($rows);
    $prevCoach = 0; $newPool = 0; $matched = 0; $unmatched = 0;
    foreach ($rows as $r) {
        if ($r['source'] === 'previous_coach') { $prevCoach++; $matched++; }
        elseif ($r['source'] === 'new_pool')   { $newPool++;   $matched++; }
        elseif ($r['source'] === 'unmatched')  { $newPool++;   $unmatched++; }
    }
    return [
        'total'      => $total,
        'prev_coach' => $prevCoach,
        'new_pool'   => $newPool,
        'matched'    => $matched,
        'unmatched'  => $unmatched,
    ];
}
