<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coaching_calendar.php';

class CoachingLog {
    /**
     * order_sessions UPSERT (order_id, session_number 키). calendar_id 자동 link.
     */
    public static function create_for_order(int $order_id, array $data, int $actor_id): int {
        $pdo = getDb();
        $cal = CoachingCalendar::get_for_order($order_id);
        $calendar_id = $cal['id'] ?? null;
        $sn = (int)$data['session_number'];

        $existing = $pdo->prepare("SELECT id FROM order_sessions WHERE order_id=:o AND session_number=:n");
        $existing->execute([':o'=>$order_id, ':n'=>$sn]);
        $eid = (int)$existing->fetchColumn();
        if ($eid > 0) {
            self::update($eid, $data, $actor_id);
            return $eid;
        }

        $imp = (int)($data['improved'] ?? 0);
        $stmt = $pdo->prepare("INSERT INTO order_sessions
            (order_id, calendar_id, session_number, completed_at, progress, issue, solution, improved, improved_at, updated_by)
            VALUES (:o,:c,:n,:done,:p,:i,:s,:imp,:impa,:u)");
        $stmt->execute([
            ':o'=>$order_id, ':c'=>$calendar_id, ':n'=>$sn,
            ':done'=>$data['completed_at']??null,
            ':p'=>$data['progress']??null, ':i'=>$data['issue']??null,
            ':s'=>$data['solution']??null, ':imp'=>$imp,
            ':impa'=>$imp ? ($data['improved_at']??date('Y-m-d H:i:s')) : null,
            ':u'=>$actor_id,
        ]);
        $id = (int)$pdo->lastInsertId();
        self::log_change('order_session', $id, 'create', null, $data, $actor_id);
        return $id;
    }

    public static function update(int $session_id, array $data, int $actor_id): void {
        $pdo = getDb();
        $before = $pdo->query("SELECT * FROM order_sessions WHERE id=$session_id")->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new RuntimeException("session not found");

        $fields = []; $params = [':id'=>$session_id, ':u'=>$actor_id];
        foreach (['progress','issue','solution','completed_at','memo'] as $k) {
            if (array_key_exists($k, $data)) { $fields[] = "$k=:$k"; $params[":$k"] = $data[$k]; }
        }
        if (array_key_exists('improved', $data)) {
            $imp = (int)$data['improved'];
            $fields[] = "improved=:imp"; $params[':imp'] = $imp;
            if ($imp && empty($before['improved_at'])) {
                $fields[] = "improved_at=:impa"; $params[':impa'] = date('Y-m-d H:i:s');
            } elseif (!$imp) {
                $fields[] = "improved_at=NULL";
            }
        }
        $fields[] = "updated_by=:u";
        if (!$fields) return;
        $sql = "UPDATE order_sessions SET " . implode(',', $fields) . " WHERE id=:id";
        $pdo->prepare($sql)->execute($params);
        self::log_change('order_session', $session_id, 'update', $before, $data, $actor_id);
    }

    public static function bulk_update(array $session_ids, array $data, int $actor_id): int {
        if (empty($session_ids)) return 0;
        $count = 0;
        foreach ($session_ids as $sid) {
            self::update((int)$sid, $data, $actor_id);
            $count++;
        }
        return $count;
    }

    public static function delete(int $session_id, int $actor_id): void {
        $pdo = getDb();
        $before = $pdo->query("SELECT * FROM order_sessions WHERE id=$session_id")->fetch(PDO::FETCH_ASSOC);
        if (!$before) return;
        $pdo->prepare("DELETE FROM order_sessions WHERE id=:id")->execute([':id'=>$session_id]);
        self::log_change('order_session', $session_id, 'delete', $before, null, $actor_id);
    }

    public static function list_for_order(int $order_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("
            SELECT os.*, ccd.scheduled_date
            FROM order_sessions os
            LEFT JOIN coaching_calendar_dates ccd
              ON ccd.calendar_id = os.calendar_id AND ccd.session_number = os.session_number
            WHERE os.order_id = :o
            ORDER BY os.session_number
        ");
        $stmt->execute([':o' => $order_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function log_change(string $tt, int $tid, string $action, ?array $before, ?array $after, int $actor_id): void {
        $pdo = getDb();
        $stmt = $pdo->prepare("INSERT INTO change_logs (actor_type, actor_id, target_type, target_id, action, old_value, new_value)
                               VALUES ('coach', :aid, :tt, :tid, :ac, :b, :a)");
        $stmt->execute([
            ':aid'=>$actor_id, ':tt'=>$tt, ':tid'=>$tid, ':ac'=>$action,
            ':b'=>$before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            ':a'=>$after  ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
