<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coach_team_guard.php';
require_once __DIR__ . '/../includes/coach_training.php';

/**
 * 팀장의 우리 팀 overview (본인 + 팀원 명단 + 직전 4주 출석율 + 면담 카운트).
 * 권한 검증 포함: 비-팀장이면 RuntimeException.
 */
function buildTeamOverview(PDO $db, int $leaderId, DateTimeImmutable $nowKst): array
{
    if (!coachIsLeader($db, $leaderId)) {
        throw new RuntimeException('팀장 권한이 필요합니다');
    }

    $recentDates = recentTrainingDates($nowKst); // 직전 4개 DESC

    // 멤버 목록 (본인 포함)
    $stmt = $db->prepare("
        SELECT id AS coach_id, coach_name, korean_name
          FROM coaches
         WHERE team_leader_id = ?
         ORDER BY (id = ?) DESC, coach_name ASC
    ");
    $stmt->execute([$leaderId, $leaderId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$members) return ['recent_dates' => $recentDates, 'members' => []];

    $memberIds = array_map(fn($m) => (int)$m['coach_id'], $members);

    // 직전 4주 출석 카운트 (member별)
    $idsPh   = implode(',', array_fill(0, count($memberIds), '?'));
    $datesPh = implode(',', array_fill(0, count($recentDates), '?'));
    $att = $db->prepare("
        SELECT coach_id, COUNT(*) AS cnt
          FROM coach_training_attendance
         WHERE coach_id IN ({$idsPh})
           AND training_date IN ({$datesPh})
         GROUP BY coach_id
    ");
    $att->execute(array_merge($memberIds, $recentDates));
    $attMap = [];
    foreach ($att->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $attMap[(int)$r['coach_id']] = (int)$r['cnt'];
    }

    // 면담 카운트 (member별, 전체)
    $note = $db->prepare("
        SELECT coach_id, COUNT(*) AS cnt
          FROM coach_meeting_notes
         WHERE coach_id IN ({$idsPh})
         GROUP BY coach_id
    ");
    $note->execute($memberIds);
    $noteMap = [];
    foreach ($note->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $noteMap[(int)$r['coach_id']] = (int)$r['cnt'];
    }

    $total = COACH_TRAINING_RECENT_COUNT;
    foreach ($members as &$m) {
        $cid = (int)$m['coach_id'];
        $attended = $attMap[$cid] ?? 0;
        $m['is_self']             = $cid === $leaderId;
        $m['attended_count']      = $attended;
        $m['total_count']         = $total;
        $m['attendance_rate']     = $total > 0 ? round($attended / $total, 4) : 0.0;
        $m['meeting_notes_count'] = $noteMap[$cid] ?? 0;
    }
    unset($m);

    return ['recent_dates' => $recentDates, 'members' => $members];
}

// 라우터 가드: CLI(테스트) 또는 LIB_ONLY 정의 시 라우터 스킵
if (PHP_SAPI === 'cli' || defined('COACH_SELF_LIB_ONLY')) return;

header('Content-Type: application/json; charset=utf-8');

$user = requireCoach();
$coachId = (int)$user['id'];  // 세션의 id만 신뢰. URL/POST 파라미터는 일체 사용 안 함.
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_info':
        // 본인 정보
        $stmt = $db->prepare("
            SELECT id, coach_name, korean_name, kakao_room_url, team_leader_id
            FROM coaches WHERE id = ?
        ");
        $stmt->execute([$coachId]);
        $self = $stmt->fetch();
        if (!$self) jsonError('코치 정보를 찾을 수 없습니다', 404);

        $isLeader = ($self['team_leader_id'] !== null
                     && (int)$self['team_leader_id'] === (int)$self['id']);
        $team = null;
        $members = null;

        if ($self['team_leader_id'] !== null) {
            $tl = $db->prepare("SELECT id, coach_name FROM coaches WHERE id = ?");
            $tl->execute([(int)$self['team_leader_id']]);
            $leader = $tl->fetch();
            if ($leader) {
                $team = [
                    'name'        => $leader['coach_name'] . '팀',
                    'leader_name' => $leader['coach_name'],
                ];
            }
        }

        $payload = [
            'self' => [
                'coach_name'     => $self['coach_name'],
                'korean_name'    => $self['korean_name'],
                'kakao_room_url' => $self['kakao_room_url'],
            ],
            'team'      => $team,
            'is_leader' => $isLeader,
        ];

        if ($isLeader) {
            // 같은 팀 멤버 (본인 포함 — UI에서 본인 행도 같이 보여주기 위함)
            $ms = $db->prepare("
                SELECT coach_name, korean_name, kakao_room_url
                FROM coaches
                WHERE team_leader_id = ?
                ORDER BY (id = ?) DESC, coach_name ASC
            ");
            $ms->execute([$coachId, $coachId]);
            $payload['members'] = $ms->fetchAll(PDO::FETCH_ASSOC);
        }

        jsonSuccess($payload);

    case 'team_overview':
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul'));
        try {
            jsonSuccess(buildTeamOverview($db, $coachId, $now));
        } catch (RuntimeException $e) {
            jsonError($e->getMessage(), 403);
        }

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
