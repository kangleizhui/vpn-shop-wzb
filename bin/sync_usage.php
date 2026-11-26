<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/hiddify.php';
require __DIR__ . '/../app/payment.php';

set_time_limit(600);

$lockFile = DATA_DIR . '/sync.lock';
$fp = fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "sync already running\n");
    exit(0);
}

$t0 = microtime(true);
$results = pool_sync_all();
$ok = 0; $errs = 0; $disabled = 0;
foreach ($results as $r) {
    if (!empty($r['ok'])) $ok++; else $errs++;
    foreach (($r['actions'] ?? []) as $a) if (str_starts_with($a, 'disable')) $disabled++;
    foreach (($r['errors'] ?? []) as $e) fwrite(STDERR, "sub#{$r['id']}: {$e}\n");
}
$singles = sync_single_orders();
try { $expiredOrders = expire_stale_orders(); } catch (Throwable $e) { $expiredOrders = 0; fwrite(STDERR, 'expire orders: ' . $e->getMessage() . "\n"); }
try { stats_snapshot(); } catch (Throwable $e) { fwrite(STDERR, 'stats: ' . $e->getMessage() . "\n"); }

printf("[%s] pools=%d ok=%d err=%d disabled=%d singles=%d expired=%d %.2fs\n",
    date('Y-m-d H:i:s'), count($results), $ok, $errs, $disabled, count($singles), $expiredOrders, microtime(true) - $t0);

flock($fp, LOCK_UN);
fclose($fp);
