#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * КИНТ→Bitrix24 sync runner.
 *
 * Usage: php bin/sync.php [--window-days=N] [--only=bookings|invoices|payments|cards|catalog]
 *
 * Cron example (every 15 min):
 */
// */15 * * * * /usr/bin/php /var/www/kint-b24/bin/sync.php >> /var/log/kint-sync.log 2>&1

require_once dirname(__DIR__) . '/autoload.php';

use KintB24\Config;
use KintB24\KintClient;
use KintB24\B24Client;
use KintB24\Store;
use KintB24\SyncEngine;

// ── Parse args ────────────────────────────────────────────────────────────────

$opts   = getopt('', ['window-days:', 'only:']);
$window = filter_var($opts['window-days'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($window === false) {
    fwrite(STDERR, "[ERROR] --window-days must be a positive integer\n");
    exit(1);
}
$only   = $opts['only'] ?? null;

// ── Bootstrap ─────────────────────────────────────────────────────────────────

try {
    $cfg = new Config();
} catch (\RuntimeException $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Keep overlapping cron ticks from creating the same remote records.
$lock = fopen($cfg->dbPath() . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[ERROR] Cannot acquire sync lock\n");
    exit(1);
}

$kint   = new KintClient($cfg->kintBaseUrl(), $cfg->kintUser(), $cfg->kintPass());
$b24    = new B24Client($cfg->b24WebhookUrl());
$store  = new Store($cfg->dbPath());
$engine = new SyncEngine($kint, $b24, $store, $window);

// ── Run ───────────────────────────────────────────────────────────────────────

$ts  = date('Y-m-d H:i:s');
echo "[{$ts}] sync start" . ($only ? " only={$only}" : '') . " window={$window}d\n";

$steps = [
    'bookings' => fn() => $engine->syncBookings(),
    'invoices' => fn() => $engine->syncInvoices(),
    'payments' => fn() => $engine->syncPayments(),
    'cards'    => fn() => $engine->syncGuestCards(),
    'catalog'  => fn() => $engine->syncCatalog(),
];

if ($only !== null && !isset($steps[$only])) {
    fwrite(STDERR, "[ERROR] Unknown --only value '{$only}'. Valid: " . implode(', ', array_keys($steps)) . PHP_EOL);
    exit(1);
}

$failures = 0;
foreach ($steps as $name => $fn) {
    if ($only !== null && $only !== $name) {
        continue;
    }
    try {
        echo "[" . date('H:i:s') . "] {$name}...\n";
        $failed = $fn();
        if ($failed > 0) {
            $failures += $failed;
            echo "[" . date('H:i:s') . "] {$name} FAIL: {$failed} records\n";
            continue;
        }
        echo "[" . date('H:i:s') . "] {$name} OK\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "[" . date('H:i:s') . "] {$name} FAIL: " . $e->getMessage() . "\n";
        // Log to store and continue — one failed step should not stop others
        $store->error("{$name} fatal: " . $e->getMessage());
    }
}

echo "[" . date('Y-m-d H:i:s') . "] sync done\n";
exit($failures > 0 ? 1 : 0);
