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
        'ok' => true,
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
        'ok' => false,
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
 * 엑셀 과학표기 손상 패턴 감지.
 * 예: 12자리 한국 번호 821012341234 → 엑셀이 8.21012E+11로 변환 → 다시 저장하면 "821012+11" 형태로
 * 뒤 5자리가 영구 소실. 이 패턴이면 phone 데이터는 복구 불가.
 *
 * @return bool true if 손상 시그니처 (digits+digits)
 */
function isPhoneCorrupted(?string $phone): bool
{
    if ($phone === null || $phone === '') return false;
    return (bool)preg_match('/^\s*\d+\+\d+\s*$/', $phone);
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
 * @param string $actorType   'admin', 'coach', or 'system'
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

/**
 * Recompute a single order's status by the decision tree, updating it if needed.
 * Caller MUST hold a FOR UPDATE row lock and manage the transaction —
 * this function does NOT call BEGIN/COMMIT.
 *
 * @param PDO      $db
 * @param int      $orderId
 * @param string   $today                  YYYY-MM-DD. Defaults to date('Y-m-d').
 * @param bool     $allowRevertTerminated  When true, status='종료' rows also pass the
 *                                          protection cut (used only by complete_session).
 *                                          '연기/중단/환불' are always protected regardless.
 * @return string|null                      The new status if changed; null otherwise (or if order missing).
 */
function recomputeOrderStatus(
    PDO $db,
    int $orderId,
    ?string $today = null,
    bool $allowRevertTerminated = false
): ?string {
    $today ??= date('Y-m-d');

    $stmt = $db->prepare("
        SELECT id, coach_id, status, product_type, start_date, end_date, total_sessions
          FROM orders
         WHERE id = ?
    ");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    if (in_array($row['status'], ['연기', '중단', '환불'], true)) {
        return null;
    }
    if ($row['status'] === '종료' && !$allowRevertTerminated) {
        return null;
    }

    if ($row['coach_id'] === null) {
        $newStatus = '매칭대기';
    } else {
        $terminated = false;
        if ($row['end_date'] !== null && $today > $row['end_date']) {
            $terminated = true;
        }
        if ($row['product_type'] === 'count'
            && (int)($row['total_sessions'] ?? 0) > 0
        ) {
            $usedStmt = $db->prepare("
                SELECT COUNT(*) FROM order_sessions
                 WHERE order_id = ? AND completed_at IS NOT NULL
            ");
            $usedStmt->execute([$orderId]);
            $used = (int)$usedStmt->fetchColumn();
            if ($used >= (int)$row['total_sessions']) {
                $terminated = true;
            }
        }
        if ($terminated) {
            $newStatus = '종료';
        } elseif ($row['start_date'] !== null && $today >= $row['start_date']) {
            $newStatus = '진행중';
        } else {
            $newStatus = '매칭완료';
        }
    }

    if ($newStatus === $row['status']) {
        return null;
    }

    $action = match (true) {
        $newStatus === '매칭완료' => 'auto_match_complete',
        $newStatus === '진행중'  => 'auto_in_progress',
        $newStatus === '종료'    => 'auto_terminate',
        $newStatus === '매칭대기' => 'auto_revert_to_pending',
        default                  => 'auto_status_change',
    };

    $db->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$newStatus, $orderId]);

    logChange(
        $db, 'order', $orderId, $action,
        ['status' => $row['status']],
        ['status' => $newStatus],
        'system', 0
    );

    return $newStatus;
}

/**
 * Helper for callers without an active transaction: opens BEGIN, locks the order
 * row FOR UPDATE, runs the callback (which typically calls recomputeOrderStatus),
 * then COMMITs. ROLLBACK on exception.
 *
 * @throws RuntimeException If the caller already has an active transaction.
 */
function withOrderLock(PDO $db, int $orderId, callable $fn): mixed
{
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'withOrderLock() must not be called inside an active transaction. ' .
            'Use SELECT ... FOR UPDATE + recomputeOrderStatus() directly instead.'
        );
    }
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE')->execute([$orderId]);
        $result = $fn();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
