<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/coach_training.php';

t_section('recentTrainingDates — 오늘이 목요일이면 오늘 포함');
$thu = new DateTimeImmutable('2026-04-30 21:00:00', new DateTimeZone('Asia/Seoul')); // Thursday
t_assert_eq(
    ['2026-04-30','2026-04-23','2026-04-16','2026-04-09'],
    recentTrainingDates($thu, 4, 4),
    '목요일 21시 → 오늘 포함 직전 4개'
);

t_section('recentTrainingDates — 금요일은 어제(목) 포함');
$fri = new DateTimeImmutable('2026-05-01 09:00:00', new DateTimeZone('Asia/Seoul')); // Friday
t_assert_eq(
    ['2026-04-30','2026-04-23','2026-04-16','2026-04-09'],
    recentTrainingDates($fri, 4, 4),
    '금요일 → 어제 목요일 첫 원소'
);

t_section('recentTrainingDates — 수요일은 지난 목요일이 첫 원소');
$wed = new DateTimeImmutable('2026-04-29 12:00:00', new DateTimeZone('Asia/Seoul')); // Wednesday
t_assert_eq(
    ['2026-04-23','2026-04-16','2026-04-09','2026-04-02'],
    recentTrainingDates($wed, 4, 4),
    '수요일 → 지난 목요일'
);

t_section('recentTrainingDates — 월/연도 경계');
$earlyJan = new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('Asia/Seoul')); // Monday
t_assert_eq(
    ['2026-01-01','2025-12-25','2025-12-18','2025-12-11'],
    recentTrainingDates($earlyJan, 4, 4),
    '연도 넘어가는 경계'
);

t_section('recentTrainingDates — DOW 파라미터(토요일=6)');
$sat = new DateTimeImmutable('2026-05-02 10:00:00', new DateTimeZone('Asia/Seoul')); // Saturday
t_assert_eq(
    ['2026-05-02','2026-04-25','2026-04-18','2026-04-11'],
    recentTrainingDates($sat, 4, 6),
    '토요일 DOW로도 작동'
);

t_section('recentTrainingDates — N=8');
t_assert_eq(8, count(recentTrainingDates($thu, 8, 4)), 'N개수 그대로');

require_once __DIR__ . '/../public_html/includes/coach_team_guard.php';

t_section('coachIsMyMember — 같은 팀이면 true');
$db = getDB();
// 시드: Kel(팀장) — Lulu/Ella/... (같은 팀)
$kelId  = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Kel'")->fetchColumn();
$luluId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Lulu'")->fetchColumn();
$nanaId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Nana'")->fetchColumn();
$hyunId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Hyun'")->fetchColumn();

t_assert_true(coachIsMyMember($db, $kelId, $luluId), 'Kel→Lulu 같은 팀');
t_assert_true(coachIsMyMember($db, $kelId, $kelId),  'Kel→Kel 자기 자신도 true');
t_assert_eq(false, coachIsMyMember($db, $kelId, $hyunId), 'Kel→Hyun(Nana팀) false');
t_assert_eq(false, coachIsMyMember($db, $kelId, 99999),   '존재하지 않는 coach_id false');

t_section('coachIsLeader — 자기 팀장이면 true');
t_assert_true(coachIsLeader($db, $kelId),  'Kel은 팀장');
t_assert_true(coachIsLeader($db, $nanaId), 'Nana는 팀장');
t_assert_eq(false, coachIsLeader($db, $luluId), 'Lulu는 팀장 아님');
t_assert_eq(false, coachIsLeader($db, 99999),   '존재하지 않는 coach_id false');

t_section('coach_team_guard — active 필터');
$kelId  = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Kel'")->fetchColumn();
$luluId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Lulu'")->fetchColumn();
$origStatus = $db->query("SELECT status FROM coaches WHERE id={$kelId}")->fetchColumn();

// Kel을 임시로 inactive 처리
$db->prepare("UPDATE coaches SET status='inactive' WHERE id=?")->execute([$kelId]);
t_assert_eq(false, coachIsLeader($db, $kelId), 'inactive Kel은 팀장 아님');
t_assert_eq(false, coachIsMyMember($db, $kelId, $luluId), 'inactive 팀장은 멤버 관계 아님');
// 복원
$db->prepare("UPDATE coaches SET status=? WHERE id=?")->execute([$origStatus, $kelId]);
t_assert_true(coachIsLeader($db, $kelId), '복원 후 다시 팀장');

const COACH_MEETING_NOTES_LIB_ONLY = true;
require_once __DIR__ . '/../public_html/api/coach_meeting_notes.php';

t_section('createMeetingNote — 정상 INSERT');
$db = getDB();
$kelId  = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Kel'")->fetchColumn();
$luluId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Lulu'")->fetchColumn();

$noteId = createMeetingNote($db, $luluId, $kelId, '2026-05-01', 'Lulu 발성 톤 좋아짐');
t_assert_true($noteId > 0, '신규 id 반환');

$row = $db->query("SELECT * FROM coach_meeting_notes WHERE id={$noteId}")->fetch(PDO::FETCH_ASSOC);
t_assert_eq($luluId, (int)$row['coach_id'], 'coach_id 일치');
t_assert_eq($kelId,  (int)$row['created_by'], 'created_by 일치');
t_assert_eq('2026-05-01', $row['meeting_date'], 'meeting_date 일치');
t_assert_eq('Lulu 발성 톤 좋아짐', $row['notes'], 'notes 일치');

t_section('createMeetingNote — 검증');
t_assert_throws(
    fn() => createMeetingNote($db, $luluId, $kelId, '2026-13-01', 'x'),
    InvalidArgumentException::class,
    '잘못된 날짜 형식 거부 (월=13)'
);
t_assert_throws(
    fn() => createMeetingNote($db, $luluId, $kelId, '2026-05-01', ''),
    InvalidArgumentException::class,
    '빈 notes 거부'
);
t_assert_throws(
    fn() => createMeetingNote($db, $luluId, $kelId, '2026-05-01', '   '),
    InvalidArgumentException::class,
    '공백 only notes 거부'
);
t_assert_throws(
    fn() => createMeetingNote($db, $luluId, $kelId, '2026-05-01', str_repeat('a', 50001)),
    InvalidArgumentException::class,
    '50001자 거부'
);

t_section('listMeetingNotes — DESC 정렬 + JOIN');
$noteId2 = createMeetingNote($db, $luluId, $kelId, '2026-05-03', '두 번째 메모');
$rows = listMeetingNotes($db, $luluId);
$ids = array_map(fn($r) => (int)$r['id'], $rows);
$pos1 = array_search($noteId,  $ids, true);
$pos2 = array_search($noteId2, $ids, true);
t_assert_true($pos1 !== false && $pos2 !== false, '두 메모 모두 list에 존재');
t_assert_true($pos2 < $pos1, '최신(2026-05-03)이 먼저');
$row2 = array_values(array_filter($rows, fn($r) => (int)$r['id'] === $noteId2))[0];
t_assert_eq('Kel', $row2['created_by_name'], 'created_by_name JOIN');

t_section('updateMeetingNote — 작성자 본인만');
t_assert_true(
    updateMeetingNote($db, $noteId, $kelId, '2026-05-01', '수정 본문'),
    'Kel이 자기가 쓴 메모 수정 → true'
);
$row = $db->query("SELECT notes FROM coach_meeting_notes WHERE id={$noteId}")->fetch();
t_assert_eq('수정 본문', $row['notes'], '수정 반영');

$nanaId = (int)$db->query("SELECT id FROM coaches WHERE coach_name='Nana'")->fetchColumn();
t_assert_eq(false,
    updateMeetingNote($db, $noteId, $nanaId, '2026-05-01', '탈취 시도'),
    'Nana가 Kel 메모 수정 → false'
);

t_section('updateMeetingNote — no-op (동일 본문) 처리');
$noopId = createMeetingNote($db, $luluId, $kelId, '2026-05-01', '동일 본문');
t_assert_true(
    updateMeetingNote($db, $noopId, $kelId, '2026-05-01', '동일 본문'),
    '같은 본문 재저장도 true (권한 OK이면 no-op도 성공)'
);
t_assert_eq(false,
    updateMeetingNote($db, $noopId, $nanaId, '2026-05-01', '동일 본문'),
    '권한 없는 작성자는 여전히 false'
);
deleteMeetingNote($db, $noopId, $kelId);

t_section('deleteMeetingNote — 작성자 본인만');
t_assert_eq(false,
    deleteMeetingNote($db, $noteId, $nanaId),
    'Nana 삭제 시도 → false'
);
t_assert_true(deleteMeetingNote($db, $noteId,  $kelId), 'Kel 본인 삭제 → true');
t_assert_true(deleteMeetingNote($db, $noteId2, $kelId), '두 번째도 삭제');

$cnt = (int)$db->query(
    "SELECT COUNT(*) FROM coach_meeting_notes WHERE id IN ({$noteId},{$noteId2})"
)->fetchColumn();
t_assert_eq(0, $cnt, '두 row 모두 삭제됨');

t_section('createMeetingNote — logChange 본문 미저장');
$noteId3 = createMeetingNote($db, $luluId, $kelId, '2026-05-04', '로그 검증 본문');
$logRow = $db->query(
    "SELECT new_value FROM change_logs
      WHERE target_type='meeting_note' AND target_id={$noteId3} AND action='create'"
)->fetch(PDO::FETCH_ASSOC);
t_assert_true($logRow !== false, 'create 로그 row 생성됨');
$payload = json_decode($logRow['new_value'], true);
t_assert_true(!isset($payload['notes']), 'logChange new_value에 본문(notes) 미저장');
t_assert_eq($luluId, $payload['coach_id'], 'logChange new_value에 coach_id 메타');
deleteMeetingNote($db, $noteId3, $kelId);
