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

// 라우터는 Task 7에서 추가
if (PHP_SAPI === 'cli' || defined('COACH_TRAINING_ATTENDANCE_LIB_ONLY')) return;

header('Content-Type: application/json; charset=utf-8');
$user = requireCoach();
$db   = getDB();
$leaderId = (int)$user['id'];
$action = $_GET['action'] ?? '';

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
