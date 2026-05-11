<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coaching_log.php';

class CoachingLogMigration {

    public static function normalize_improved(string $raw): int {
        $r = trim(mb_strtolower($raw));
        if (in_array($r, ['1','true','y','yes','✓','o','ok','t'], true)) return 1;
        return 0;
    }

    public static function normalize_date(string $raw): ?string {
        $r = trim($raw);
        if ($r === '') return null;
        foreach (['Y-m-d','Y/m/d','m/d/Y','Y.m.d','d.m.Y'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $r);
            if ($dt && $dt->format($fmt) === $r) return $dt->format('Y-m-d');
        }
        $t = strtotime($r);
        return $t ? date('Y-m-d', $t) : null;
    }

    public static function normalize_datetime(string $raw): ?string {
        $r = trim($raw);
        if ($r === '') return null;
        $t = strtotime($r);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    public static function stage_csv(array $rows, string $batch_id): string {
        $pdo = getDb();
        $stmt = $pdo->prepare("INSERT INTO coaching_log_migration_preview
            (batch_id, source_row, soritune_id, cohort_month, product_name, session_number,
             scheduled_date, completed_at, progress, issue, solution, improved,
             sheet_progress_rate, sheet_improvement_rate,
             match_status, target_order_id, error_detail)
            VALUES (:b,:sr,:sid,:cm,:pn,:sn,:sd,:ca,:p,:i,:s,:imp,:spr,:sir,:ms,:toi,:ed)");

        foreach ($rows as $idx => $r) {
            $imp = self::normalize_improved((string)($r['improved'] ?? ''));
            $sd  = self::normalize_date((string)($r['scheduled_date'] ?? ''));
            $ca  = self::normalize_datetime((string)($r['completed_at'] ?? ''));
            $match = self::_match($r, $pdo);
            $stmt->execute([
                ':b' => $batch_id, ':sr' => $idx + 1,
                ':sid' => $r['soritune_id'] ?? null,
                ':cm'  => $r['cohort_month'] ?? null,
                ':pn'  => $r['product_name'] ?? null,
                ':sn'  => (int)($r['session_number'] ?? 0),
                ':sd'  => $sd, ':ca' => $ca,
                ':p'   => $r['progress'] ?? null,
                ':i'   => $r['issue']    ?? null,
                ':s'   => $r['solution'] ?? null,
                ':imp' => $imp,
                ':spr' => isset($r['sheet_progress_rate']) && $r['sheet_progress_rate'] !== ''
                          ? (float)$r['sheet_progress_rate'] : null,
                ':sir' => isset($r['sheet_improvement_rate']) && $r['sheet_improvement_rate'] !== ''
                          ? (float)$r['sheet_improvement_rate'] : null,
                ':ms'  => $match['status'],
                ':toi' => $match['order_id'],
                ':ed'  => $match['error'],
            ]);
        }
        return $batch_id;
    }

    private static function _match(array $r, PDO $pdo): array {
        $sid = $r['soritune_id'] ?? '';
        $cm  = $r['cohort_month'] ?? '';
        $pn  = $r['product_name'] ?? '';
        if (!$sid || !$cm || !$pn) return ['status'=>'date_invalid','order_id'=>null,'error'=>'필수 키 누락'];

        $stmt = $pdo->prepare("SELECT id FROM members WHERE soritune_id=?");
        $stmt->execute([$sid]);
        $mid = (int)$stmt->fetchColumn();
        if (!$mid) return ['status'=>'member_not_found','order_id'=>null,'error'=>"soritune_id: $sid"];

        $stmt = $pdo->prepare("SELECT id FROM orders WHERE member_id=? AND cohort_month=? AND product_name=? LIMIT 1");
        $stmt->execute([$mid, $cm, $pn]);
        $oid = (int)$stmt->fetchColumn();
        if (!$oid) return ['status'=>'order_not_found','order_id'=>null,'error'=>"$cm / $pn"];

        $stmt = $pdo->prepare("SELECT 1 FROM coaching_calendars WHERE cohort_month=? AND product_name=?");
        $stmt->execute([$cm, $pn]);
        if (!$stmt->fetchColumn()) return ['status'=>'calendar_missing','order_id'=>$oid,'error'=>'매칭 캘린더 미생성'];

        return ['status'=>'matched','order_id'=>$oid,'error'=>null];
    }

    public static function run_import(string $batch_id, int $actor_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT * FROM coaching_log_migration_preview
                               WHERE batch_id=:b AND match_status IN ('matched','imported')");
        $stmt->execute([':b' => $batch_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $imported = 0; $errors = 0;
        $chunks = array_chunk($rows, 500);
        foreach ($chunks as $chunk) {
            $in_outer = $pdo->inTransaction();
            if (!$in_outer) $pdo->beginTransaction();
            try {
                foreach ($chunk as $r) {
                    CoachingLog::create_for_order((int)$r['target_order_id'], [
                        'session_number' => (int)$r['session_number'],
                        'completed_at'   => $r['completed_at'],
                        'progress'       => $r['progress'],
                        'issue'          => $r['issue'],
                        'solution'       => $r['solution'],
                        'improved'       => (int)$r['improved'],
                    ], $actor_id);

                    $upd = $pdo->prepare("UPDATE coaching_log_migration_preview SET match_status='imported' WHERE id=?");
                    $upd->execute([$r['id']]);

                    self::log_migration_row($batch_id, (int)$r['source_row'], (int)$r['target_order_id'], 'success', null);
                    $imported++;
                }
                if (!$in_outer) $pdo->commit();
            } catch (Throwable $e) {
                if (!$in_outer) $pdo->rollBack();
                $errors += count($chunk);
                self::log_migration_row($batch_id, 0, 0, 'error', $e->getMessage());
            }
        }
        return ['imported' => $imported, 'errors' => $errors];
    }

    private static function log_migration_row(string $batch, int $src_row, int $target_id, string $status, ?string $msg): void {
        $pdo = getDb();
        $stmt = $pdo->prepare("INSERT INTO migration_logs
            (batch_id, source_type, source_row, target_table, target_id, status, message)
            VALUES (?,'coaching_log',?,'order_sessions',?,?,?)");
        $stmt->execute([$batch, $src_row, $target_id, $status, $msg]);
    }
}
