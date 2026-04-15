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

        $statusSQL = memberStatusSQL();

        $where = ["m.merged_into IS NULL"];
        $params = [];

        // Coach role: only show assigned members
        if ($user['role'] === 'coach') {
            $where[] = "EXISTS (SELECT 1 FROM coach_assignments ca WHERE ca.member_id = m.id AND ca.coach_id = ? AND ca.released_at IS NULL)";
            $params[] = $user['id'];
        }

        if ($search !== '') {
            $where[] = "(m.name LIKE ? OR m.phone LIKE ? OR m.email LIKE ?)";
            $like = "%{$search}%";
            $params = array_merge($params, [$like, $like, $like]);
        }

        $havingClauses = [];
        if ($statusFilter !== '') {
            $havingClauses[] = "display_status = ?";
            $params[] = $statusFilter;
        }

        if ($coachFilter !== '') {
            $where[] = "EXISTS (SELECT 1 FROM coach_assignments ca2 WHERE ca2.member_id = m.id AND ca2.coach_id = ? AND ca2.released_at IS NULL)";
            $params[] = (int)$coachFilter;
        }

        $whereSQL = implode(' AND ', $where);
        $havingSQL = $havingClauses ? 'HAVING ' . implode(' AND ', $havingClauses) : '';

        $sql = "
            SELECT m.*,
              {$statusSQL} AS display_status,
              (SELECT GROUP_CONCAT(DISTINCT c.coach_name SEPARATOR ', ')
               FROM coach_assignments ca
               JOIN coaches c ON c.id = ca.coach_id
               WHERE ca.member_id = m.id AND ca.released_at IS NULL) AS current_coaches,
              (SELECT COUNT(*) FROM orders o WHERE o.member_id = m.id) AS order_count,
              (SELECT ma.source_id FROM member_accounts ma
               WHERE ma.member_id = m.id AND ma.source = 'soritune' LIMIT 1) AS soritune_id
            FROM members m
            WHERE {$whereSQL}
            {$havingSQL}
            ORDER BY m.updated_at DESC
            LIMIT 100
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonSuccess(['members' => $stmt->fetchAll()]);

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');

        // Coach role: verify access
        if ($user['role'] === 'coach') {
            $stmt = $db->prepare("SELECT 1 FROM coach_assignments WHERE member_id = ? AND coach_id = ? AND released_at IS NULL");
            $stmt->execute([$id, $user['id']]);
            if (!$stmt->fetch()) jsonError('접근 권한이 없습니다', 403);
        }

        $statusSQL = memberStatusSQL();
        $stmt = $db->prepare("
            SELECT m.*,
              {$statusSQL} AS display_status,
              (SELECT ma.source_id FROM member_accounts ma WHERE ma.member_id = m.id AND ma.source = 'soritune' LIMIT 1) AS soritune_id
            FROM members m WHERE m.id = ?
        ");
        $stmt->execute([$id]);
        $member = $stmt->fetch();
        if (!$member) jsonError('회원을 찾을 수 없습니다', 404);

        // Current coaches
        $stmt = $db->prepare("
            SELECT ca.*, c.coach_name
            FROM coach_assignments ca
            JOIN coaches c ON c.id = ca.coach_id
            WHERE ca.member_id = ? AND ca.released_at IS NULL
        ");
        $stmt->execute([$id]);
        $member['current_coaches'] = $stmt->fetchAll();

        // Linked accounts
        $stmt = $db->prepare("SELECT * FROM member_accounts WHERE member_id = ? ORDER BY is_primary DESC");
        $stmt->execute([$id]);
        $member['accounts'] = $stmt->fetchAll();

        jsonSuccess(['member' => $member]);

    case 'create':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $input = getJsonInput();
        $name = trim($input['name'] ?? '');
        if (!$name) jsonError('이름을 입력하세요');

        $phone = normalizePhone($input['phone'] ?? null);
        $email = trim($input['email'] ?? '') ?: null;

        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO members (name, phone, email, memo) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $phone, $email, $input['memo'] ?? null]);
        $memberId = (int)$db->lastInsertId();

        // Create primary account
        $stmt = $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email, is_primary)
            VALUES (?, 'manual', NULL, ?, ?, ?, 1)");
        $stmt->execute([$memberId, $name, $phone, $email]);

        // If soritune_id provided, add soritune account
        $sorituneId = trim($input['soritune_id'] ?? '');
        if ($sorituneId) {
            $stmt = $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email, is_primary)
                VALUES (?, 'soritune', ?, ?, ?, ?, 0)");
            $stmt->execute([$memberId, $sorituneId, $name, $phone, $email]);
        }

        $db->commit();
        jsonSuccess(['id' => $memberId], '회원이 등록되었습니다');

    case 'update':
        if ($user['role'] !== 'admin') jsonError('권한이 없습니다', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $input = getJsonInput();

        $fields = [];
        $params = [];
        if (array_key_exists('name', $input)) { $fields[] = 'name = ?'; $params[] = trim($input['name']); }
        if (array_key_exists('phone', $input)) { $fields[] = 'phone = ?'; $params[] = normalizePhone($input['phone']); }
        if (array_key_exists('email', $input)) { $fields[] = 'email = ?'; $params[] = trim($input['email']) ?: null; }
        if (array_key_exists('memo', $input)) { $fields[] = 'memo = ?'; $params[] = $input['memo']; }

        if (empty($fields)) jsonError('변경할 항목이 없습니다');
        $params[] = $id;

        // Log change
        $stmt = $db->prepare("SELECT name, phone, email, memo FROM members WHERE id = ?");
        $stmt->execute([$id]);
        $oldData = $stmt->fetch();

        $db->prepare("UPDATE members SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

        $stmt = $db->prepare("SELECT name, phone, email, memo FROM members WHERE id = ?");
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
