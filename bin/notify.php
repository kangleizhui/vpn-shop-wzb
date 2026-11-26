<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/mailer.php';
require __DIR__ . '/../app/notify.php';

set_time_limit(300);

$lockFile = DATA_DIR . '/notify.lock';
$fp = fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "notify already running\n");
    exit(0);
}

$t0 = microtime(true);
$r = notify_run(false);

printf("[%s] checked=%d sent=%d skipped=%d failed=%d %.2fs%s\n",
    date('Y-m-d H:i:s'), $r['checked'], $r['sent'], $r['skipped'], $r['failed'],
    microtime(true) - $t0,
    smtp_configured() ? '' : ' (SMTP 未配置，跳过发送)');

foreach ($r['errors'] as $e) fwrite(STDERR, "  error: {$e}\n");

flock($fp, LOCK_UN);
fclose($fp);
