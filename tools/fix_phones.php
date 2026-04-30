<?php
/**
 * 전화번호 정정 일회성 스크립트.
 *
 * 사용:
 *   php tools/fix_phones.php /root/pt-phone-fix-2026-04-30.csv          # dry-run
 *   php tools/fix_phones.php /root/pt-phone-fix-2026-04-30.csv --apply  # 실제 update
 *
 * Scope (사용자 결정 — 2026-04-30):
 *   - DB phone이 NULL/'' 이거나 '^82' 시작(`82xxxx+11` 손상 또는 `82xxxxx` 미완)인 row만 candidate
 *   - CSV의 phone이 비어있으면 그 row는 skip (정보 부재 ≠ 삭제 명령)
 *   - 정상 phone은 CSV와 다르더라도 안 건드림
 *   - 매칭 안되는 soritune_id는 리포트만 출력 + skip (insert 절대 안 함)
 *   - phone만 update (name/email은 동기화 안 함)
 *
 * Audit: 각 update당 change_logs(target_type='member', action='phone_corrected')
 */

declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/fix_phones.php <csv_path> [--apply]\n");
    exit(2);
}

$csvPath = $argv[1];
$apply = in_array('--apply', array_slice($argv, 2), true);

if (!is_readable($csvPath)) {
    fwrite(STDERR, "CSV not readable: {$csvPath}\n");
    exit(2);
}

// helpers.php의 normalizePhone(?string): ?string 사용 (하이픈/공백 제거 + +82→010 변환)

// 1. CSV 로드
$rows = [];
$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh, 0, "\t");
if ($header === false || count($header) < 3) {
    fwrite(STDERR, "CSV header invalid (need: soritune_id\\tname\\tphone\\t[email])\n");
    exit(2);
}
$colIdx = array_flip(array_map('trim', $header));
if (!isset($colIdx['soritune_id'], $colIdx['phone'])) {
    fwrite(STDERR, "CSV must have soritune_id and phone columns. Got: " . implode(',', $header) . "\n");
    exit(2);
}

while (($r = fgetcsv($fh, 0, "\t")) !== false) {
    if (count($r) < 2) continue;
    $sid = trim($r[$colIdx['soritune_id']] ?? '');
    $phone = trim($r[$colIdx['phone']] ?? '');
    if ($sid === '') continue;
    $rows[] = ['soritune_id' => $sid, 'phone' => $phone];
}
fclose($fh);

echo "CSV 로드: " . count($rows) . " 행\n";

// CSV에서 soritune_id별로 정규화된 phone (비어있지 않은 것만) 인덱싱
// 같은 soritune_id가 여러 행 있으면 마지막 non-empty 채택
$csvPhones = [];
$csvSeenEmpty = [];
foreach ($rows as $row) {
    $norm = normalizePhone($row['phone']); // null if empty
    if ($norm === null) {
        $csvSeenEmpty[$row['soritune_id']] = true;
        continue;
    }
    $csvPhones[$row['soritune_id']] = $norm;
}
echo "CSV에서 phone 있는 unique soritune_id: " . count($csvPhones) . "\n";
echo "CSV에서 phone 비어있는 soritune_id: " . count($csvSeenEmpty) . "\n";

// 2. DB candidate 조회
$db = getDB();
$cands = $db->query("
    SELECT id, soritune_id, name, phone
      FROM members
     WHERE merged_into IS NULL
       AND (phone IS NULL OR phone = '' OR phone REGEXP '^82')
")->fetchAll(PDO::FETCH_ASSOC);
echo "DB candidate (NULL/''/82-start): " . count($cands) . " 행\n\n";

// 3. matching loop
$plans = [];     // [['member_id'=>id, 'old'=>old, 'new'=>new, 'soritune_id'=>sid, 'name'=>name]]
$skipsCsvEmpty = [];     // soritune_id 리스트 (CSV에서 phone 비어있어 skip)
$skipsNoCsvMatch = [];   // soritune_id 리스트 (DB candidate인데 CSV에 없음)
$skipsSamePhone = [];    // soritune_id 리스트 (이미 같은 phone)
$csvUnmatched = [];      // CSV에 있는데 DB candidate에 없는 soritune_id

$cIndex = [];
foreach ($cands as $c) $cIndex[$c['soritune_id']] = $c;

foreach ($cands as $c) {
    $sid = $c['soritune_id'];
    if (!isset($csvPhones[$sid])) {
        if (isset($csvSeenEmpty[$sid])) {
            $skipsCsvEmpty[] = "{$sid} ({$c['name']})";
        } else {
            $skipsNoCsvMatch[] = "{$sid} ({$c['name']})";
        }
        continue;
    }
    $newPhone = $csvPhones[$sid];
    $oldPhone = $c['phone'];
    if ($oldPhone === $newPhone) {
        $skipsSamePhone[] = "{$sid} ({$c['name']}): {$oldPhone}";
        continue;
    }
    $plans[] = [
        'member_id'   => (int)$c['id'],
        'soritune_id' => $sid,
        'name'        => $c['name'],
        'old'         => $oldPhone,
        'new'         => $newPhone,
    ];
}

// CSV에 있지만 DB candidate(NULL/82-start)에 매칭 안되는 soritune_id (정상 phone인 회원이거나, 회원 자체 없음)
foreach ($csvPhones as $sid => $phone) {
    if (!isset($cIndex[$sid])) {
        $csvUnmatched[] = $sid;
    }
}

// 4. report
echo "=== UPDATE 예정 (" . count($plans) . " 건) ===\n";
foreach ($plans as $p) {
    $oldDisplay = $p['old'] === null ? 'NULL' : "'{$p['old']}'";
    echo sprintf("  [id=%d] %s (%s): %s → '%s'\n",
        $p['member_id'], $p['soritune_id'], $p['name'], $oldDisplay, $p['new']);
}

echo "\n=== SKIP — CSV에 phone 없음 (기존값 유지, " . count($skipsCsvEmpty) . " 건) ===\n";
foreach ($skipsCsvEmpty as $s) echo "  {$s}\n";

echo "\n=== SKIP — CSV에 해당 soritune_id 없음 (" . count($skipsNoCsvMatch) . " 건) ===\n";
foreach ($skipsNoCsvMatch as $s) echo "  {$s}\n";

echo "\n=== SKIP — 이미 같은 phone (" . count($skipsSamePhone) . " 건) ===\n";
foreach ($skipsSamePhone as $s) echo "  {$s}\n";

echo "\n=== INFO — CSV soritune_id가 DB candidate에 없음 (정상 phone이거나 미가입, " . count($csvUnmatched) . " 건) ===\n";
echo "  (총 " . count($csvUnmatched) . " 건 — scope A라 무시. 필요 시 별도 검증)\n";

echo "\n=== 요약 ===\n";
echo "  CSV 행: " . count($rows) . "\n";
echo "  CSV phone 있는 unique sid: " . count($csvPhones) . "\n";
echo "  DB candidate: " . count($cands) . "\n";
echo "  → UPDATE: " . count($plans) . "\n";
echo "  → SKIP (CSV phone 없음): " . count($skipsCsvEmpty) . "\n";
echo "  → SKIP (CSV에 sid 없음): " . count($skipsNoCsvMatch) . "\n";
echo "  → SKIP (같은 phone): " . count($skipsSamePhone) . "\n";

if (!$apply) {
    echo "\n[DRY-RUN] 실제 update 안 함. --apply 추가하면 실행.\n";
    exit(0);
}

if (empty($plans)) {
    echo "\nUPDATE 대상 없음. 종료.\n";
    exit(0);
}

// 5. apply
$adminId = (int)$db->query("SELECT id FROM admins WHERE login_id='admin' LIMIT 1")->fetchColumn();
if ($adminId === 0) {
    $adminId = (int)$db->query("SELECT id FROM admins LIMIT 1")->fetchColumn();
}
if ($adminId === 0) {
    fwrite(STDERR, "admin 계정 없음. abort.\n");
    exit(2);
}
echo "\n[APPLY] admin id={$adminId} 로 audit 기록.\n";

$db->beginTransaction();
try {
    $upd = $db->prepare("UPDATE members SET phone = ? WHERE id = ?");
    $applied = 0;
    foreach ($plans as $p) {
        $upd->execute([$p['new'], $p['member_id']]);
        logChange($db, 'member', $p['member_id'], 'phone_corrected',
            ['phone' => $p['old']],
            ['phone' => $p['new']],
            'admin', $adminId);
        $applied++;
    }
    $db->commit();
    echo "✓ {$applied} 건 update 완료. change_logs 기록됨.\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\nROLLBACK.\n");
    exit(1);
}
