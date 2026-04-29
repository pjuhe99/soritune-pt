<?php
/**
 * PT 알림톡 합성 어댑터: Google Sheet + PT members lookup
 *
 * 시트에서 (아이디, 발송대상Y/N, 변수컬럼들) 행을 읽고, soritune_id로
 * PT members 테이블 lookup해서 phone을 채워 넣는다.
 *
 * cfg 구조 (시나리오 파일의 source 블록):
 *   ['type'=>'pt_sheet_member',
 *    'sheet_id', 'tab', 'range', 'check_col', 'soritune_id_col', 'name_col', 'check_value'?]
 *   check_value 미지정 시 기본 'Y'.
 *
 * 반환: 발송 후보 행 (matched/unknown 모두 포함, 호출측에서 status 분류):
 *   [['row_key', 'phone', 'name', 'columns', 'match_status', 'resolved_member_id', 'sheet_row_idx', 'soritune_id'], ...]
 *
 * match_status:
 *   - 'matched'           — soritune_id 매칭 + members.phone 있음
 *   - 'merged_followed'   — soritune_id가 merged_into된 회원, 마스터 phone 사용
 *   - 'member_not_found'  — soritune_id가 DB에 없음 (phone NULL)
 *   - 'phone_empty'       — DB에는 있으나 phone NULL/빈값
 */

require_once __DIR__ . '/../db.php';
require_once dirname(__DIR__, 3) . '/cron/GoogleSheets.php';

const PT_NOTIFY_MERGE_MAX_HOPS = 5;

function notifySourcePtSheetMember(array $cfg): array {
    $required = ['sheet_id', 'tab', 'range', 'check_col', 'soritune_id_col', 'name_col'];
    foreach ($required as $r) {
        if (empty($cfg[$r])) {
            throw new RuntimeException("source.{$r} 누락");
        }
    }
    $checkValue = isset($cfg['check_value']) && $cfg['check_value'] !== ''
        ? (string)$cfg['check_value']
        : 'Y';

    $sheet = new GoogleSheets();
    $rangeFull = $cfg['tab'] . '!' . $cfg['range'];
    $values = $sheet->getValues($cfg['sheet_id'], $rangeFull);
    if (!is_array($values) || count($values) < 2) return [];

    return notifyPtResolveSheetRows($values, $cfg, $checkValue);
}

/**
 * 시트 데이터(2D array) + cfg를 받아 lookup 결과 행 배열을 반환.
 * Google Sheets 호출과 분리하여 단위 테스트 가능하게 함.
 */
function notifyPtResolveSheetRows(array $values, array $cfg, string $checkValue): array {
    $headers = array_map('strval', $values[0]);
    $headerIdx = array_flip($headers);

    foreach ([$cfg['check_col'], $cfg['soritune_id_col'], $cfg['name_col']] as $h) {
        if (!isset($headerIdx[$h])) {
            throw new RuntimeException("시트에 '{$h}' 헤더가 없습니다");
        }
    }

    $checkIdx = $headerIdx[$cfg['check_col']];

    $db = getDB();
    $stmt = $db->prepare("SELECT id, phone, merged_into FROM members WHERE soritune_id = ? LIMIT 1");

    $results = [];
    $rowCount = count($values);
    for ($i = 1; $i < $rowCount; $i++) {
        $row = $values[$i];
        $checkVal = isset($row[$checkIdx]) ? trim((string)$row[$checkIdx]) : '';
        if (strcasecmp($checkVal, $checkValue) !== 0) continue;

        $columns = [];
        foreach ($headers as $j => $h) {
            $columns[$h] = isset($row[$j]) ? (string)$row[$j] : '';
        }
        $sid = trim((string)($columns[$cfg['soritune_id_col']] ?? ''));

        $resolved = notifyPtResolveMember($stmt, $sid);

        $results[] = [
            'row_key'             => sprintf('pt:%s:%s:%d', $cfg['sheet_id'], $cfg['tab'], $i + 1),
            'phone'               => $resolved['phone'] ?? '',
            'name'                => $columns[$cfg['name_col']] ?? '',
            'columns'             => $columns,
            'match_status'        => $resolved['match_status'],
            'resolved_member_id'  => $resolved['member_id'],
            'sheet_row_idx'       => $i + 1,
            'soritune_id'         => $sid,
        ];
    }
    return $results;
}

/**
 * soritune_id로 members lookup. merged_into가 있으면 마스터로 추적 (최대 PT_NOTIFY_MERGE_MAX_HOPS 회).
 * 반환: ['member_id'=>?, 'phone'=>?, 'match_status'=>...]
 */
function notifyPtResolveMember(PDOStatement $stmt, string $soritune_id): array {
    if ($soritune_id === '') {
        return ['member_id' => null, 'phone' => null, 'match_status' => 'member_not_found'];
    }
    $stmt->execute([$soritune_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['member_id' => null, 'phone' => null, 'match_status' => 'member_not_found'];
    }

    $merged = false;
    $hops = 0;
    $db = getDB();
    $find = $db->prepare("SELECT id, phone, merged_into FROM members WHERE id = ? LIMIT 1");
    while (!empty($row['merged_into']) && $hops < PT_NOTIFY_MERGE_MAX_HOPS) {
        $find->execute([$row['merged_into']]);
        $next = $find->fetch(PDO::FETCH_ASSOC);
        if (!$next) break;
        $row = $next;
        $merged = true;
        $hops++;
    }
    if ($hops >= PT_NOTIFY_MERGE_MAX_HOPS && !empty($row['merged_into'])) {
        return ['member_id' => null, 'phone' => null, 'match_status' => 'member_not_found'];
    }

    $phone = trim((string)($row['phone'] ?? ''));
    if ($phone === '') {
        return ['member_id' => (int)$row['id'], 'phone' => null, 'match_status' => 'phone_empty'];
    }
    return [
        'member_id'    => (int)$row['id'],
        'phone'        => $phone,
        'match_status' => $merged ? 'merged_followed' : 'matched',
    ];
}

/* ===========================================================
 * CLI smoke 검증 — boot 패턴.
 * 실행: php source_pt_sheet_member.php --smoke
 * =========================================================== */
if (php_sapi_name() === 'cli' && in_array('--smoke', $argv ?? [], true)) {
    $db = getDB();

    // 테스트용 임시 회원 시드 (트랜잭션 + ROLLBACK으로 PROD DB 영향 없음)
    $db->beginTransaction();
    try {
        $db->exec("INSERT INTO members (soritune_id, name, phone) VALUES
            ('__smoke_ok__',     'SmokeOK',     '01011112222'),
            ('__smoke_nophone__','SmokeNoPhone', NULL),
            ('__smoke_master__', 'SmokeMaster', '01033334444')");
        $masterId = (int)$db->query("SELECT id FROM members WHERE soritune_id='__smoke_master__'")->fetchColumn();
        $db->prepare("INSERT INTO members (soritune_id, name, phone, merged_into) VALUES (?,?,?,?)")
           ->execute(['__smoke_merged__', 'SmokeMerged', '01099998888', $masterId]);

        // 의도적 5단계 초과 순환 시드 (6단계 체인)
        $chainIds = [];
        for ($i = 0; $i < 6; $i++) {
            $db->prepare("INSERT INTO members (soritune_id, name, phone) VALUES (?,?,?)")
               ->execute(["__smoke_chain_{$i}__", "Chain{$i}", "0102000000{$i}"]);
            $chainIds[] = (int)$db->lastInsertId();
        }
        for ($i = 0; $i < 6; $i++) {
            $next = $chainIds[($i + 1) % 6];
            $db->prepare("UPDATE members SET merged_into = ? WHERE id = ?")
               ->execute([$next, $chainIds[$i]]);
        }

        $sheetData = [
            ['아이디', '발송대상', '구매자명', '비고'],
            ['__smoke_ok__',      'Y', 'SmokeOK',     ''],
            ['__smoke_missing__', 'Y', 'NotInDB',     ''],
            ['__smoke_nophone__', 'Y', 'SmokeNoPhone',''],
            ['',                  'Y', 'EmptyId',     ''],
            ['__smoke_merged__',  'Y', 'SmokeMerged', ''],
            ['__smoke_chain_0__', 'Y', 'Chain0',      ''],
            ['__smoke_skip__',    'N', 'Skipped',     ''],  // check_value 미일치 → 결과 미포함
        ];
        $cfg = [
            'sheet_id' => 'TEST', 'tab' => 'T', 'range' => 'A1:D10',
            'check_col' => '발송대상', 'soritune_id_col' => '아이디', 'name_col' => '구매자명',
            'check_value' => 'Y',
        ];

        $results = notifyPtResolveSheetRows($sheetData, $cfg, 'Y');

        $byId = [];
        foreach ($results as $r) $byId[$r['soritune_id']] = $r;

        $assertions = [
            'matched'         => fn() => isset($byId['__smoke_ok__']) && $byId['__smoke_ok__']['match_status'] === 'matched' && $byId['__smoke_ok__']['phone'] === '01011112222',
            'member_not_found' => fn() => isset($byId['__smoke_missing__']) && $byId['__smoke_missing__']['match_status'] === 'member_not_found' && $byId['__smoke_missing__']['phone'] === '',
            'phone_empty'     => fn() => isset($byId['__smoke_nophone__']) && $byId['__smoke_nophone__']['match_status'] === 'phone_empty' && $byId['__smoke_nophone__']['phone'] === '',
            'empty_id_not_found' => fn() => isset($byId['']) && $byId['']['match_status'] === 'member_not_found',
            'merged_followed' => fn() => isset($byId['__smoke_merged__']) && $byId['__smoke_merged__']['match_status'] === 'merged_followed' && $byId['__smoke_merged__']['phone'] === '01033334444',
            'chain_failsafe'  => fn() => isset($byId['__smoke_chain_0__']) && $byId['__smoke_chain_0__']['match_status'] === 'member_not_found',
            'skip_filter'     => fn() => count($results) === 6,  // __smoke_skip__ 제외
        ];

        $allPass = true;
        foreach ($assertions as $name => $fn) {
            try {
                $ok = $fn();
            } catch (Throwable $e) { $ok = false; }
            echo ($ok ? "PASS" : "FAIL") . " - {$name}\n";
            if (!$ok) $allPass = false;
        }

        if (!$allPass) {
            echo "\nSmoke FAILED. Results dump:\n";
            print_r($results);
            $db->rollBack();
            exit(1);
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    echo "\nSmoke PASSED. (DB rolled back)\n";
    exit(0);
}
