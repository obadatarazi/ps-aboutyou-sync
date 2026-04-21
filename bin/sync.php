#!/usr/bin/env php
<?php

/**
 * bin/sync.php
 *
 * CLI entry point for all sync operations.
 *
 * Usage:
 *   php bin/sync.php products          # Full product sync PS → AY
 *   php bin/sync.php products:inc      # Incremental product sync
 *   php bin/sync.php stock             # Stock + price sync PS → AY
 *   php bin/sync.php orders            # Import new AY orders → PS
 *   php bin/sync.php order-status      # Push PS order statuses → AY
 *   php bin/sync.php repair-mappings   # Cleanup stale/empty mapping entries
 *   php bin/sync.php all               # Run all syncs in sequence
 *   php bin/sync.php report            # Send daily email report
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Sync\Services\SyncRunner;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$command = $argv[1] ?? 'all';
$runner = new SyncRunner();
$result = $runner->run($command);
printStats($result['message'] ?: $command, $result['stats']);
exit($result['ok'] ? 0 : 1);

// ----------------------------------------------------------------

function printStats(string $label, ?array $stats): void
{
    echo "\n=== {$label} ===\n";
    if (!$stats) {
        echo "No stats returned (possible error)\n";
        return;
    }
    foreach ($stats as $key => $value) {
        if (is_array($value)) {
            echo "  [{$key}]\n";
            foreach ($value as $k => $v) {
                echo "    {$k}: {$v}\n";
            }
        } else {
            echo "  {$key}: {$value}\n";
        }
    }
    echo "\n";
}
