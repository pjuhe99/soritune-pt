<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coach_team_guard.php';

/**
 * 면담 본문 검증.
 * @throws InvalidArgumentException
 */
function validateMeetingNoteBody(string $notes): string
{
    $trimmed = trim($notes);
    if ($trimmed === '') {
        throw new InvalidArgumentException('notes는 빈 문자열일 수 없습니다');
    }
    if (mb_strlen($trimmed) > 50000) {
        throw new InvalidArgumentException('notes는 50,000자를 초과할 수 없습니다');
    }
    return $trimmed;
}

/**
 * meeting_date 검증. YYYY-MM-DD + checkdate.
 * @throws InvalidArgumentException
 */
function validateMeetingDate(string $date): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        throw new InvalidArgumentException('meeting_date 형식 오류 (YYYY-MM-DD)');
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new InvalidArgumentException('meeting_date 유효하지 않은 일자');
    }
    return $date;
}

/**
 * 한 코치 대상의 면담 list. meeting_date DESC, id DESC.
 * 권한 검증은 caller 책임.
 */
function listMeetingNotes(PDO $db, int $coachId): array
{
    $stmt = $db->prepare("
        SELECT n.id, n.meeting_date, n.notes,
               n.created_by, c.coach_name AS created_by_name,
               n.created_at, n.updated_at
          FROM coach_meeting_notes n
          JOIN coaches c ON c.id = n.created_by
         WHERE n.coach_id = ?
         ORDER BY n.meeting_date DESC, n.id DESC
    ");
    $stmt->execute([$coachId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 면담 INSERT. 권한 검증은 caller 책임.
 * @return int 신규 row id
 * @throws InvalidArgumentException
 */
function createMeetingNote(
    PDO $db, int $coachId, int $createdBy, string $meetingDate, string $notes
): int {
    $meetingDate = validateMeetingDate($meetingDate);
    $notes       = validateMeetingNoteBody($notes);

    $stmt = $db->prepare("
        INSERT INTO coach_meeting_notes (coach_id, meeting_date, notes, created_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$coachId, $meetingDate, $notes, $createdBy]);
    $id = (int)$db->lastInsertId();

    logChange($db, 'meeting_note', $id, 'create',
        null,
        ['coach_id' => $coachId, 'meeting_date' => $meetingDate],
        'coach', $createdBy);

    return $id;
}

/**
 * 면담 UPDATE — 작성자 본인만 가능 (WHERE created_by=?로 race-free).
 * @return bool affected_rows > 0
 * @throws InvalidArgumentException
 */
function updateMeetingNote(
    PDO $db, int $id, int $createdBy, string $meetingDate, string $notes
): bool {
    $meetingDate = validateMeetingDate($meetingDate);
    $notes       = validateMeetingNoteBody($notes);

    $stmt = $db->prepare("
        UPDATE coach_meeting_notes
           SET meeting_date = ?, notes = ?
         WHERE id = ? AND created_by = ?
    ");
    $stmt->execute([$meetingDate, $notes, $id, $createdBy]);
    $affected = $stmt->rowCount() > 0;

    if ($affected) {
        logChange($db, 'meeting_note', $id, 'update',
            null,
            ['meeting_date' => $meetingDate],
            'coach', $createdBy);
    }
    return $affected;
}

/**
 * 면담 DELETE — 작성자 본인만 가능.
 * @return bool affected_rows > 0
 */
function deleteMeetingNote(PDO $db, int $id, int $createdBy): bool
{
    $stmt = $db->prepare("
        DELETE FROM coach_meeting_notes
         WHERE id = ? AND created_by = ?
    ");
    $stmt->execute([$id, $createdBy]);
    $affected = $stmt->rowCount() > 0;

    if ($affected) {
        logChange($db, 'meeting_note', $id, 'delete', null, null, 'coach', $createdBy);
    }
    return $affected;
}

// 라우터는 Task 5에서 추가
if (PHP_SAPI === 'cli' || defined('COACH_MEETING_NOTES_LIB_ONLY')) return;
