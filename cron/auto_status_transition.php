<?php
declare(strict_types=1);

require __DIR__ . '/../public_html/includes/db.php';
require __DIR__ . '/../public_html/includes/helpers.php';

$db = getDB();
$today = date('Y-m-d');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$candidates = $db->query("
    SELECT id FROM orders
     WHERE status NOT IN ('연기','중단','환불','종료')
")->fetchAll(PDO::FETCH_COLUMN);

$summary = ['total' => count($candidates), 'changed' => 0, 'errors' => 0];

foreach ($candidates as $orderId) {
    try {
        $newStatus = withOrderLock($db, (int)$orderId, function () use ($db, $orderId, $today) {
            return recomputeOrderStatus($db, (int)$orderId, $today);
        });
        if ($newStatus !== null) {
            $summary['changed']++;
        }
    } catch (Throwable $e) {
        $summary['errors']++;
        error_log("auto_status_transition: order={$orderId} err=" . $e->getMessage());
    }
}

$logLine = sprintf(
    "[%s] candidates=%d changed=%d errors=%d\n",
    date('Y-m-d H:i:s'),
    $summary['total'], $summary['changed'], $summary['errors']
);
file_put_contents($logDir . '/auto_status.log', $logLine, FILE_APPEND);
