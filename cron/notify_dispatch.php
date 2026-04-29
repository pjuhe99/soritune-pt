<?php
// PT 알림톡 cron 진입점.
// 크론탭에서 5분 간격으로 호출, 활성 시나리오의 schedule 매칭 시 디스패치.
// 등록 예 (crontab):
//   STAR/5 STAR STAR STAR STAR  php /var/www/html/_______site_SORITUNECOM_PT/cron/notify_dispatch.php >> /var/www/html/_______site_SORITUNECOM_PT/logs/notify_cron.log 2>&1
// (위에서 STAR는 *. PHP 주석 종료자 충돌 방지로 STAR로 표기)

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/notify/dispatcher.php';

try {
    notifyDispatch();
    echo '[' . date('Y-m-d H:i:s') . "] notify_dispatch tick OK\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
