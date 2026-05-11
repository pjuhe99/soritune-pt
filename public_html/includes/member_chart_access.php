<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * 코치가 회원 차트에 접근 가능한지 확인.
 * 기준: 그 회원이 코치의 담당 order(환불/중단 제외) 에 있어야 함.
 */
function coach_can_access_member(int $coach_id, int $member_id): bool
{
    if ($coach_id <= 0 || $member_id <= 0) return false;
    $pdo = getDb();
    $stmt = $pdo->prepare("
        SELECT 1 FROM orders
        WHERE coach_id = :coach_id
          AND member_id = :member_id
          AND status NOT IN ('환불','중단')
        LIMIT 1
    ");
    $stmt->execute([':coach_id' => $coach_id, ':member_id' => $member_id]);
    return (bool)$stmt->fetchColumn();
}

/**
 * API 가드: 코치면 자기 회원만, 관리자면 모두. 실패 시 jsonError 후 exit.
 * - 미로그인 → 401
 * - 코치이면서 본인 담당이 아닌 회원 → 404 (회원 존재 자체를 노출하지 않음)
 */
function require_member_chart_access(int $member_id): array
{
    require_once __DIR__ . '/auth.php';
    $user = getCurrentUser();
    if (!$user) {
        jsonError('로그인이 필요합니다.', 401);
    }
    if (($user['role'] ?? null) === 'admin') {
        return $user;
    }
    if (($user['role'] ?? null) === 'coach') {
        if (coach_can_access_member((int)$user['id'], $member_id)) {
            return $user;
        }
    }
    jsonError('회원을 찾을 수 없습니다.', 404);
}
