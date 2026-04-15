<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'upload':
        if (empty($_FILES['file'])) jsonError('파일을 선택하세요');
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'tsv', 'txt'])) jsonError('CSV 또는 TSV 파일만 지원합니다');

        $batchId = date('Ymd-His') . '-' . substr(uniqid(), -6);
        $destDir = __DIR__ . '/../uploads/imports/';
        $destPath = $destDir . $batchId . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            jsonError('파일 업로드에 실패했습니다');
        }

        // Parse file
        $rows = [];
        $handle = fopen($destPath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $delimiter = $ext === 'tsv' ? "\t" : ',';
        $headers = fgetcsv($handle, 0, $delimiter);
        $headers = array_map('trim', $headers);

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) === count($headers)) {
                $rows[] = array_combine($headers, array_map('trim', $row));
            }
        }
        fclose($handle);

        jsonSuccess([
            'batch_id' => $batchId,
            'headers' => $headers,
            'row_count' => count($rows),
            'sample' => array_slice($rows, 0, 5),
        ], '파일이 업로드되었습니다');

    case 'import_members':
        $input = getJsonInput();
        $batchId = $input['batch_id'] ?? '';
        if (!$batchId) jsonError('batch_id가 필요합니다');

        // Check duplicate batch
        $stmt = $db->prepare("SELECT 1 FROM migration_logs WHERE batch_id = ? LIMIT 1");
        $stmt->execute([$batchId]);
        if ($stmt->fetch()) jsonError('이미 처리된 배치입니다');

        // Re-read file
        $files = glob(__DIR__ . "/../uploads/imports/{$batchId}.*");
        if (empty($files)) jsonError('파일을 찾을 수 없습니다');
        $filePath = $files[0];
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $delimiter = $ext === 'tsv' ? "\t" : ',';

        $handle = fopen($filePath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        $headers = array_map('trim', fgetcsv($handle, 0, $delimiter));

        $stats = ['success' => 0, 'skipped' => 0, 'error' => 0];
        $rowNum = 1;

        while (($raw = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if (count($raw) !== count($headers)) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '컬럼 수 불일치']);
                $stats['error']++;
                continue;
            }
            $row = array_combine($headers, array_map('trim', $raw));

            $name = $row['이름'] ?? $row['name'] ?? '';
            if (!$name) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '이름 누락']);
                $stats['error']++;
                continue;
            }

            $phone = normalizePhone($row['전화번호'] ?? $row['phone'] ?? null);
            $email = $row['이메일'] ?? $row['email'] ?? null;
            $sorituneId = $row['soritune_id'] ?? $row['Soritune ID'] ?? '';
            $memo = $row['메모'] ?? $row['memo'] ?? '';

            // Check for existing member (natural key: soritune_id or name+phone)
            $existingId = null;
            if ($sorituneId) {
                $stmt = $db->prepare("SELECT ma.member_id FROM member_accounts ma WHERE ma.source = 'soritune' AND ma.source_id = ?");
                $stmt->execute([$sorituneId]);
                $existingId = $stmt->fetchColumn() ?: null;
            }
            if (!$existingId && $phone && $name) {
                $stmt = $db->prepare("SELECT id FROM members WHERE name = ? AND phone = ? AND merged_into IS NULL");
                $stmt->execute([$name, $phone]);
                $existingId = $stmt->fetchColumn() ?: null;
            }

            if ($existingId) {
                // Add account to existing member
                $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email)
                    VALUES (?, 'import', ?, ?, ?, ?)")->execute([$existingId, $sorituneId ?: null, $name, $phone, $email]);
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, target_table, target_id, status, message)
                    VALUES (?, 'spreadsheet', ?, 'member_accounts', ?, 'success', ?)")
                    ->execute([$batchId, $rowNum, (int)$db->lastInsertId(), '기존 회원에 계정 추가']);
                $stats['success']++;
                continue;
            }

            // Create new member
            $db->prepare("INSERT INTO members (name, phone, email, memo) VALUES (?, ?, ?, ?)")
                ->execute([$name, $phone, $email ?: null, $memo ?: null]);
            $memberId = (int)$db->lastInsertId();

            // Primary account
            $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email, is_primary)
                VALUES (?, 'import', ?, ?, ?, ?, 1)")
                ->execute([$memberId, $sorituneId ?: null, $name, $phone, $email]);

            // Soritune account if ID provided
            if ($sorituneId) {
                $db->prepare("INSERT INTO member_accounts (member_id, source, source_id, name, phone, email)
                    VALUES (?, 'soritune', ?, ?, ?, ?)")
                    ->execute([$memberId, $sorituneId, $name, $phone, $email]);
            }

            $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, target_table, target_id, status, message)
                VALUES (?, 'spreadsheet', ?, 'members', ?, 'success', ?)")
                ->execute([$batchId, $rowNum, $memberId, '신규 회원 생성']);
            $stats['success']++;
        }
        fclose($handle);

        jsonSuccess(['stats' => $stats], "처리 완료: 성공 {$stats['success']} / 스킵 {$stats['skipped']} / 에러 {$stats['error']}");

    case 'import_orders':
        $input = getJsonInput();
        $batchId = $input['batch_id'] ?? '';
        if (!$batchId) jsonError('batch_id가 필요합니다');

        $stmt = $db->prepare("SELECT 1 FROM migration_logs WHERE batch_id = ? LIMIT 1");
        $stmt->execute([$batchId]);
        if ($stmt->fetch()) jsonError('이미 처리된 배치입니다');

        $files = glob(__DIR__ . "/../uploads/imports/{$batchId}.*");
        if (empty($files)) jsonError('파일을 찾을 수 없습니다');
        $filePath = $files[0];
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $delimiter = $ext === 'tsv' ? "\t" : ',';

        $handle = fopen($filePath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        $headers = array_map('trim', fgetcsv($handle, 0, $delimiter));

        $stats = ['success' => 0, 'skipped' => 0, 'error' => 0];
        $rowNum = 1;

        while (($raw = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if (count($raw) !== count($headers)) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '컬럼 수 불일치']);
                $stats['error']++;
                continue;
            }
            $row = array_combine($headers, array_map('trim', $raw));

            $memberName = $row['회원이름'] ?? $row['member_name'] ?? '';
            $memberPhone = normalizePhone($row['전화번호'] ?? $row['phone'] ?? null);
            $productName = $row['상품명'] ?? $row['product_name'] ?? '';
            $startDate = $row['시작일'] ?? $row['start_date'] ?? '';

            if (!$memberName || !$productName || !$startDate) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '필수 필드 누락']);
                $stats['error']++;
                continue;
            }

            // Match member
            $memberId = null;
            if ($memberPhone) {
                $stmt = $db->prepare("SELECT id FROM members WHERE phone = ? AND merged_into IS NULL LIMIT 1");
                $stmt->execute([$memberPhone]);
                $memberId = $stmt->fetchColumn() ?: null;
            }
            if (!$memberId) {
                $stmt = $db->prepare("SELECT id FROM members WHERE name = ? AND merged_into IS NULL LIMIT 1");
                $stmt->execute([$memberName]);
                $memberId = $stmt->fetchColumn() ?: null;
            }
            if (!$memberId) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, "회원 매칭 실패: {$memberName}"]);
                $stats['error']++;
                continue;
            }

            // Check duplicate (natural key: member_id + product_name + start_date)
            $stmt = $db->prepare("SELECT 1 FROM orders WHERE member_id = ? AND product_name = ? AND start_date = ?");
            $stmt->execute([$memberId, $productName, $startDate]);
            if ($stmt->fetch()) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'skipped', ?)")->execute([$batchId, $rowNum, '중복 주문']);
                $stats['skipped']++;
                continue;
            }

            // Match coach
            $coachName = $row['코치명(영문)'] ?? $row['coach_name'] ?? '';
            $coachId = null;
            if ($coachName) {
                $stmt = $db->prepare("SELECT id FROM coaches WHERE coach_name = ?");
                $stmt->execute([$coachName]);
                $coachId = $stmt->fetchColumn() ?: null;
                if (!$coachId) {
                    $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                        VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, "코치 매칭 실패: {$coachName}"]);
                    $stats['error']++;
                    continue;
                }
            }

            $productTypeRaw = $row['상품유형(기간/횟수)'] ?? $row['product_type'] ?? '기간';
            $productType = (str_contains($productTypeRaw, '횟수') || $productTypeRaw === 'count') ? 'count' : 'period';
            $endDate = $row['종료일'] ?? $row['end_date'] ?? $startDate;
            $totalSessions = $productType === 'count' ? (int)($row['총횟수'] ?? $row['total_sessions'] ?? 0) : null;
            $amount = (int)str_replace([',', '원', ' '], '', $row['금액'] ?? $row['amount'] ?? '0');
            $statusRaw = $row['상태'] ?? $row['status'] ?? '매칭대기';
            $validStatuses = ['매칭대기','매칭완료','진행중','연기','중단','환불','종료'];
            $status = in_array($statusRaw, $validStatuses) ? $statusRaw : '매칭대기';
            $memo = $row['메모'] ?? $row['memo'] ?? '';

            // Validate dates
            if (!strtotime($startDate) || !strtotime($endDate)) {
                $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, status, message)
                    VALUES (?, 'spreadsheet', ?, 'error', ?)")->execute([$batchId, $rowNum, '날짜 형식 오류']);
                $stats['error']++;
                continue;
            }

            $db->prepare("INSERT INTO orders (member_id, coach_id, product_name, product_type, start_date, end_date, total_sessions, amount, status, memo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$memberId, $coachId, $productName, $productType, $startDate, $endDate, $totalSessions, $amount, $status, $memo ?: null]);
            $orderId = (int)$db->lastInsertId();

            // Create sessions for count type
            if ($productType === 'count' && $totalSessions > 0) {
                $usedSessions = (int)($row['소진횟수'] ?? $row['used_sessions'] ?? 0);
                $stmtSession = $db->prepare("INSERT INTO order_sessions (order_id, session_number, completed_at) VALUES (?, ?, ?)");
                for ($i = 1; $i <= $totalSessions; $i++) {
                    $completedAt = $i <= $usedSessions ? date('Y-m-d H:i:s') : null;
                    $stmtSession->execute([$orderId, $i, $completedAt]);
                }
            }

            // Create coach assignment
            if ($coachId) {
                $db->prepare("INSERT INTO coach_assignments (member_id, coach_id, order_id) VALUES (?, ?, ?)")
                    ->execute([$memberId, $coachId, $orderId]);
            }

            $db->prepare("INSERT INTO migration_logs (batch_id, source_type, source_row, target_table, target_id, status, message)
                VALUES (?, 'spreadsheet', ?, 'orders', ?, 'success', ?)")
                ->execute([$batchId, $rowNum, $orderId, '주문 생성']);
            $stats['success']++;
        }
        fclose($handle);

        jsonSuccess(['stats' => $stats], "처리 완료: 성공 {$stats['success']} / 스킵 {$stats['skipped']} / 에러 {$stats['error']}");

    case 'batches':
        $stmt = $db->query("
            SELECT batch_id,
              MIN(created_at) AS imported_at,
              SUM(status = 'success') AS success_count,
              SUM(status = 'skipped') AS skipped_count,
              SUM(status = 'error') AS error_count,
              COUNT(*) AS total_count
            FROM migration_logs
            GROUP BY batch_id
            ORDER BY MIN(created_at) DESC
        ");
        jsonSuccess(['batches' => $stmt->fetchAll()]);

    case 'batch_errors':
        $batchId = $_GET['batch_id'] ?? '';
        $stmt = $db->prepare("SELECT * FROM migration_logs WHERE batch_id = ? AND status IN ('error','skipped') ORDER BY source_row");
        $stmt->execute([$batchId]);
        jsonSuccess(['errors' => $stmt->fetchAll()]);

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
