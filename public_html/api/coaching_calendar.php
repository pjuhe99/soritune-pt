<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coaching_calendar.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db = getDB();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list': {
            $rows = $db->query("
                SELECT * FROM coaching_calendars
                ORDER BY cohort_month DESC, product_name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            jsonSuccess(['calendars' => $rows]);
        }

        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonError('ID가 필요합니다');
            $stmt = $db->prepare("SELECT * FROM coaching_calendars WHERE id = ?");
            $stmt->execute([$id]);
            $cal = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cal) jsonError('캘린더를 찾을 수 없습니다', 404);
            $cal['dates'] = CoachingCalendar::get_dates($id);
            jsonSuccess(['calendar' => $cal]);
        }

        case 'pattern_preview': {
            $input = getJsonInput();
            $start = trim((string)($input['start'] ?? ''));
            $count = (int)($input['count'] ?? 0);
            $pattern = trim((string)($input['pattern'] ?? ''));
            if ($start === '' || $count <= 0 || $pattern === '') {
                jsonError('start, count, pattern은 필수입니다');
            }
            try {
                $dates = CoachingCalendar::generate_pattern($start, $count, $pattern);
            } catch (InvalidArgumentException $e) {
                jsonError($e->getMessage());
            }
            jsonSuccess(['dates' => $dates]);
        }

        case 'create': {
            $input = getJsonInput();
            $cohortMonth = trim((string)($input['cohort_month'] ?? ''));
            $productName = trim((string)($input['product_name'] ?? ''));
            $sessionCount = (int)($input['session_count'] ?? 0);
            if ($cohortMonth === '' || $productName === '' || $sessionCount <= 0) {
                jsonError('cohort_month, product_name, session_count는 필수입니다');
            }
            try {
                $calId = CoachingCalendar::create([
                    'cohort_month'  => $cohortMonth,
                    'product_name'  => $productName,
                    'session_count' => $sessionCount,
                    'notes'         => $input['notes'] ?? null,
                    'created_by'    => (int)$admin['id'],
                ]);
                if (!empty($input['dates']) && is_array($input['dates'])) {
                    CoachingCalendar::set_dates($calId, $input['dates']);
                }
            } catch (InvalidArgumentException $e) {
                jsonError($e->getMessage());
            }
            jsonSuccess(['id' => $calId]);
        }

        case 'update': {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonError('ID가 필요합니다');
            $input = getJsonInput();
            if (!isset($input['session_count'])) {
                jsonError('session_count는 필수입니다');
            }
            try {
                CoachingCalendar::update($id, [
                    'session_count' => (int)$input['session_count'],
                    'notes'         => $input['notes'] ?? null,
                ], (int)$admin['id']);
                if (isset($input['dates']) && is_array($input['dates'])) {
                    CoachingCalendar::set_dates($id, $input['dates']);
                }
            } catch (InvalidArgumentException $e) {
                jsonError($e->getMessage());
            } catch (RuntimeException $e) {
                jsonError($e->getMessage(), 404);
            }
            jsonSuccess(['id' => $id]);
        }

        case 'delete': {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonError('ID가 필요합니다');
            CoachingCalendar::delete($id, (int)$admin['id']);
            jsonSuccess(['id' => $id]);
        }

        default:
            jsonError('unknown action: ' . $action);
    }
} catch (Throwable $e) {
    error_log('coaching_calendar API: ' . $e->getMessage());
    jsonError('서버 오류: ' . $e->getMessage(), 500);
}
