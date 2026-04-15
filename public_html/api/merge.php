<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'suspects':
        // Find duplicate suspects by phone or email
        $stmt = $db->query("
            SELECT phone AS match_value, 'phone' AS match_type, GROUP_CONCAT(id) AS member_ids, COUNT(*) AS cnt
            FROM members
            WHERE phone IS NOT NULL AND phone != '' AND merged_into IS NULL
            GROUP BY phone HAVING cnt > 1
            UNION ALL
            SELECT email AS match_value, 'email' AS match_type, GROUP_CONCAT(id) AS member_ids, COUNT(*) AS cnt
            FROM members
            WHERE email IS NOT NULL AND email != '' AND merged_into IS NULL
            GROUP BY email HAVING cnt > 1
        ");
        $suspects = $stmt->fetchAll();

        // Deduplicate and enrich with member data
        $groups = [];
        $seen = [];
        foreach ($suspects as $s) {
            $ids = array_map('intval', explode(',', $s['member_ids']));
            sort($ids);
            $key = implode('-', $ids);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT id, name, phone, email FROM members WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $groups[] = [
                'match_type' => $s['match_type'],
                'match_value' => $s['match_value'],
                'members' => $stmt->fetchAll(),
            ];
        }
        jsonSuccess(['groups' => $groups]);

    case 'preview':
        $input = getJsonInput();
        $memberIds = $input['member_ids'] ?? [];
        $primaryId = (int)($input['primary_id'] ?? 0);

        if (count($memberIds) < 2 || !$primaryId) jsonError('2명 이상 선택하고 대표 계정을 지정하세요');
        if (!in_array($primaryId, $memberIds)) jsonError('대표 계정은 선택 목록에 포함되어야 합니다');

        $mergedIds = array_filter($memberIds, fn($id) => (int)$id !== $primaryId);
        $preview = ['primary' => null, 'absorbed' => [], 'data_counts' => []];

        // Primary member info
        $stmt = $db->prepare("SELECT id, name, phone, email FROM members WHERE id = ?");
        $stmt->execute([$primaryId]);
        $preview['primary'] = $stmt->fetch();

        foreach ($mergedIds as $mid) {
            $mid = (int)$mid;
            $stmt = $db->prepare("SELECT id, name, phone, email FROM members WHERE id = ?");
            $stmt->execute([$mid]);
            $member = $stmt->fetch();

            $counts = [];
            foreach (['orders','coach_assignments','test_results','member_notes','member_accounts'] as $table) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE member_id = ?");
                $stmt->execute([$mid]);
                $counts[$table] = (int)$stmt->fetchColumn();
            }
            $preview['absorbed'][] = ['member' => $member, 'counts' => $counts];
        }
        jsonSuccess(['preview' => $preview]);

    case 'execute':
        $input = getJsonInput();
        $primaryId = (int)($input['primary_id'] ?? 0);
        $mergedIds = array_map('intval', $input['merged_ids'] ?? []);

        if (!$primaryId || empty($mergedIds)) jsonError('대표 계정과 병합 대상을 지정하세요');

        $db->beginTransaction();

        foreach ($mergedIds as $mid) {
            // Collect moved record IDs
            $moved = [];
            $tables = ['orders','coach_assignments','test_results','member_notes','member_accounts'];
            foreach ($tables as $table) {
                $stmt = $db->prepare("SELECT id FROM {$table} WHERE member_id = ?");
                $stmt->execute([$mid]);
                $moved[$table] = array_column($stmt->fetchAll(), 'id');
            }

            // Get absorbed member data
            $stmt = $db->prepare("SELECT id, name, phone, email, memo FROM members WHERE id = ?");
            $stmt->execute([$mid]);
            $absorbedData = $stmt->fetch();

            // Move records
            foreach ($tables as $table) {
                if (!empty($moved[$table])) {
                    $db->prepare("UPDATE {$table} SET member_id = ? WHERE member_id = ?")->execute([$primaryId, $mid]);
                }
            }

            // Mark as merged
            $db->prepare("UPDATE members SET merged_into = ? WHERE id = ?")->execute([$primaryId, $mid]);

            // Log
            $db->prepare("INSERT INTO merge_logs (primary_member_id, merged_member_id, absorbed_member_data, moved_records, admin_id)
                VALUES (?, ?, ?, ?, ?)")->execute([
                $primaryId, $mid,
                json_encode($absorbedData, JSON_UNESCAPED_UNICODE),
                json_encode($moved, JSON_UNESCAPED_UNICODE),
                $admin['id'],
            ]);

            logChange($db, 'merge', $primaryId, 'member_merged',
                ['merged_member_id' => $mid], ['primary_member_id' => $primaryId],
                'admin', $admin['id']);
        }

        $db->commit();
        jsonSuccess([], count($mergedIds) . '명이 병합되었습니다');

    case 'history':
        $memberId = (int)($_GET['member_id'] ?? 0);
        if (!$memberId) jsonError('member_id가 필요합니다');
        $stmt = $db->prepare("
            SELECT ml.*, a.name AS admin_name
            FROM merge_logs ml
            JOIN admins a ON a.id = ml.admin_id
            WHERE ml.primary_member_id = ? OR ml.merged_member_id = ?
            ORDER BY ml.merged_at DESC
        ");
        $stmt->execute([$memberId, $memberId]);
        jsonSuccess(['history' => $stmt->fetchAll()]);

    case 'undo':
        $mergeLogId = (int)($_GET['id'] ?? 0);
        if (!$mergeLogId) jsonError('ID가 필요합니다');

        $stmt = $db->prepare("SELECT * FROM merge_logs WHERE id = ? AND unmerged_at IS NULL");
        $stmt->execute([$mergeLogId]);
        $log = $stmt->fetch();
        if (!$log) jsonError('병합 이력을 찾을 수 없습니다', 404);

        $movedRecords = json_decode($log['moved_records'], true);
        $mergedMemberId = (int)$log['merged_member_id'];
        $primaryMemberId = (int)$log['primary_member_id'];

        // Check for post-merge data
        $postMergeCount = 0;
        $tables = ['orders','coach_assignments','test_results','member_notes','member_accounts'];
        foreach ($tables as $table) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE member_id = ? AND created_at > ?");
            $stmt->execute([$primaryMemberId, $log['merged_at']]);
            $postMergeCount += (int)$stmt->fetchColumn();
        }

        if ($postMergeCount > 0 && empty($_GET['force'])) {
            jsonSuccess([
                'warning' => true,
                'post_merge_count' => $postMergeCount,
                'message' => "병합 후 추가된 데이터 {$postMergeCount}건이 있습니다. 이 데이터는 대표 회원에 유지됩니다.",
            ]);
            return;
        }

        $db->beginTransaction();

        // Move records back
        foreach ($tables as $table) {
            $ids = $movedRecords[$table] ?? [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge([$mergedMemberId], $ids);
                $db->prepare("UPDATE {$table} SET member_id = ? WHERE id IN ({$placeholders})")->execute($params);
            }
        }

        // Restore member
        $db->prepare("UPDATE members SET merged_into = NULL WHERE id = ?")->execute([$mergedMemberId]);
        $db->prepare("UPDATE merge_logs SET unmerged_at = NOW() WHERE id = ?")->execute([$mergeLogId]);

        logChange($db, 'merge', $primaryMemberId, 'member_unmerged',
            ['merged_member_id' => $mergedMemberId], null,
            'admin', $admin['id']);

        $db->commit();
        jsonSuccess([], '병합이 해제되었습니다');

    default:
        jsonError('알 수 없는 액션입니다', 404);
}
