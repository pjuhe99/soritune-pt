<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coach_team.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $stmt = $db->query("
            SELECT c.*,
              leader.coach_name AS team_leader_name,
              (SELECT COUNT(*) FROM coaches m
                WHERE m.team_leader_id = c.id AND m.id != c.id) AS team_member_count,
              (SELECT COUNT(DISTINCT o.member_id) FROM orders o
               WHERE o.coach_id = c.id AND o.status = '진행중') AS current_count
            FROM coaches c
            LEFT JOIN coaches leader ON leader.id = c.team_leader_id
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

        // 신규 필드 검증
        $kakaoUrl = null;
        try {
            $kakaoUrl = normalizeKakaoRoomUrl($input['kakao_room_url'] ?? null);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        }
        $isLeader = !empty($input['is_team_leader']);
        $teamLeaderIdInput = $isLeader ? null : (
            isset($input['team_leader_id']) && $input['team_leader_id'] !== ''
                ? (int)$input['team_leader_id'] : null
        );
        // self는 INSERT 후 알 수 있으므로 일단 입력값만 (타인 leader인 경우만 미리 검증 가능)
        if ($teamLeaderIdInput !== null) {
            try {
                validateTeamLeaderId($db, 0, $teamLeaderIdInput);
                // coachId=0은 self-체크 우회용. 타인 검증만 필요.
            } catch (InvalidArgumentException $e) {
                jsonError($e->getMessage());
            }
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO coaches
                (login_id, password_hash, coach_name, korean_name, birthdate, hired_on, role, evaluation,
                 team_leader_id, status, available, max_capacity, memo, kakao_room_url,
                 overseas, side_job, soriblock_basic, soriblock_advanced)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $loginId, $hash, $coachName,
                trim($input['korean_name'] ?? '') ?: null,
                !empty($input['birthdate']) ? $input['birthdate'] : null,
                !empty($input['hired_on']) ? $input['hired_on'] : null,
                !empty($input['role']) ? $input['role'] : null,
                !empty($input['evaluation']) ? $input['evaluation'] : null,
                $teamLeaderIdInput,
                $input['status'] ?? 'active',
                (int)($input['available'] ?? 1),
                (int)($input['max_capacity'] ?? 0),
                $input['memo'] ?? null,
                $kakaoUrl,
                (int)!empty($input['overseas']),
                (int)!empty($input['side_job']),
                (int)!empty($input['soriblock_basic']),
                (int)!empty($input['soriblock_advanced']),
            ]);
            $newId = (int)$db->lastInsertId();

            // 본인이 팀장인 경우 self-ref 업데이트
            if ($isLeader) {
                $db->prepare("UPDATE coaches SET team_leader_id = id WHERE id = ?")->execute([$newId]);
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->getCode() == 23000) jsonError('이미 사용 중인 로그인 ID입니다');
            throw $e;
        }
        jsonSuccess(['id' => $newId], '코치가 등록되었습니다');

    case 'update':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        $input = getJsonInput();

        // 현재 상태 조회 (cascade 차단 판정용)
        $cur = $db->prepare("SELECT id, status, team_leader_id FROM coaches WHERE id = ?");
        $cur->execute([$id]);
        $current = $cur->fetch();
        if (!$current) jsonError('코치를 찾을 수 없습니다', 404);
        $isCurrentlyLeader = ((int)$current['team_leader_id'] === (int)$current['id']);

        // is_team_leader / team_leader_id 의도 파악
        $hasLeaderField = array_key_exists('is_team_leader', $input)
                       || array_key_exists('team_leader_id', $input);
        $isLeaderAfter = !empty($input['is_team_leader']);
        $teamLeaderIdAfter = $isLeaderAfter ? $id : (
            isset($input['team_leader_id']) && $input['team_leader_id'] !== ''
                ? (int)$input['team_leader_id'] : null
        );

        // cascade 차단: 현재 팀장인데 (a) inactive 변경 시도 또는 (b) 팀장 해제
        if ($isCurrentlyLeader) {
            $statusAfter = $input['status'] ?? $current['status'];
            if ($statusAfter === 'inactive') {
                try { assertCanModifyLeader($db, $id, 'inactive'); }
                catch (RuntimeException $e) { jsonError($e->getMessage()); }
            }
            if ($hasLeaderField && $teamLeaderIdAfter !== $id) {
                try { assertCanModifyLeader($db, $id, 'unset_leader'); }
                catch (RuntimeException $e) { jsonError($e->getMessage()); }
            }
        }

        // team_leader_id 입력 검증 (타인 leader면 active 팀장인지 확인)
        if ($hasLeaderField && $teamLeaderIdAfter !== null && $teamLeaderIdAfter !== $id) {
            try { validateTeamLeaderId($db, $id, $teamLeaderIdAfter); }
            catch (InvalidArgumentException $e) { jsonError($e->getMessage()); }
        }

        // 카톡방 URL 정규화
        $kakaoProvided = array_key_exists('kakao_room_url', $input);
        $kakaoNormalized = null;
        if ($kakaoProvided) {
            try { $kakaoNormalized = normalizeKakaoRoomUrl($input['kakao_room_url']); }
            catch (InvalidArgumentException $e) { jsonError($e->getMessage()); }
        }

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
        if ($hasLeaderField) {
            $fields[] = "team_leader_id = ?";
            $params[] = $teamLeaderIdAfter;
        }
        if ($kakaoProvided) {
            $fields[] = "kakao_room_url = ?";
            $params[] = $kakaoNormalized;
        }
        if (empty($fields)) jsonError('변경할 항목이 없습니다');

        $params[] = $id;
        $db->prepare("UPDATE coaches SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        jsonSuccess([], '코치 정보가 수정되었습니다');

    case 'delete':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('ID가 필요합니다');
        // Block delete if any 진행중 order is assigned to this coach
        $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE coach_id = ? AND status = '진행중'");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            jsonError('현재 담당 회원이 있는 코치는 삭제할 수 없습니다');
        }
        $db->prepare("DELETE FROM coaches WHERE id = ?")->execute([$id]);
        jsonSuccess([], '코치가 삭제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
