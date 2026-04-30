<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

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

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
