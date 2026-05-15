<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

class CoachingCalendar {
    /**
     * 자동 패턴 후보 날짜 N개 생성.
     * patterns: weekday5 (월~금), mwf (월수금), tt (화목), every_day, weekend
     */
    public static function generate_pattern(string $start, int $count, string $pattern): array {
        $allowed_dow = match ($pattern) {
            'weekday5' => [1,2,3,4,5],
            'mwf'      => [1,3,5],
            'tt'       => [2,4],
            'every_day'=> [0,1,2,3,4,5,6],
            'weekend'  => [0,6],
            default => throw new InvalidArgumentException("unknown pattern: $pattern"),
        };
        $out = [];
        $d = new DateTimeImmutable($start);
        $safety = 0;
        while (count($out) < $count) {
            if (in_array((int)$d->format('w'), $allowed_dow, true)) {
                $out[] = $d->format('Y-m-d');
            }
            $d = $d->modify('+1 day');
            if (++$safety > 1000) throw new RuntimeException('pattern overflow');
        }
        return $out;
    }

    public static function create(array $data): int {
        $pdo = getDb();
        $stmt = $pdo->prepare("INSERT INTO coaching_calendars
            (cohort_month, product_name, session_count, notes, created_by)
            VALUES (:cm, :pn, :sc, :nt, :cb)");
        $stmt->execute([
            ':cm' => $data['cohort_month'],
            ':pn' => $data['product_name'],
            ':sc' => (int)$data['session_count'],
            ':nt' => $data['notes'] ?? null,
            ':cb' => (int)$data['created_by'],
        ]);
        $id = (int)$pdo->lastInsertId();
        self::log_change('coaching_calendar', $id, 'create', null, $data, (int)$data['created_by']);
        return $id;
    }

    public static function update(int $id, array $data, int $actor): void {
        $pdo = getDb();
        $before = $pdo->query("SELECT * FROM coaching_calendars WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new RuntimeException("calendar not found");
        $pdo->prepare("UPDATE coaching_calendars
            SET session_count=:sc, notes=:nt WHERE id=:id")
            ->execute([':sc'=>(int)$data['session_count'], ':nt'=>$data['notes']??null, ':id'=>$id]);
        self::log_change('coaching_calendar', $id, 'update', $before, $data, $actor);
    }

    public static function delete(int $id, int $actor): void {
        $pdo = getDb();
        $before = $pdo->query("SELECT * FROM coaching_calendars WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        if (!$before) return;
        $pdo->prepare("DELETE FROM coaching_calendars WHERE id=:id")->execute([':id'=>$id]);
        self::log_change('coaching_calendar', $id, 'delete', $before, null, $actor);
    }

    public static function set_dates(int $calendar_id, array $dates): void {
        $pdo = getDb();
        $cal = $pdo->query("SELECT session_count FROM coaching_calendars WHERE id=$calendar_id")->fetch(PDO::FETCH_ASSOC);
        if (!$cal) throw new RuntimeException("calendar not found");
        if (count($dates) !== (int)$cal['session_count']) {
            throw new InvalidArgumentException("date count " . count($dates) . " != session_count " . $cal['session_count']);
        }
        $inTx = $pdo->inTransaction();
        if (!$inTx) $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM coaching_calendar_dates WHERE calendar_id=$calendar_id");
            $stmt = $pdo->prepare("INSERT INTO coaching_calendar_dates (calendar_id, session_number, scheduled_date) VALUES (:c,:n,:d)");
            foreach ($dates as $i => $date) {
                $stmt->execute([':c'=>$calendar_id, ':n'=>$i+1, ':d'=>$date]);
            }
            if (!$inTx) $pdo->commit();
        } catch (Throwable $e) {
            if (!$inTx) $pdo->rollBack();
            throw $e;
        }
    }

    public static function get_for_order(int $order_id): ?array {
        $pdo = getDb();
        $stmt = $pdo->prepare("
            SELECT cc.* FROM coaching_calendars cc
            JOIN orders o ON o.product_name = cc.product_name AND o.cohort_month = cc.cohort_month
            WHERE o.id = :oid LIMIT 1
        ");
        $stmt->execute([':oid' => $order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function get_dates(int $calendar_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT session_number, scheduled_date FROM coaching_calendar_dates
                               WHERE calendar_id=:c ORDER BY session_number");
        $stmt->execute([':c' => $calendar_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * change_logs 기록. 실제 PT 컬럼명: action, old_value, new_value, actor_type, actor_id
     */
    private static function log_change(string $target_type, int $target_id, string $action, ?array $before, ?array $after, int $actor_id): void {
        $pdo = getDb();
        $stmt = $pdo->prepare("INSERT INTO change_logs (actor_type, actor_id, target_type, target_id, action, old_value, new_value)
                               VALUES ('admin', :aid, :tt, :tid, :ac, :b, :a)");
        $stmt->execute([
            ':aid' => $actor_id,
            ':tt'  => $target_type,
            ':tid' => $target_id,
            ':ac'  => $action,
            ':b'   => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            ':a'   => $after  ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
