<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/coaching_log_migration.php';

$user = requireAdmin();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'upload': {
            if (!isset($_FILES['file']) || $_FILES['file']['error']) {
                jsonError('파일 업로드 실패', 400);
            }
            $f = $_FILES['file'];
            if ($f['size'] > 5 * 1024 * 1024) {
                jsonError('파일이 5MB 를 초과합니다', 400);
            }

            $fp = fopen($f['tmp_name'], 'r');
            if (!$fp) jsonError('파일 열기 실패', 500);

            $headers = fgetcsv($fp, 0);
            if (!$headers) {
                fclose($fp);
                jsonError('헤더 없음', 400);
            }
            // UTF-8 BOM strip + trim on every header column
            $headers = array_map(fn($h) => trim((string)$h, "\xEF\xBB\xBF "), $headers);
            $headerCount = count($headers);

            $rows = [];
            while (($row = fgetcsv($fp, 0)) !== false) {
                // Pad short rows, slice long rows so array_combine never throws on column-count mismatch.
                $row = array_slice(array_pad($row, $headerCount, ''), 0, $headerCount);
                $rows[] = array_combine($headers, $row);
            }
            fclose($fp);

            $batch_id = 'COACH_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6);
            CoachingLogMigration::stage_csv($rows, $batch_id);
            jsonSuccess(['batch_id' => $batch_id, 'staged' => count($rows)]);
            break;
        }
        case 'preview': {
            $batch = $_GET['batch_id'] ?? '';
            if ($batch === '') jsonError('batch_id 필요', 400);
            $pdo = getDb();
            $summary = $pdo->prepare("SELECT match_status, COUNT(*) AS n
                FROM coaching_log_migration_preview WHERE batch_id=:b GROUP BY match_status");
            $summary->execute([':b' => $batch]);
            $rows = $pdo->prepare("SELECT * FROM coaching_log_migration_preview
                WHERE batch_id=:b ORDER BY id LIMIT 500");
            $rows->execute([':b' => $batch]);
            jsonSuccess([
                'summary' => $summary->fetchAll(PDO::FETCH_KEY_PAIR),
                'rows'    => $rows->fetchAll(PDO::FETCH_ASSOC),
            ]);
            break;
        }
        case 'import': {
            $in = getJsonInput();
            $batch = $in['batch_id'] ?? '';
            if (!$batch) jsonError('batch_id 필요', 400);
            $result = CoachingLogMigration::run_import($batch, (int)$user['id']);
            jsonSuccess($result);
            break;
        }
        default:
            jsonError('unknown action', 400);
    }
} catch (Throwable $e) {
    error_log('coaching_log_migration API: ' . $e->getMessage());
    jsonError('서버 오류: ' . $e->getMessage(), 500);
}
