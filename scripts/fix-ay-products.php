<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Sync\AboutYou\AyApiClient;
use Sync\Config\AppConfig;
use Sync\Logger\SyncLogger;
use Sync\Sync\MappingStore;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$_ENV['TEST_MODE'] = $_ENV['TEST_MODE'] ?? 'false';
$_ENV['DRY_RUN'] = $_ENV['DRY_RUN'] ?? 'false';

$logger = new SyncLogger('ay-fix');
$config = new AppConfig();
$store = new MappingStore();
$ay = new AyApiClient($logger, $config);

$args = $argv ?? [];
if (in_array('--cleanup-mappings', $args, true)) {
    $report = $store->cleanupProductMappings();
    echo json_encode(['cleanup' => $report], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$productsBody = $ay->getProducts(1, 200);
$items = [];
if (isset($productsBody['items']) && is_array($productsBody['items'])) {
    $items = $productsBody['items'];
} elseif (isset($productsBody['products']) && is_array($productsBody['products'])) {
    $items = $productsBody['products'];
}

$statusUpdated = 0;
$stockUpdated = 0;
$skipped = 0;
$failedStatus = 0;
$failedStock = 0;

$statusItems = [];
$stockItems = [];

foreach ($items as $item) {
    if (!is_array($item)) {
        $skipped++;
        continue;
    }

    $sku = (string) ($item['sku'] ?? '');
    $styleKey = (string) ($item['style_key'] ?? '');
    if ($sku === '' || $styleKey === '') {
        $skipped++;
        continue;
    }

    $currentQuantity = (int) ($item['quantity'] ?? 0);
    $statusItems[] = [
        'style_key' => $styleKey,
        'status' => 'published',
    ];
    $stockItems[] = [
        'sku' => $sku,
        'quantity' => max(0, $currentQuantity),
    ];
}

foreach (array_chunk($statusItems, 100) as $chunk) {
    if ($ay->updateProductStatuses($chunk)) {
        $statusUpdated += count($chunk);
    } else {
        $failedStatus += count($chunk);
    }
}

foreach (array_chunk($stockItems, 100) as $chunk) {
    if ($ay->updateStockAndPrice($chunk)) {
        $stockUpdated += count($chunk);
    } else {
        $failedStock += count($chunk);
    }
}

echo json_encode([
    'fetched' => count($items),
    'status_updated' => $statusUpdated,
    'stock_updated' => $stockUpdated,
    'skipped' => $skipped,
    'status_failed' => $failedStatus,
    'stock_failed' => $failedStock,
], JSON_PRETTY_PRINT) . PHP_EOL;
