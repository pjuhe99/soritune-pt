<?php
/**
 * 리텐션 계산 로직
 *
 * calculateRetention(PDO $db, string $baseMonth, int $totalNew, int $adminId): array
 *
 * - Reads coach_member_mapping / retention_score_criteria / grade_criteria /
 *   coach_assignment_requests from SORITUNECOM_COACH (read-only cross-DB).
 * - Writes snapshot rows to PT coach_retention_scores and coach_retention_runs.
 *
 * 월 시프트: base_month=YYYY-MM 이면 current 후보는 [base-1, base-2, base-3].
 * 각 current에 대해 prev=current-1, prevPrev=current-2.
 * 측정 구간 = prev→current 세 번 (배정이 끝난 월만 반영).
 */

declare(strict_types=1);

require_once __DIR__ . '/coach_mapping.php';

/**
 * @return array{
 *   rows: array<int, array>,
 *   unmapped_coaches: array{pt_only: string[], coach_site_only: string[]},
 *   summary: array{total_new:int, sum_auto:int, sum_final:int, unallocated:int},
 * }
 */
function calculateRetention(PDO $db, string $baseMonth, int $totalNew, int $adminId): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $baseMonth)) {
        throw new InvalidArgumentException('base_month must be YYYY-MM');
    }

    // 1. Build month list (month-shifted by 1: [base-1, base-2, base-3])
    $months = [];
    for ($i = 1; $i <= 3; $i++) {
        $months[] = date('Y-m', strtotime("{$baseMonth}-01 -{$i} months"));
    }

    // 2. Load coach mapping + coach-site criteria
    $map = loadCoachMapping($db);
    $retCriteria   = _retCriteriaLoad($db);
    $gradeCriteria = _gradeCriteriaLoad($db);

    // 3. Compute per-coach retention snapshot (PT coaches only that are mapped)
    $rows = [];
    foreach ($map['pt_by_name'] as $name => $pt) {
        $ptId      = $pt['id'];
        $coachId   = $map['pt_to_coach'][$ptId] ?? null;

        if ($coachId === null) {
            // PT-only coach: create zeroed row
            $rows[] = _emptyRow($ptId, $name);
            continue;
        }

        $rows[] = _computeOneCoach($db, $ptId, $name, $coachId, $months, $retCriteria);
    }

    // 4. Sort by total_score desc, assign rank_num (동점 동등수)
    usort($rows, fn($a, $b) => $b['total_score'] <=> $a['total_score']);
    $rank = 1;
    $total = count($rows);
    for ($i = 0; $i < $total; $i++) {
        if ($i > 0 && $rows[$i]['total_score'] < $rows[$i - 1]['total_score']) {
            $rank = $i + 1;
        }
        $rows[$i]['rank_num'] = $rank;
    }

    // 5. Assign grades by proportion (from grade_criteria.ratio)
    $gradeAssignments = [];
    $assignedSoFar = 0;
    foreach ($gradeCriteria as $gc) {
        $count = (int)round($total * (float)$gc['ratio']);
        if ($gc === end($gradeCriteria)) {
            $count = $total - $assignedSoFar;
        }
        $gradeAssignments[] = [
            'grade'       => $gc['grade'],
            'count'       => $count,
            'hope_ratio'  => $gc['hope_assignment_ratio'],
            'remain_ratio'=> $gc['remaining_assignment_ratio'],
        ];
        $assignedSoFar += $count;
    }

    // 6. Allocate new members (상위권 고정, 하위권 가중치 분배)
    $idx = 0;
    $upperAlloc = 0;
    $lowerIndices = [];

    foreach ($gradeAssignments as $ga) {
        for ($j = 0; $j < $ga['count'] && $idx < $total; $j++, $idx++) {
            $rows[$idx]['grade'] = $ga['grade'];

            $coachId = $map['pt_to_coach'][$rows[$idx]['coach_id']] ?? null;
            $reqCount = $coachId !== null
                ? _getLatestRequest($db, $coachId, $baseMonth)
                : 0;
            $rows[$idx]['requested_count'] = $reqCount;

            if ($ga['hope_ratio'] !== null) {
                // 상위권: round(희망 × hope_ratio)
                $alloc = (int)round($reqCount * (float)$ga['hope_ratio']);
                $rows[$idx]['auto_allocation'] = $alloc;
                $upperAlloc += $alloc;
            } else {
                // 하위권: 가중치만 계산, 실제 배정은 2단계
                $weight = $reqCount * (float)($ga['remain_ratio'] ?? 0);
                $rows[$idx]['_weight'] = $weight;
                $rows[$idx]['auto_allocation'] = 0;
                $lowerIndices[] = $idx;
            }
        }
    }

    $remaining = max(0, $totalNew - $upperAlloc);
    $totalWeight = 0;
    foreach ($lowerIndices as $li) {
        $totalWeight += $rows[$li]['_weight'];
    }

    if ($totalWeight > 0 && $remaining > 0) {
        $allocated = 0;
        $lastIdx = end($lowerIndices);
        foreach ($lowerIndices as $li) {
            if ($li === $lastIdx) {
                $alloc = $remaining - $allocated;
            } else {
                $alloc = (int)round(($rows[$li]['_weight'] / $totalWeight) * $remaining);
            }
            $rows[$li]['auto_allocation'] = $alloc;
            $allocated += $alloc;
        }
    } elseif ($remaining > 0 && count($lowerIndices) > 0) {
        $each = (int)floor($remaining / count($lowerIndices));
        $leftover = $remaining - ($each * count($lowerIndices));
        foreach ($lowerIndices as $i => $li) {
            $rows[$li]['auto_allocation'] = $each + ($i < $leftover ? 1 : 0);
        }
    }

    foreach ($rows as &$r) {
        unset($r['_weight']);
    }
    unset($r);

    // 7. UPSERT into coach_retention_scores + coach_retention_runs
    $db->beginTransaction();
    try {
        $upsert = $db->prepare("
            INSERT INTO coach_retention_scores
              (coach_id, coach_name_snapshot, base_month, grade, rank_num,
               total_score, new_retention_3m, existing_retention_3m,
               assigned_members, requested_count, auto_allocation, final_allocation,
               monthly_detail)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              coach_name_snapshot = VALUES(coach_name_snapshot),
              grade = VALUES(grade),
              rank_num = VALUES(rank_num),
              total_score = VALUES(total_score),
              new_retention_3m = VALUES(new_retention_3m),
              existing_retention_3m = VALUES(existing_retention_3m),
              assigned_members = VALUES(assigned_members),
              requested_count = VALUES(requested_count),
              auto_allocation = VALUES(auto_allocation),
              monthly_detail = VALUES(monthly_detail)
        ");

        foreach ($rows as $r) {
            $upsert->execute([
                $r['coach_id'],
                $r['coach_name_snapshot'],
                $baseMonth,
                $r['grade'] ?? 'D',
                $r['rank_num'],
                $r['total_score'],
                $r['new_retention_3m'],
                $r['existing_retention_3m'],
                $r['assigned_members'],
                $r['requested_count'],
                $r['auto_allocation'],
                $r['auto_allocation'], // final_allocation 초기값
                json_encode($r['monthly_detail'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        // runs upsert
        $db->prepare("
            INSERT INTO coach_retention_runs
              (base_month, total_new, unmapped_coaches, calculated_at, calculated_by)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
              total_new = VALUES(total_new),
              unmapped_coaches = VALUES(unmapped_coaches),
              calculated_at = NOW(),
              calculated_by = VALUES(calculated_by)
        ")->execute([
            $baseMonth,
            $totalNew,
            json_encode([
                'pt_only'         => $map['pt_only'],
                'coach_site_only' => $map['coach_site_only'],
            ], JSON_UNESCAPED_UNICODE),
            $adminId,
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // 8. Build final summary using stored values (includes preserved final_allocation for existing months)
    $viewRows = _fetchSnapshotRows($db, $baseMonth);
    $sumAuto  = array_sum(array_column($viewRows, 'auto_allocation'));
    $sumFinal = array_sum(array_column($viewRows, 'final_allocation'));

    return [
        'rows' => $viewRows,
        'unmapped_coaches' => [
            'pt_only'         => $map['pt_only'],
            'coach_site_only' => $map['coach_site_only'],
        ],
        'summary' => [
            'total_new'   => $totalNew,
            'sum_auto'    => (int)$sumAuto,
            'sum_final'   => (int)$sumFinal,
            'unallocated' => (int)$totalNew - (int)$sumFinal,
        ],
    ];
}

/**
 * Fetch a snapshot with display fields.
 * @return array<int, array<string, mixed>>
 */
function _fetchSnapshotRows(PDO $db, string $baseMonth): array
{
    $stmt = $db->prepare("
        SELECT id, coach_id, coach_name_snapshot, base_month, grade, rank_num,
               total_score, new_retention_3m, existing_retention_3m,
               assigned_members, requested_count, auto_allocation, final_allocation,
               adjusted_by, adjusted_at, monthly_detail, updated_at
          FROM coach_retention_scores
         WHERE base_month = ?
         ORDER BY rank_num ASC, total_score DESC
    ");
    $stmt->execute([$baseMonth]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['monthly_detail'] = $r['monthly_detail'] ? json_decode($r['monthly_detail'], true) : [];
        $r['coach_id']        = $r['coach_id'] !== null ? (int)$r['coach_id'] : null;
        $r['id']              = (int)$r['id'];
        $r['rank_num']        = (int)$r['rank_num'];
        $r['assigned_members']= (int)$r['assigned_members'];
        $r['requested_count'] = (int)$r['requested_count'];
        $r['auto_allocation'] = (int)$r['auto_allocation'];
        $r['final_allocation']= (int)$r['final_allocation'];
    }
    unset($r);

    return $rows;
}

/** Empty row for PT-only coaches. */
function _emptyRow(int $ptId, string $name): array
{
    return [
        'coach_id'              => $ptId,
        'coach_name_snapshot'   => $name,
        'total_score'           => 0.0,
        'new_retention_3m'      => 0.0,
        'existing_retention_3m' => 0.0,
        'assigned_members'      => 0,
        'requested_count'       => 0,
        'auto_allocation'       => 0,
        'monthly_detail'        => [],
    ];
}

/**
 * Compute retention + score for a single (mapped) coach.
 */
function _computeOneCoach(
    PDO $db,
    int $ptId,
    string $ptName,
    int $coachSiteId,
    array $months,
    array $retCriteria
): array {
    // 담당 회원 수: 최근 mapping 기준 DISTINCT member_id
    $assignedStmt = $db->prepare(
        "SELECT COUNT(DISTINCT member_id)
         FROM SORITUNECOM_COACH.coach_member_mapping
         WHERE coach_id = ?"
    );
    $assignedStmt->execute([$coachSiteId]);
    $assignedCount = (int)$assignedStmt->fetchColumn();

    $monthlyDetail = [];
    $newSum = 0; $existSum = 0;
    $newCount = 0; $existCount = 0;

    foreach ($months as $currentMonth) {
        $prevMonth     = date('Y-m', strtotime("{$currentMonth}-01 -1 month"));
        $prevPrevMonth = date('Y-m', strtotime("{$prevMonth}-01 -1 month"));

        // 전월 배정 회원 목록
        $prevStmt = $db->prepare("
            SELECT DISTINCT member_id
              FROM SORITUNECOM_COACH.coach_member_mapping
             WHERE coach_id = ? AND period = ?
        ");
        $prevStmt->execute([$coachSiteId, $prevMonth]);
        $prevMembers = $prevStmt->fetchAll();

        $newTotal = 0; $newRepurchase = 0;
        $existTotal = 0; $existRepurchase = 0;

        $existCheck = $db->prepare("
            SELECT COUNT(*)
              FROM SORITUNECOM_COACH.coach_member_mapping
             WHERE coach_id = ? AND member_id = ? AND period = ?
        ");
        $retainCheck = $db->prepare("
            SELECT COUNT(*)
              FROM SORITUNECOM_COACH.coach_member_mapping
             WHERE coach_id = ? AND member_id = ? AND period = ?
        ");

        foreach ($prevMembers as $m) {
            $memberId = $m['member_id'];

            $existCheck->execute([$coachSiteId, $memberId, $prevPrevMonth]);
            $isNew = (int)$existCheck->fetchColumn() === 0;

            $retainCheck->execute([$coachSiteId, $memberId, $currentMonth]);
            $hasRetention = (int)$retainCheck->fetchColumn() > 0;

            if ($isNew) {
                $newTotal++;
                if ($hasRetention) $newRepurchase++;
            } else {
                $existTotal++;
                if ($hasRetention) $existRepurchase++;
            }
        }

        $newRate   = $newTotal   > 0 ? $newRepurchase   / $newTotal   : 0;
        $existRate = $existTotal > 0 ? $existRepurchase / $existTotal : 0;

        $monthlyDetail[] = [
            'month'                => $currentMonth,
            'prev_month'           => $prevMonth,
            'new_total'            => $newTotal,
            'new_repurchase'       => $newRepurchase,
            'new_retention_rate'   => round($newRate, 10),
            'exist_total'          => $existTotal,
            'exist_repurchase'     => $existRepurchase,
            'exist_retention_rate' => round($existRate, 10),
        ];

        if ($newTotal   > 0) { $newSum   += $newRate;   $newCount++; }
        if ($existTotal > 0) { $existSum += $existRate; $existCount++; }
    }

    $avgNew   = $newCount   > 0 ? $newSum   / $newCount   : 0;
    $avgExist = $existCount > 0 ? $existSum / $existCount : 0;

    $newScore   = _retentionToScore($avgNew,   $retCriteria, 'new');
    $existScore = _retentionToScore($avgExist, $retCriteria, 'existing');

    return [
        'coach_id'              => $ptId,
        'coach_name_snapshot'   => $ptName,
        'total_score'           => $newScore + $existScore,
        'new_retention_3m'      => $avgNew,
        'existing_retention_3m' => $avgExist,
        'assigned_members'      => $assignedCount,
        'requested_count'       => 0,  // filled later in grade assignment phase
        'auto_allocation'       => 0,  // filled later
        'monthly_detail'        => $monthlyDetail,
    ];
}

function _retCriteriaLoad(PDO $db): array
{
    return $db->query("
        SELECT retention_rate_min, new_member_score, existing_member_score
          FROM SORITUNECOM_COACH.retention_score_criteria
         ORDER BY retention_rate_min ASC
    ")->fetchAll();
}

function _gradeCriteriaLoad(PDO $db): array
{
    return $db->query("
        SELECT grade, grade_order, ratio, hope_assignment_ratio, remaining_assignment_ratio
          FROM SORITUNECOM_COACH.grade_criteria
         ORDER BY grade_order ASC
    ")->fetchAll();
}

function _retentionToScore(float $rate, array $criteria, string $type = 'new'): float
{
    $col = $type === 'new' ? 'new_member_score' : 'existing_member_score';
    $score = 0.0;
    foreach ($criteria as $row) {
        if ($rate >= (float)$row['retention_rate_min']) {
            $score = (float)$row[$col];
        }
    }
    return $score;
}

function _getLatestRequest(PDO $db, int $coachSiteId, string $period): int
{
    $stmt = $db->prepare("
        SELECT requested_count
          FROM SORITUNECOM_COACH.coach_assignment_requests
         WHERE coach_id = ? AND period = ?
         ORDER BY request_date DESC
         LIMIT 1
    ");
    $stmt->execute([$coachSiteId, $period]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : 0;
}
