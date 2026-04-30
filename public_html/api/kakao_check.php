<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireAnyAuth();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'cohorts':
        // GET ?action=cohorts[&coach_id=N]  — 데이터 있는 cohort 월 목록
        jsonError('TODO: implement cohorts', 501);

    case 'list':
        // GET ?action=list&cohort=YYYY-MM[&product=...&include_joined=0|1&coach_id=N]
        jsonError('TODO: implement list', 501);

    case 'toggle_join':
        // POST ?action=toggle_join  body={order_id, joined}
        jsonError('TODO: implement toggle_join', 501);

    case 'set_cohort':
        // POST ?action=set_cohort  body={order_ids:[], cohort_month:"YYYY-MM"|null}  — admin only
        jsonError('TODO: implement set_cohort', 501);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
