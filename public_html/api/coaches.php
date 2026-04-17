<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $stmt = $db->query("
            SELECT c.*,
              (SELECT COUNT(*) FROM coach_assignments ca
               WHERE ca.coach_id = c.id AND ca.released_at IS NULL) AS current_count
            FROM coaches c
            ORDER BY c.status ASC, c.coach_name ASC
        ");
        jsonSuccess(['coaches' => $stmt->fetchAll()]);

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $stmt = $db->prepare("SELECT * FROM coaches WHERE id = ?");
        $stmt->execute([$id]);
        $coach = $stmt->fetch();
        if (!$coach) jsonError('코치를 찾을 수 없습니다', 404);
        jsonSuccess(['coach' => $coach]);

    case 'create':
        $input = getJsonInput();
        $loginId = trim($input['login_id'] ?? '');
        $password = $input['password'] ?? '';
        $coachName = trim($input['coach_name'] ?? '');

        if (!$loginId || !$password || !$coachName) jsonError('필수 항목을 입력하세요');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO coaches
            (login_id, password_hash, coach_name, korean_name, birthdate, hired_on, role, evaluation,
             status, available, max_capacity, memo, overseas, side_job, soriblock_basic, soriblock_advanced)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        try {
            $stmt->execute([
                $loginId, $hash, $coachName,
                trim($input['korean_name'] ?? '') ?: null,
                !empty($input['birthdate']) ? $input['birthdate'] : null,
                !empty($input['hired_on']) ? $input['hired_on'] : null,
                !empty($input['role']) ? $input['role'] : null,
                !empty($input['evaluation']) ? $input['evaluation'] : null,
                $input['status'] ?? 'active',
                (int)($input['available'] ?? 1),
                (int)($input['max_capacity'] ?? 0),
                $input['memo'] ?? null,
                (int)!empty($input['overseas']),
                (int)!empty($input['side_job']),
                (int)!empty($input['soriblock_basic']),
                (int)!empty($input['soriblock_advanced']),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) jsonError('이미 사용 중인 로그인 ID입니다');
            throw $e;
        }
        jsonSuccess(['id' => (int)$db->lastInsertId()], '코치가 등록되었습니다');

    case 'update':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $input = getJsonInput();

        $fields = [];
        $params = [];
        $boolFields = ['available','overseas','side_job','soriblock_basic','soriblock_advanced'];
        $nullableFields = ['korean_name','birthdate','hired_on','role','evaluation'];
        foreach (['coach_name','status','max_capacity','memo'] as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = $input[$f];
            }
        }
        foreach ($boolFields as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = (int)!empty($input[$f]);
            }
        }
        foreach ($nullableFields as $f) {
            if (array_key_exists($f, $input)) {
                $fields[] = "{$f} = ?";
                $params[] = ($input[$f] === '' || $input[$f] === null) ? null : $input[$f];
            }
        }
        if (!empty($input['password'])) {
            $fields[] = "password_hash = ?";
            $params[] = password_hash($input['password'], PASSWORD_BCRYPT);
        }
        if (empty($fields)) jsonError('변경할 항목이 없습니다');

        $params[] = $id;
        $db->prepare("UPDATE coaches SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        jsonSuccess([], '코치 정보가 수정되었습니다');

    case 'delete':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        // Check for active assignments
        $stmt = $db->prepare("SELECT COUNT(*) FROM coach_assignments WHERE coach_id = ? AND released_at IS NULL");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            jsonError('현재 담당 회원이 있는 코치는 삭제할 수 없습니다');
        }
        $db->prepare("DELETE FROM coaches WHERE id = ?")->execute([$id]);
        jsonSuccess([], '코치가 삭제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
