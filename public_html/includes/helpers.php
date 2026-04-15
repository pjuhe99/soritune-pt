<?php
/**
 * Shared helper functions for PT Management System
 */

declare(strict_types=1);

/**
 * Output a successful JSON response and exit.
 */
function jsonSuccess(array $data, string $message = ''): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Output an error JSON response with HTTP status code and exit.
 */
function jsonError(string $message, int $httpCode = 400): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Read and decode JSON from php://input.
 * Returns empty array on failure.
 */
function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Normalize a phone number to plain digits.
 * - Strips hyphens and spaces
 * - Converts +82 / 82 country code to 010 prefix
 * - Ensures Korean mobile numbers start with 010
 */
function normalizePhone(?string $phone): ?string
{
    if ($phone === null || $phone === '') {
        return null;
    }

    // Strip all non-digit characters except leading +
    $normalized = preg_replace('/[^0-9+]/', '', $phone);

    // Remove leading +
    $normalized = ltrim($normalized, '+');

    // Handle 82 country code (Korea): 821012345678 → 01012345678
    if (str_starts_with($normalized, '82') && strlen($normalized) >= 12) {
        $normalized = '0' . substr($normalized, 2);
    }

    // Strip hyphens and spaces from the original again just in case
    // (already done above, but kept for clarity)

    return $normalized !== '' ? $normalized : null;
}

/**
 * Returns a SQL subquery string that computes the display_status for a member
 * based on their orders. Priority order (via FIELD):
 *   진행중 → 매칭완료 (→ display: 진행예정) → 매칭대기 → 연기 → 중단 → 환불 → 종료
 * Default when no orders: '매칭대기'
 *
 * @param string $memberIdExpr  SQL expression that resolves to the member id (e.g. 'm.id')
 * @return string  SQL subquery (without surrounding parentheses for use in SELECT)
 */
function memberStatusSQL(string $memberIdExpr = 'm.id'): string
{
    return "COALESCE(
        (
            SELECT
                CASE o2.status
                    WHEN '매칭완료' THEN '진행예정'
                    ELSE o2.status
                END
            FROM orders o2
            WHERE o2.member_id = {$memberIdExpr}
            ORDER BY FIELD(o2.status,
                '진행중',
                '매칭완료',
                '매칭대기',
                '연기',
                '중단',
                '환불',
                '종료'
            ) ASC
            LIMIT 1
        ),
        '매칭대기'
    )";
}

/**
 * Compute and return the display status for a single member.
 */
function getMemberDisplayStatus(PDO $db, int $memberId): string
{
    $statusExpr = memberStatusSQL(':member_id');
    $sql = "SELECT {$statusExpr} AS display_status FROM DUAL";

    // Replace :member_id placeholder inside the subquery with a literal value
    // since we can't bind inside a string expression; use a wrapper query instead.
    $sql = "SELECT COALESCE(
        (
            SELECT
                CASE o.status
                    WHEN '매칭완료' THEN '진행예정'
                    ELSE o.status
                END
            FROM orders o
            WHERE o.member_id = :member_id
            ORDER BY FIELD(o.status,
                '진행중',
                '매칭완료',
                '매칭대기',
                '연기',
                '중단',
                '환불',
                '종료'
            ) ASC
            LIMIT 1
        ),
        '매칭대기'
    ) AS display_status";

    $stmt = $db->prepare($sql);
    $stmt->execute([':member_id' => $memberId]);
    $row = $stmt->fetch();

    return $row['display_status'] ?? '매칭대기';
}

/**
 * Insert a record into change_logs.
 *
 * @param PDO    $db
 * @param string $targetType  Table/entity name (e.g. 'members', 'orders')
 * @param int    $targetId    Primary key of the target record
 * @param string $action      Action label: create|update|delete|status_change|etc
 * @param mixed  $oldValue    Previous value (will be JSON-encoded); null if new record
 * @param mixed  $newValue    New value (will be JSON-encoded); null if deleted
 * @param string $actorType   'admin' or 'coach'
 * @param int    $actorId     ID of the acting user
 */
function logChange(
    PDO    $db,
    string $targetType,
    int    $targetId,
    string $action,
    mixed  $oldValue,
    mixed  $newValue,
    string $actorType,
    int    $actorId
): void {
    $sql = "INSERT INTO change_logs
        (target_type, target_id, action, old_value, new_value, actor_type, actor_id)
        VALUES
        (:target_type, :target_id, :action, :old_value, :new_value, :actor_type, :actor_id)";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':target_type' => $targetType,
        ':target_id'   => $targetId,
        ':action'      => $action,
        ':old_value'   => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
        ':new_value'   => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
        ':actor_type'  => $actorType,
        ':actor_id'    => $actorId,
    ]);
}
