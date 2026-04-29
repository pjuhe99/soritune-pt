<?php
declare(strict_types=1);

$dir = __DIR__;
$files = glob($dir . '/*_test.php');
if (!$files) {
    echo "No test files found in {$dir}\n";
    exit(1);
}

require_once $dir . '/_bootstrap.php';

foreach ($files as $f) {
    echo "\n>>> " . basename($f) . "\n";
    require $f;
}

exit(t_summary());
