<?php
/**
 * 알림톡 API 라우터. PT 단일 라우터 패턴 (members.php 등과 동일 구조).
 * 모든 액션 admin 권한 필요 (services/notify.php 안에서 requireAdmin() 호출).
 */
require_once __DIR__ . '/services/notify.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list_scenarios':
        handleNotifyListScenarios();
        break;
    case 'toggle':
        handleNotifyToggle($method);
        break;
    case 'preview':
        handleNotifyPreview($method);
        break;
    case 'send_now':
        handleNotifySendNow($method);
        break;
    case 'list_batches':
        handleNotifyListBatches();
        break;
    case 'batch_detail':
        handleNotifyBatchDetail();
        break;
    case 'retry_failed':
        handleNotifyRetryFailed($method);
        break;
    default:
        jsonError('알 수 없는 action: ' . $action, 404);
}
