<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * $coachId가 자기 자신을 팀장으로 가리키는지(team_leader_id == id) 검증.
 */
function coachIsLeader(PDO $db, int $coachId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM coaches WHERE id = ? AND team_leader_id = id LIMIT 1"
    );
    $stmt->execute([$coachId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * $targetCoachId가 $leaderId 팀장의 본인 팀 멤버(같은 team_leader_id)인지 검증.
 * 팀장 자신($targetCoachId == $leaderId)도 자기 팀의 멤버로 통과한다.
 */
function coachIsMyMember(PDO $db, int $leaderId, int $targetCoachId): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM coaches WHERE id = ? AND team_leader_id = ? LIMIT 1"
    );
    $stmt->execute([$targetCoachId, $leaderId]);
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
