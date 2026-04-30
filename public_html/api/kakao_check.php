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
        ORDER BY cohort
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
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
        jsonError('TODO: implement list', 501);

    case 'toggle_join':
        jsonError('TODO: implement toggle_join', 501);

    case 'set_cohort':
        jsonError('TODO: implement set_cohort', 501);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
