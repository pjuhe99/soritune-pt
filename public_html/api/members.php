<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $search = trim($_GET['search'] ?? '');
        $statusFilter = $_GET['status'] ?? '';
        $coachFilter = $_GET['coach_id'] ?? '';
        $productFilter = trim($_GET['product'] ?? '');

        $statusSQL = memberStatusSQL();

        $where = ["m.merged_into IS NULL"];
        $params = [];

        // Coach role: 현재 담당 중인 회원만 (진행중/매칭완료 status). 과거 종료된 PT는 제외.
        if ($user['role'] === 'coach') {
            $where[] = "EXISTS (SELECT 1 FROM orders o WHERE o.member_id = m.id AND o.coach_id = ? AND o.status IN ('진행중', '매칭완료'))";
            $params[] = $user['id'];
        }

        if ($search !== '') {
            $where[] = "(m.name LIKE ? OR m.phone LIKE ? OR m.email LIKE ? OR m.soritune_id LIKE ?)";
            $like = "%{$search}%";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        if ($coachFilter !== '') {
            $where[] = "(
                EXISTS (SELECT 1 FROM orders oc2 WHERE oc2.member_id = m.id AND oc2.status = '진행중' AND oc2.coach_id = ?)
                OR (
                    NOT EXISTS (SELECT 1 FROM orders oc3 WHERE oc3.member_id = m.id AND oc3.status = '진행중' AND oc3.coach_id IS NOT NULL)
                    AND (SELECT oc4.coach_id FROM orders oc4 WHERE oc4.member_id = m.id AND oc4.coach_id IS NOT NULL ORDER BY oc4.start_date DESC, oc4.id DESC LIMIT 1) = ?
                )
            )";
            $params[] = (int)$coachFilter;
            $params[] = (int)$coachFilter;
        }

        if ($productFilter !== '') {
            $where[] = "EXISTS (SELECT 1 FROM orders op WHERE op.member_id = m.id AND op.status = '진행중' AND op.product_name = ?)";
            $params[] = $productFilter;
        }

        $havingClauses = [];
        if ($statusFilter !== '') {
            $statusList = array_values(array_filter(array_map('trim', explode(',', $statusFilter))));
            if (!empty($statusList)) {
                $placeholders = implode(',', array_fill(0, count($statusList), '?'));
                $havingClauses[] = "display_status IN ({$placeholders})";
                foreach ($statusList as $s) $params[] = $s;
            }
        }

        $whereSQL = implode(' AND ', $where);
        $havingSQL = $havingClauses ? 'HAVING ' . implode(' AND ', $havingClauses) : '';

        $sql = "
            SELECT m.*,
              {$statusSQL} AS display_status,
              COALESCE(
                (SELECT GROUP_CONCAT(DISTINCT c.coach_name ORDER BY c.coach_name SEPARATOR ', ')
                 FROM orders oc JOIN coaches c ON c.id = oc.coach_id
                 WHERE oc.member_id = m.id AND oc.status = '진행중' AND oc.coach_id IS NOT NULL),
                (SELECT c.coach_name
                 FROM orders oc JOIN coaches c ON c.id = oc.coach_id
                 WHERE oc.member_id = m.id AND oc.coach_id IS NOT NULL
                 ORDER BY oc.start_date DESC, oc.id DESC LIMIT 1)
              ) AS current_coaches,
              (SELECT GROUP_CONCAT(DISTINCT oa.product_name ORDER BY oa.product_name SEPARATOR ', ')
               FROM orders oa
               WHERE oa.member_id = m.id AND oa.status = '진행중') AS current_products,
              (SELECT COUNT(*) FROM orders o WHERE o.member_id = m.id) AS order_count
            FROM members m
            WHERE {$whereSQL}
            {$havingSQL}
            ORDER BY m.updated_at DESC
            LIMIT 100
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonSuccess(['members' => $stmt->fetchAll()]);

    case 'active_products':
        $where = ["o.status = '진행중'"];
        $params = [];
        if ($user['role'] === 'coach') {
            $where[] = "EXISTS (SELECT 1 FROM orders o2 WHERE o2.member_id = o.member_id AND o2.coach_id = ? AND o2.status IN ('진행중', '매칭완료'))";
            $params[] = $user['id'];
        }
        $whereSQL = implode(' AND ', $where);
        $stmt = $db->prepare("SELECT DISTINCT o.product_name FROM orders o WHERE {$whereSQL} ORDER BY o.product_name");
        $stmt->execute($params);
        jsonSuccess(['products' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');

        // Coach role: verify access — 현재 담당(진행중/매칭완료) order가 있어야 함
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND coach_id = ? AND status IN ('진행중', '매칭완료') LIMIT 1");
            $stmt->execute([$id, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        $statusSQL = memberStatusSQL();
        $stmt = $db->prepare("
            SELECT m.*,
              {$statusSQL} AS display_status
            FROM members m WHERE m.id = ?
        ");
        $stmt->execute([$id]);
        $member = $stmt->fetch();
        if (!$member) jsonError('회원을 찾을 수 없습니다', 404);

        // Current coaches: coaches of 진행중 orders (distinct), else latest order's coach
        $stmt = $db->prepare("
            SELECT DISTINCT c.id, c.coach_name
            FROM orders o JOIN coaches c ON c.id = o.coach_id
            WHERE o.member_id = ? AND o.status = '진행중' AND o.coach_id IS NOT NULL
            ORDER BY c.coach_name
        ");
        $stmt->execute([$id]);
        $coaches = $stmt->fetchAll();
        if (empty($coaches)) {
            $stmt = $db->prepare("
                SELECT c.id, c.coach_name
                FROM orders o JOIN coaches c ON c.id = o.coach_id
                WHERE o.member_id = ? AND o.coach_id IS NOT NULL
                ORDER BY o.start_date DESC, o.id DESC LIMIT 1
            ");
            $stmt->execute([$id]);
            $coaches = $stmt->fetchAll();
        }
        $member['current_coaches'] = $coaches;

        // Linked accounts
        $stmt = $db->prepare("SELECT * FROM member_accounts WHERE member_id = ? ORDER BY is_primary DESC");
        $stmt->execute([$id]);
        $member['accounts'] = $stmt->fetchAll();

        jsonSuccess(['member' => $member]);

    case 'create':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $input = getJsonInput();
        $sorituneId = trim($input['soritune_id'] ?? '');
        $name = trim($input['name'] ?? '');
        if (!$sorituneId) jsonError('Soritune ID를 입력하세요');
        if (!$name) jsonError('이름을 입력하세요');

        $phone = normalizePhone($input['phone'] ?? null);
        $email = trim($input['email'] ?? '') ?: null;

        try {
            $stmt = $db->prepare("INSERT INTO members (soritune_id, name, phone, email, memo) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$sorituneId, $name, $phone, $email, $input['memo'] ?? null]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) jsonError('이미 사용 중인 Soritune ID입니다');
            throw $e;
        }
        $memberId = (int)$db->lastInsertId();

        jsonSuccess(['id' => $memberId], '회원이 등록되었습니다');

    case 'update':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $input = getJsonInput();

        $fields = [];
        $params = [];
        if (array_key_exists('soritune_id', $input)) {
            $sid = trim($input['soritune_id']);
            if (!$sid) jsonError('Soritune ID는 비울 수 없습니다');
            $fields[] = 'soritune_id = ?'; $params[] = $sid;
        }
        if (array_key_exists('name', $input)) {
            $nm = trim($input['name']);
            if (!$nm) jsonError('이름은 비울 수 없습니다');
            $fields[] = 'name = ?'; $params[] = $nm;
        }
        if (array_key_exists('phone', $input)) { $fields[] = 'phone = ?'; $params[] = normalizePhone($input['phone']); }
        if (array_key_exists('email', $input)) { $fields[] = 'email = ?'; $params[] = trim($input['email']) ?: null; }
        if (array_key_exists('memo', $input)) { $fields[] = 'memo = ?'; $params[] = $input['memo']; }

        if (empty($fields)) jsonError('변경할 항목이 없습니다');
        $params[] = $id;

        // Log change
        $stmt = $db->prepare("SELECT soritune_id, name, phone, email, memo FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $oldData = $stmt->fetch();

        try {
            $db->prepare("UPDATE members SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) jsonError('이미 사용 중인 Soritune ID입니다');
            throw $e;
        }

        $stmt = $db->prepare("SELECT soritune_id, name, phone, email, memo FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $newData = $stmt->fetch();

        logChange($db, 'member', $id, 'info_update', $oldData, $newData, $user['role'], $user['id']);

        jsonSuccess([], '회원 정보가 수정되었습니다');

    case 'delete':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $db->prepare("DELETE FROM members WHERE id = ?")->execute([$id]);
        jsonSuccess([], '회원이 삭제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
