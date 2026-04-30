<?php
declare(strict_types=1);

/** Accepts https://open.kakao.com/o/<slug> and /me/<slug>; slug chars: [A-Za-z0-9_]+ */
const KAKAO_ROOM_URL_REGEX = '/^https:\/\/open\.kakao\.com\/(o|me)\/[A-Za-z0-9_]+$/';

function normalizeKakaoRoomUrl(?string $raw): ?string
{
    if ($raw === null) return null;
    $trimmed = trim($raw);
    if ($trimmed === '') return null;
    if (!preg_match(KAKAO_ROOM_URL_REGEX, $trimmed)) {
        throw new InvalidArgumentException(
            '카톡방 링크 형식이 올바르지 않습니다 (https://open.kakao.com/o/... 또는 /me/...)'
        );
    }
    return $trimmed;
}

function validateTeamLeaderId(PDO $db, int $coachId, ?int $leaderId): void
{
    if ($leaderId === null) return;
    if ($leaderId === $coachId) return;
    $stmt = $db->prepare("
        SELECT id FROM coaches
        WHERE id = ? AND team_leader_id = id AND status = 'active'
    ");
    $stmt->execute([$leaderId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('지정한 팀장이 유효하지 않습니다 (active 팀장만 선택 가능)');
    }
}

function countTeamMembers(PDO $db, int $leaderId): int
{
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM coaches
        WHERE team_leader_id = ? AND id != ?
    ");
    $stmt->execute([$leaderId, $leaderId]);
    return (int)$stmt->fetchColumn();
}

function assertCanModifyLeader(PDO $db, int $coachId, string $action): void
{
    $allowed = ['inactive', 'unset_leader', 'delete'];
    if (!in_array($action, $allowed, true)) {
        throw new InvalidArgumentException("Unknown action: {$action}");
    }
    $count = countTeamMembers($db, $coachId);
    if ($count > 0) {
        throw new RuntimeException(
            "이 팀에 팀원 {$count}명이 있습니다. 먼저 다른 팀장을 지정하거나 팀원을 미배정 처리하세요"
        );
    }
}
