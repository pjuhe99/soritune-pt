<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * $coachId가 자기 자신을 팀장으로 가리키는지(team_leader_id == id) 검증.
 * active 코치만 팀장으로 인정 (validateTeamLeaderId와 일관).
 */
function coachIsLeader(PDO $db, int $coachId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM coaches WHERE id = ? AND team_leader_id = id AND status = 'active' LIMIT 1"
    );
    $stmt->execute([$coachId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * $targetCoachId가 $leaderId 팀장의 본인 팀 멤버(같은 team_leader_id)인지 검증.
 * 팀장과 대상 모두 active 일 때만 멤버 관계 인정.
 */
function coachIsMyMember(PDO $db, int $leaderId, int $targetCoachId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM coaches c
         JOIN coaches leader ON leader.id = ? AND leader.status = 'active'
         WHERE c.id = ? AND c.team_leader_id = ? AND c.status = 'active' LIMIT 1"
    );
    $stmt->execute([$leaderId, $targetCoachId, $leaderId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * 팀장 권한 가드. 비-팀장이면 jsonError 403 후 exit.
 */
function assertIsLeader(PDO $db, int $coachId): void
{
    if (!coachIsLeader($db, $coachId)) {
        jsonError('팀장 권한이 필요합니다', 403);
    }
}

/**
 * 본인 팀 멤버 가드. 멤버 아니면 jsonError 403 후 exit.
 */
function assertCoachIsMyMember(PDO $db, int $leaderId, int $targetCoachId): void
{
    if (!coachIsMyMember($db, $leaderId, $targetCoachId)) {
        jsonError('해당 코치에 대한 권한이 없습니다', 403);
    }
}
