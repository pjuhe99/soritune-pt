<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coach_team_guard.php';
require_once __DIR__ . '/../includes/coach_training.php';

/**
 * training_date 검증: YYYY-MM-DD + checkdate + 요일=COACH_TRAINING_DOW.
 * @throws InvalidArgumentException
 */
function validateTrainingDate(string $date): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        throw new InvalidArgumentException('training_date 형식 오류 (YYYY-MM-DD)');
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new InvalidArgumentException('training_date 유효하지 않은 일자');
    }
    $dt = new DateTimeImmutable($date);
    if ((int)$dt->format('N') !== COACH_TRAINING_DOW) {
        throw new InvalidArgumentException('training_date는 교육 요일이어야 합니다');
    }
    return $date;
}

/**
 * 직전 N회 + 그 이전 M회 (총 12회) 출석 이력.
 * recent: 직전 4회 (출석율 분모)
 * earlier: 5~12번째
 * 권한 검증은 caller 책임.
 */
function listAttendanceHistory(PDO $db, int $coachId, DateTimeImmutable $nowKst): array
{
    $totalCount = COACH_TRAINING_RECENT_COUNT; // 4
    $allDates = recentTrainingDates($nowKst, 12); // DESC

    $placeholders = implode(',', array_fill(0, count($allDates), '?'));
    $stmt = $db->prepare("
        SELECT a.training_date, a.marked_at, c.coach_name AS marked_by_name
          FROM coach_training_attendance a
          JOIN coaches c ON c.id = a.marked_by
         WHERE a.coach_id = ?
           AND a.training_date IN ({$placeholders})
    ");
    $stmt->execute(array_merge([$coachId], $allDates));
    $byDate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byDate[$r['training_date']] = $r;
    }

    $build = [];
    foreach ($allDates as $d) {
        $hit = $byDate[$d] ?? null;
        $build[] = [
            'date'           => $d,
            'attended'       => $hit ? 1 : 0,
            'marked_at'      => $hit['marked_at'] ?? null,
            'marked_by_name' => $hit['marked_by_name'] ?? null,
        ];
    }

    $recent  = array_slice($build, 0, $totalCount);
    $earlier = array_slice($build, $totalCount);
    $attendedCount = 0;
    foreach ($recent as $r) $attendedCount += $r['attended'];

    return [
        'recent'          => $recent,
        'earlier'         => $earlier,
        'attended_count'  => $attendedCount,
        'total_count'     => $totalCount,
        'attendance_rate' => $totalCount > 0 ? round($attendedCount / $totalCount, 4) : 0.0,
    ];
}

/**
 * 출석 토글 (멱등). row 존재=출석. 권한 검증은 caller 책임.
 *
 * @return bool true if state changed
 * @throws InvalidArgumentException
 */
function toggleAttendance(
    PDO $db, int $coachId, string $trainingDate, bool $attended, int $markedBy
): bool {
    $trainingDate = validateTrainingDate($trainingDate);

    $sel = $db->prepare("
        SELECT id FROM coach_training_attendance
         WHERE coach_id = ? AND training_date = ?
    ");
    $sel->execute([$coachId, $trainingDate]);
    $existing = $sel->fetchColumn();

    if ($attended) {
        if ($existing !== false) return false; // no-op
        try {
            $ins = $db->prepare("
                INSERT INTO coach_training_attendance (coach_id, training_date, marked_by)
                VALUES (?, ?, ?)
            ");
            $ins->execute([$coachId, $trainingDate, $markedBy]);
        } catch (PDOException $e) {
            // 1062 Duplicate entry — race 시 다른 트랜잭션이 INSERT 완료. 멱등 처리.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) return false;
            throw $e;
        }
        $newId = (int)$db->lastInsertId();
        logChange($db, 'training_attendance', $newId, 'mark_attended',
            null,
            ['coach_id' => $coachId, 'training_date' => $trainingDate],
            'coach', $markedBy);
        return true;
    } else {
        if ($existing === false) return false; // no-op
        $del = $db->prepare("
            DELETE FROM coach_training_attendance
             WHERE coach_id = ? AND training_date = ?
        ");
        $del->execute([$coachId, $trainingDate]);
        if ($del->rowCount() === 0) return false; // race
        logChange($db, 'training_attendance', (int)$existing, 'mark_absent',
            ['coach_id' => $coachId, 'training_date' => $trainingDate],
            null,
            'coach', $markedBy);
        return true;
    }
}

/**
 * 어드민 전용: 활성 팀(team_leader_id=id) 전체에 대해 직전 4회 출석 매트릭스.
 *
 * 응답:
 * [
 *   'recent_dates' => ['2026-04-30', ...4 DESC],
 *   'teams' => [
 *     [
 *       'leader_id' => 6, 'leader_name' => 'Kel',
 *       'members' => [
 *         [coach_id, coach_name, korean_name,
 *          attendance: [{date, attended}, ...4],
 *          attended_count, total_count, attendance_rate]
 *       ]
 *     ],
 *     ...
 *   ]
 * ]
 *
 * 정렬: leader_name ASC, member 본인 첫 + 나머지 coach_name ASC.
 * active=1 코치만 포함.
 */
function buildAdminAttendanceOverview(PDO $db, DateTimeImmutable $nowKst): array
{
    $totalCount = COACH_TRAINING_RECENT_COUNT;
    $recentDates = recentTrainingDates($nowKst);

    // 활성 팀장 (team_leader_id == id AND status='active')
    $stmt = $db->query("
        SELECT id, coach_name
          FROM coaches
         WHERE team_leader_id = id AND status = 'active'
         ORDER BY coach_name ASC
    ");
    $leaders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$leaders) return ['recent_dates' => $recentDates, 'teams' => []];

    $leaderIds = array_map(fn($l) => (int)$l['id'], $leaders);
    $leaderIdsPh = implode(',', array_fill(0, count($leaderIds), '?'));

    // 모든 팀 멤버 (active 코치 + 팀장 본인 포함)
    $mStmt = $db->prepare("
        SELECT id AS coach_id, coach_name, korean_name, team_leader_id
          FROM coaches
         WHERE team_leader_id IN ({$leaderIdsPh})
           AND status = 'active'
         ORDER BY team_leader_id ASC, (id = team_leader_id) DESC, coach_name ASC
    ");
    $mStmt->execute($leaderIds);
    $allMembers = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$allMembers) {
        $teams = [];
        foreach ($leaders as $l) {
            $teams[] = [
                'leader_id' => (int)$l['id'],
                'leader_name' => $l['coach_name'],
                'members' => [],
            ];
        }
        return ['recent_dates' => $recentDates, 'teams' => $teams];
    }

    $memberIds = array_map(fn($m) => (int)$m['coach_id'], $allMembers);
    $idsPh   = implode(',', array_fill(0, count($memberIds), '?'));
    $datesPh = implode(',', array_fill(0, count($recentDates), '?'));

    // 출석 row (member별 + date별)
    $att = $db->prepare("
        SELECT coach_id, training_date
          FROM coach_training_attendance
         WHERE coach_id IN ({$idsPh})
           AND training_date IN ({$datesPh})
    ");
    $att->execute(array_merge($memberIds, $recentDates));
    $attBy = []; // [coachId][date] = 1
    foreach ($att->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $attBy[(int)$r['coach_id']][$r['training_date']] = 1;
    }

    // 팀별 그룹핑
    $byLeader = [];
    foreach ($allMembers as $m) {
        $cid = (int)$m['coach_id'];
        $rows = $attBy[$cid] ?? [];
        $attended = 0;
        $attendance = [];
        foreach ($recentDates as $d) {
            $on = isset($rows[$d]) ? 1 : 0;
            $attendance[] = ['date' => $d, 'attended' => $on];
            $attended += $on;
        }
        $byLeader[(int)$m['team_leader_id']][] = [
            'coach_id'         => $cid,
            'coach_name'       => $m['coach_name'],
            'korean_name'      => $m['korean_name'],
            'attendance'       => $attendance,
            'attended_count'   => $attended,
            'total_count'      => $totalCount,
            'attendance_rate'  => $totalCount > 0 ? round($attended / $totalCount, 4) : 0.0,
        ];
    }

    $teams = [];
    foreach ($leaders as $l) {
        $lid = (int)$l['id'];
        $teams[] = [
            'leader_id'   => $lid,
            'leader_name' => $l['coach_name'],
            'members'     => $byLeader[$lid] ?? [],
        ];
    }
    return ['recent_dates' => $recentDates, 'teams' => $teams];
}

// 라우터는 Task 7에서 추가
if (PHP_SAPI === 'cli' || defined('COACH_TRAINING_ATTENDANCE_LIB_ONLY')) return;

header('Content-Type: application/json; charset=utf-8');
$user = requireAnyAuth();
$db   = getDB();
$action = $_GET['action'] ?? '';

if ($action === 'admin_overview') {
    if ($user['role'] !== 'admin') jsonError('관리자 권한이 필요합니다', 403);
    $nowKst = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
    jsonSuccess(buildAdminAttendanceOverview($db, $nowKst));
}

// 이하 액션은 코치-팀장 전용
if ($user['role'] !== 'coach') jsonError('코치 로그인이 필요합니다', 401);
$leaderId = (int)$user['id'];
assertIsLeader($db, $leaderId);

switch ($action) {
    case 'history': {
        $coachId = (int)($_GET['coach_id'] ?? 0);
        if (!$coachId) jsonError('coach_id가 필요합니다');
        assertCoachIsMyMember($db, $leaderId, $coachId);

        $nowKst = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
        jsonSuccess(listAttendanceHistory($db, $coachId, $nowKst));
    }

    case 'toggle': {
        $input = getJsonInput();
        $coachId      = (int)($input['coach_id'] ?? 0);
        $trainingDate = (string)($input['training_date'] ?? '');
        $attended     = !empty($input['attended']);
        if (!$coachId) jsonError('coach_id가 필요합니다');

        assertCoachIsMyMember($db, $leaderId, $coachId);

        try {
            $changed = toggleAttendance($db, $coachId, $trainingDate, $attended, $leaderId);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        jsonSuccess(['changed' => $changed, 'attended' => $attended ? 1 : 0]);
    }

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
