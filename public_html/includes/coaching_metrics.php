<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

class CoachingMetrics {
    public static function for_order(int $order_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("
            SELECT
              COUNT(CASE WHEN os.completed_at IS NOT NULL THEN 1 END) AS done,
              COALESCE(MAX(cc.session_count), 0) AS total,
              COUNT(CASE WHEN os.improved = 1 THEN 1 END) AS improved,
              COUNT(CASE WHEN os.solution IS NOT NULL AND os.solution <> '' THEN 1 END) AS solution_total
            FROM order_sessions os
            LEFT JOIN coaching_calendars cc ON cc.id = os.calendar_id
            WHERE os.order_id = :order_id
        ");
        $stmt->execute([':order_id' => $order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['done'=>0,'total'=>0,'progress_rate'=>0.0,'improved'=>0,'solution_total'=>0,'improvement_rate'=>0.0];
        }
        $done = (int)$row['done']; $total = (int)$row['total'];
        $improved = (int)$row['improved']; $sol = (int)$row['solution_total'];
        return [
            'done' => $done,
            'total' => $total,
            'progress_rate' => $total > 0 ? round($done / $total, 4) : 0.0,
            'improved' => $improved,
            'solution_total' => $sol,
            'improvement_rate' => $sol > 0 ? round($improved / $sol, 4) : 0.0,
        ];
    }

    public static function for_member(int $member_id): array {
        $pdo = getDb();
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE member_id=:m AND status NOT IN ('환불','중단')");
        $stmt->execute([':m' => $member_id]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $agg = ['done'=>0,'total'=>0,'improved'=>0,'solution_total'=>0];
        foreach ($ids as $id) {
            $m = self::for_order((int)$id);
            $agg['done'] += $m['done'];
            $agg['total'] += $m['total'];
            $agg['improved'] += $m['improved'];
            $agg['solution_total'] += $m['solution_total'];
        }
        $agg['progress_rate'] = $agg['total'] > 0 ? round($agg['done'] / $agg['total'], 4) : 0.0;
        $agg['improvement_rate'] = $agg['solution_total'] > 0 ? round($agg['improved'] / $agg['solution_total'], 4) : 0.0;
        return $agg;
    }
}
