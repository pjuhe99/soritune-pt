<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * 데이터 있는 cohort 월 목록 (status IN '진행중','매칭완료')
 *
 * @param int|null $coachId  null이면 전체 (admin), 정수면 해당 코치만
 * @return string[]  ['2026-04', '2026-05', ...]
 */
function kakaoCheckCohorts(PDO $db, ?int $coachId): array
{
    $where = ["o.status IN ('진행중', '매칭완료')"];
    $params = [];
    if ($coachId !== null) {
        $where[] = "o.coach_id = ?";
        $params[] = $coachId;
    }
    $sql = "
        SELECT DISTINCT COALESCE(cohort_month, DATE_FORMAT(start_date, '%Y-%m')) AS cohort
        FROM orders o
        WHERE " . implode(' AND ', $where) . "
        ORDER BY cohort DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * 특정 cohort의 order 리스트 + product distinct 목록.
 *
 * @param array{cohort:string, coach_id:?int, include_joined:bool, product:?string} $opts
 * @return array{orders:array, products:string[]}
 */
function kakaoCheckList(PDO $db, array $opts): array
{
    $cohort = $opts['cohort'];
    $coachId = $opts['coach_id'] ?? null;
    // include_processed (신) 우선, 없으면 include_joined (구) fallback
    $includeProcessed = array_key_exists('include_processed', $opts)
        ? !empty($opts['include_processed'])
        : !empty($opts['include_joined']);
    $product = $opts['product'] ?? null;

    // ---- products 리스트 (product 필터 무시, scope만 적용) ----
    $pWhere = [
        "o.status IN ('진행중', '매칭완료')",
        "COALESCE(o.cohort_month, DATE_FORMAT(o.start_date, '%Y-%m')) = ?",
    ];
    $pParams = [$cohort];
    if ($coachId !== null) {
        $pWhere[] = "o.coach_id = ?";
        $pParams[] = $coachId;
    }
    if (!$includeProcessed) {
        $pWhere[] = "o.kakao_room_joined = 0 AND o.coupon_issued = 0 AND o.special_case = 0";
    }
    $pSql = "
        SELECT DISTINCT o.product_name
        FROM orders o
        WHERE " . implode(' AND ', $pWhere) . "
          AND o.product_name IS NOT NULL AND o.product_name != ''
        ORDER BY o.product_name
    ";
    $stmt = $db->prepare($pSql);
    $stmt->execute($pParams);
    $products = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // ---- orders 리스트 ----
    $oWhere = $pWhere;
    $oParams = $pParams;
    if ($product !== null && $product !== '') {
        $oWhere[] = "o.product_name = ?";
        $oParams[] = $product;
    }
    $oSql = "
        SELECT
          o.id AS order_id,
          o.member_id,
          m.name,
          m.phone,
          m.email,
          o.product_name,
          o.start_date,
          o.end_date,
          o.status,
          CASE WHEN o.status = '매칭완료' THEN '진행예정' ELSE o.status END AS display_status,
          o.cohort_month AS cohort_month_override,
          COALESCE(o.cohort_month, DATE_FORMAT(o.start_date, '%Y-%m')) AS effective_cohort,
          o.kakao_room_joined,
          o.kakao_room_joined_at,
          o.coupon_issued,
          o.special_case,
          o.special_case_note,
          o.coach_id,
          (SELECT p.coach_id
             FROM orders p
            WHERE p.member_id = o.member_id
              AND p.id <> o.id
              AND p.product_name = o.product_name
              AND p.status NOT IN ('환불','중단')
              AND COALESCE(p.cohort_month, DATE_FORMAT(p.start_date, '%Y-%m'))
                   < COALESCE(o.cohort_month, DATE_FORMAT(o.start_date, '%Y-%m'))
            ORDER BY COALESCE(p.cohort_month, DATE_FORMAT(p.start_date, '%Y-%m')) DESC, p.id DESC
            LIMIT 1) AS prev_coach_id,
          c.coach_name
        FROM orders o
        JOIN members m ON m.id = o.member_id
        LEFT JOIN coaches c ON c.id = o.coach_id
        WHERE " . implode(' AND ', $oWhere) . "
        ORDER BY o.start_date ASC, m.name ASC
    ";
    $stmt = $db->prepare($oSql);
    $stmt->execute($oParams);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['orders' => $orders, 'products' => $products];
}

/**
 * order의 kakao_room_joined 토글. 권한 체크는 caller 책임.
 *
 * @param bool   $joined     true=ON / false=OFF
 * @param string $actorType  'admin' | 'coach'
 * @param int    $actorId    user.id
 * @return bool  실제로 값이 바뀌었는지 (false면 no-op)
 */
function kakaoCheckToggle(PDO $db, int $orderId, bool $joined, string $actorType, int $actorId): bool
{
    $current = $db->prepare("SELECT kakao_room_joined FROM orders WHERE id = ?");
    $current->execute([$orderId]);
    $row = $current->fetch();
    if (!$row) {
        return false;
    }
    $oldVal = (int)$row['kakao_room_joined'];
    $newVal = $joined ? 1 : 0;
    if ($oldVal === $newVal) {
        return false; // idempotent no-op
    }
    if ($joined) {
        $db->prepare("
            UPDATE orders
               SET kakao_room_joined = 1,
                   kakao_room_joined_at = NOW(),
                   kakao_room_joined_by = ?
             WHERE id = ?
        ")->execute([$actorId, $orderId]);
        $action = 'kakao_room_join';
    } else {
        $db->prepare("
            UPDATE orders
               SET kakao_room_joined = 0,
                   kakao_room_joined_at = NULL,
                   kakao_room_joined_by = NULL
             WHERE id = ?
        ")->execute([$orderId]);
        $action = 'kakao_room_unjoin';
    }
    logChange($db, 'order', $orderId, $action,
        ['kakao_room_joined' => $oldVal],
        ['kakao_room_joined' => $newVal],
        $actorType, $actorId);
    return true;
}

/**
 * 통합 flag 토글. flag별로 컬럼 매핑.
 *
 * @param string      $flag       'kakao' | 'coupon' | 'special'
 * @param bool        $value      true=ON / false=OFF
 * @param string|null $note       flag='special' && value=true 일 때만 사용. 빈 문자열은 NULL로 저장.
 * @param string      $actorType  'admin' | 'coach'
 * @param int         $actorId    user.id
 * @return bool  실제로 값이 바뀌었는지 (false면 no-op)
 */
function kakaoCheckToggleFlag(PDO $db, int $orderId, string $flag, bool $value, ?string $note, string $actorType, int $actorId): bool
{
    if ($flag === 'kakao') {
        return kakaoCheckToggle($db, $orderId, $value, $actorType, $actorId);
    }
    if ($flag !== 'coupon' && $flag !== 'special') {
        throw new InvalidArgumentException("flag must be 'kakao' | 'coupon' | 'special', got '{$flag}'");
    }

    $colVal = $flag === 'coupon' ? 'coupon_issued'    : 'special_case';
    $colAt  = $flag === 'coupon' ? 'coupon_issued_at' : 'special_case_at';
    $colBy  = $flag === 'coupon' ? 'coupon_issued_by' : 'special_case_by';
    $hasNote = ($flag === 'special');
    $colNote = 'special_case_note';

    $selCols = $hasNote ? "{$colVal}, {$colNote}" : $colVal;
    $current = $db->prepare("SELECT {$selCols} FROM orders WHERE id = ?");
    $current->execute([$orderId]);
    $row = $current->fetch();
    if (!$row) return false;

    $oldVal  = (int)$row[$colVal];
    $oldNote = $hasNote ? ($row[$colNote] ?? null) : null;
    $newVal  = $value ? 1 : 0;
    $newNote = null;
    if ($hasNote && $value) {
        $note = $note !== null ? trim($note) : '';
        $newNote = $note === '' ? null : $note;
    }

    // no-op: 값 동일 + (special의 경우) note도 동일
    if ($oldVal === $newVal && (!$hasNote || $oldNote === $newNote)) {
        return false;
    }

    if ($value) {
        if ($hasNote) {
            $db->prepare("UPDATE orders SET {$colVal}=1, {$colAt}=NOW(), {$colBy}=?, {$colNote}=? WHERE id=?")
               ->execute([$actorId, $newNote, $orderId]);
        } else {
            $db->prepare("UPDATE orders SET {$colVal}=1, {$colAt}=NOW(), {$colBy}=? WHERE id=?")
               ->execute([$actorId, $orderId]);
        }
        $action = "{$flag}_" . ($flag === 'coupon' ? 'issued_set' : 'case_set');
    } else {
        if ($hasNote) {
            $db->prepare("UPDATE orders SET {$colVal}=0, {$colAt}=NULL, {$colBy}=NULL, {$colNote}=NULL WHERE id=?")
               ->execute([$orderId]);
        } else {
            $db->prepare("UPDATE orders SET {$colVal}=0, {$colAt}=NULL, {$colBy}=NULL WHERE id=?")
               ->execute([$orderId]);
        }
        $action = "{$flag}_" . ($flag === 'coupon' ? 'issued_unset' : 'case_unset');
    }

    $oldLog = [$colVal => $oldVal];
    $newLog = [$colVal => $newVal];
    if ($hasNote) {
        $oldLog[$colNote] = $oldNote;
        $newLog[$colNote] = $newNote;
    }
    logChange($db, 'order', $orderId, $action, $oldLog, $newLog, $actorType, $actorId);
    return true;
}

/**
 * 여러 order의 cohort_month를 일괄 설정 (또는 NULL 복원). admin only.
 * 트랜잭션으로 묶어 모두 성공 또는 모두 실패.
 *
 * @param int[]       $orderIds
 * @param string|null $cohortMonth  'YYYY-MM' 또는 null(override 해제)
 * @param int         $adminId
 * @return int  실제 UPDATE된 행 수 (변경 없는 행은 제외)
 */
function kakaoCheckSetCohort(PDO $db, array $orderIds, ?string $cohortMonth, int $adminId): int
{
    if (empty($orderIds)) return 0;
    if ($cohortMonth !== null && !preg_match('/^\d{4}-\d{2}$/', $cohortMonth)) {
        throw new InvalidArgumentException('cohort_month 형식 오류 (YYYY-MM)');
    }

    $ownTxn = !$db->inTransaction();
    if ($ownTxn) $db->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sel = $db->prepare("SELECT id, cohort_month FROM orders WHERE id IN ({$placeholders})");
        $sel->execute($orderIds);
        $existing = $sel->fetchAll(PDO::FETCH_KEY_PAIR);

        $updated = 0;
        $upd = $db->prepare("UPDATE orders SET cohort_month = ? WHERE id = ?");

        foreach ($orderIds as $oid) {
            if (!array_key_exists($oid, $existing)) continue;
            $oldVal = $existing[$oid]; // string 'YYYY-MM' 또는 null
            if ($oldVal === $cohortMonth) continue; // no-op
            $upd->execute([$cohortMonth, $oid]);
            logChange($db, 'order', (int)$oid, 'cohort_month_set',
                ['cohort_month' => $oldVal],
                ['cohort_month' => $cohortMonth],
                'admin', $adminId);
            $updated++;
        }

        if ($ownTxn) $db->commit();
        return $updated;
    } catch (Throwable $e) {
        if ($ownTxn && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// --- 라우터 진입점 (lib only 모드에서는 스킵) ---
if (PHP_SAPI === 'cli' || defined('KAKAO_CHECK_LIB_ONLY')) return;

header('Content-Type: application/json; charset=utf-8');
$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'cohorts':
        $coachId = null;
        if ($user['role'] === 'coach') {
            $coachId = (int)$user['id'];
        } elseif ($user['role'] === 'admin' && !empty($_GET['coach_id'])) {
            $coachId = (int)$_GET['coach_id'];
        }
        jsonSuccess(['cohorts' => kakaoCheckCohorts($db, $coachId)]);

    case 'list':
        $cohort = trim($_GET['cohort'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $cohort)) {
            jsonError('cohort 파라미터가 필요합니다 (YYYY-MM)');
        }
        $coachId = null;
        if ($user['role'] === 'coach') {
            $coachId = (int)$user['id'];
        } elseif ($user['role'] === 'admin' && !empty($_GET['coach_id'])) {
            $coachId = (int)$_GET['coach_id'];
        }
        // include_processed (신) 우선, include_joined (구) fallback
        $rawProcessed = $_GET['include_processed'] ?? $_GET['include_joined'] ?? '';
        $includeProcessed = $rawProcessed !== '' && $rawProcessed !== '0';
        $result = kakaoCheckList($db, [
            'cohort' => $cohort,
            'coach_id' => $coachId,
            'include_processed' => $includeProcessed,
            'product' => trim($_GET['product'] ?? '') ?: null,
        ]);
        jsonSuccess($result);

    case 'toggle_join':
        $input = getJsonInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $joined = !empty($input['joined']);
        if (!$orderId) jsonError('order_id가 필요합니다');

        // 존재 확인 (양 role 공통)
        $stmt = $db->prepare("SELECT coach_id FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        if (!$row) jsonError('order를 찾을 수 없습니다', 404);

        // 코치 권한: 본인 order만 (admin은 통과)
        if ($user['role'] === 'coach' && (int)$row['coach_id'] !== (int)$user['id']) {
            jsonError('권한이 없습니다', 403);
        }

        kakaoCheckToggle($db, $orderId, $joined, $user['role'], (int)$user['id']);
        jsonSuccess(['joined' => $joined ? 1 : 0]);

    case 'toggle_flag':
        $input = getJsonInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $flag    = (string)($input['flag'] ?? '');
        $value   = !empty($input['value']);
        $note    = array_key_exists('note', $input) ? (string)$input['note'] : null;
        if (!$orderId) jsonError('order_id가 필요합니다');
        if (!in_array($flag, ['kakao', 'coupon', 'special'], true)) {
            jsonError("flag는 'kakao'|'coupon'|'special' 중 하나여야 합니다");
        }

        // 권한: order 존재 확인 + coach는 자기 order만
        $stmt = $db->prepare("SELECT coach_id FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        if (!$row) jsonError('order를 찾을 수 없습니다', 404);
        if ($user['role'] === 'coach' && (int)$row['coach_id'] !== (int)$user['id']) {
            jsonError('권한이 없습니다', 403);
        }

        try {
            $changed = kakaoCheckToggleFlag($db, $orderId, $flag, $value, $note, $user['role'], (int)$user['id']);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        jsonSuccess(['flag' => $flag, 'value' => $value ? 1 : 0, 'changed' => $changed]);

    case 'set_cohort':
        if ($user['role'] !== 'admin') jsonError('관리자만 가능합니다', 403);

        $input = getJsonInput();
        $orderIds = $input['order_ids'] ?? [];
        $cohortMonth = $input['cohort_month'] ?? null;

        if (!is_array($orderIds) || empty($orderIds)) jsonError('order_ids가 필요합니다');
        $orderIds = array_map('intval', $orderIds);

        if ($cohortMonth !== null && !preg_match('/^\d{4}-\d{2}$/', $cohortMonth)) {
            jsonError('cohort_month 형식 오류 (YYYY-MM)');
        }

        try {
            $updated = kakaoCheckSetCohort($db, $orderIds, $cohortMonth, (int)$user['id']);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        jsonSuccess(['updated' => $updated]);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
