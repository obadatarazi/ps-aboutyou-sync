<?php

namespace Sync\Services;

use Sync\AboutYou\AyApiClient;
use Sync\Config\AppConfig;
use Sync\Services\AyImageNormalizer;
use Sync\Services\SyncRuntimeStatus;
use Sync\Logger\SyncLogger;
use Sync\PrestaShop\PsApiClient;
use Sync\Sync\DataMapper;
use Sync\Sync\MappingStore;
use Sync\Sync\OrderSync;
use Sync\Sync\ProductSync;
use Sync\Sync\RetryHandler;
use Sync\Sync\TextileMaterialResolver;

class SyncRunner
{
    private SyncLogger $logger;
    private RetryHandler $retry;
    private MappingStore $store;
    private ProductSync $productSync;
    private OrderSync $orderSync;
    private SyncRuntimeStatus $runtimeStatus;

    public function __construct()
    {
        $this->logger = new SyncLogger('sync');
        $config = new AppConfig();
        $this->runtimeStatus = new SyncRuntimeStatus($config);
        $this->retry = new RetryHandler($this->logger);
        $this->store = new MappingStore();
        $mapper = new DataMapper();
        $ps = new PsApiClient($this->logger, $config);
        $ay = new AyApiClient($this->logger, $config);
        $imageNormalizer = AyImageNormalizer::createFromEnv($this->logger);
        $textileMaterial = TextileMaterialResolver::createFromEnv($ps, $this->logger);
        $this->productSync = new ProductSync(
            $ps,
            $ay,
            $mapper,
            $this->store,
            $this->logger,
            $config,
            $imageNormalizer,
            $textileMaterial,
            $this->runtimeStatus
        );
        $this->orderSync = new OrderSync($ps, $ay, $mapper, $this->store, $this->logger, $config);
    }

    public function run(string $command, array $context = []): array
    {
        $maxExecution = (int) ($_ENV['SYNC_MAX_EXECUTION_TIME'] ?? 0);
        if ($maxExecution < 0) {
            $maxExecution = 0;
        }
        @ini_set('max_execution_time', (string) $maxExecution);
        if (function_exists('set_time_limit')) {
            @set_time_limit($maxExecution);
        }

        $start = microtime(true);
        $runId = bin2hex(random_bytes(8));
        $lockHandle = $this->acquireRunLock();
        if ($lockHandle === null) {
            return [
                'command' => $command,
                'run_id' => $runId,
                'ok' => false,
                'stats' => null,
                'message' => 'Another sync run is already in progress. Please wait until it finishes.',
                'elapsed' => 0.0,
            ];
        }
        $this->runtimeStatus->start($runId, $command);
        $this->logger->info("=== Sync started: {$command} ===", ['run_id' => $runId, 'command' => $command]);
        $payload = ['command' => $command, 'run_id' => $runId, 'ok' => true, 'stats' => null, 'message' => ''];

        try {
            switch ($command) {
                case 'products':
                    $productIds = self::normalizeIdList($context['ps_product_ids'] ?? null, 200);
                    if ($productIds !== []) {
                        $this->runtimeStatus->update(['phase' => 'product_sync_targeted', 'last_message' => 'Running targeted product sync']);
                        $payload['stats'] = $this->retry->run(fn() => $this->productSync->syncForProductIds($productIds), 'product-sync-targeted');
                        $payload['message'] = 'Product Sync (Selected Products)';
                    } else {
                        $this->runtimeStatus->update(['phase' => 'product_sync_full', 'last_message' => 'Running full product sync']);
                        $payload['stats'] = $this->retry->run(fn() => $this->productSync->syncAll(), 'product-sync-full');
                        $payload['message'] = 'Product Sync (Full)';
                    }
                    break;
                case 'products:inc':
                    $productIds = self::normalizeIdList($context['ps_product_ids'] ?? null, 200);
                    if ($productIds !== []) {
                        $this->runtimeStatus->update(['phase' => 'product_sync_targeted', 'last_message' => 'Running targeted product sync']);
                        $payload['stats'] = $this->retry->run(fn() => $this->productSync->syncForProductIds($productIds), 'product-sync-targeted');
                        $payload['message'] = 'Product Sync (Selected Products)';
                    } else {
                        $this->runtimeStatus->update(['phase' => 'product_sync_incremental', 'last_message' => 'Running incremental product sync']);
                        $payload['stats'] = $this->retry->run(fn() => $this->productSync->syncIncremental(), 'product-sync-incremental');
                        $payload['message'] = 'Product Sync (Incremental)';
                    }
                    break;
                case 'stock':
                    $this->runtimeStatus->update(['phase' => 'stock_sync', 'last_message' => 'Running stock and price sync']);
                    $productIds = self::normalizeIdList($context['ps_product_ids'] ?? null, 50);
                    if ($productIds !== []) {
                        $payload['stats'] = $this->retry->run(
                            fn() => $this->productSync->syncStockAndPriceForProductIds($productIds),
                            'stock-price-sync-targeted'
                        );
                        $payload['message'] = 'Stock and Price Sync (selected products)';
                    } else {
                        $payload['stats'] = $this->retry->run(fn() => $this->productSync->syncStockAndPrice(), 'stock-price-sync');
                        $payload['message'] = 'Stock and Price Sync';
                    }
                    break;
                case 'orders':
                    $this->runtimeStatus->update(['phase' => 'order_import', 'last_message' => 'Importing AboutYou orders into PrestaShop']);
                    $payload['stats'] = $this->retry->run(fn() => $this->orderSync->importNewOrders(), 'order-import');
                    $payload['message'] = 'Order Import (AY -> PS)';
                    break;
                case 'order-status':
                    $this->runtimeStatus->update(['phase' => 'order_status_push', 'last_message' => 'Pushing PrestaShop order statuses to AboutYou']);
                    $orderIds = self::normalizeIdList($context['ps_order_ids'] ?? null, 50);
                    if ($orderIds !== []) {
                        $payload['stats'] = $this->retry->run(
                            fn() => $this->orderSync->pushOrderStatusForPsOrderIds($orderIds),
                            'order-status-push-targeted'
                        );
                        $payload['message'] = 'Order Status Push (PS -> AY, selected)';
                    } else {
                        $payload['stats'] = $this->retry->run(fn() => $this->orderSync->pushOrderStatusUpdates(), 'order-status-push');
                        $payload['message'] = 'Order Status Push (PS -> AY)';
                    }
                    break;
                case 'all':
                    $this->runtimeStatus->update(['phase' => 'all_sync', 'last_message' => 'Running combined sync']);
                    $combined = [
                        'stock' => $this->retry->runSafe(fn() => $this->productSync->syncStockAndPrice(), 'stock-price-sync'),
                        'orders' => $this->retry->runSafe(fn() => $this->orderSync->importNewOrders(), 'order-import'),
                        'order_status' => $this->retry->runSafe(fn() => $this->orderSync->pushOrderStatusUpdates(), 'order-status-push'),
                    ];
                    $hasFailure = false;
                    foreach ($combined as $section => $stats) {
                        if ($stats === null) {
                            $hasFailure = true;
                            continue;
                        }
                        if (is_array($stats) && !empty($stats['failed'])) {
                            $hasFailure = true;
                        }
                        if (is_array($stats) && !empty($stats['orders_failed'])) {
                            $hasFailure = true;
                        }
                        if (is_array($stats) && !empty($stats['status_push_failed'])) {
                            $hasFailure = true;
                        }
                    }
                    $payload['stats'] = $combined;
                    $payload['message'] = $hasFailure
                        ? 'Full Sync Run (with failures)'
                        : 'Full Sync Run';
                    if ($hasFailure) {
                        $payload['ok'] = false;
                    }
                    break;
                case 'report':
                    $this->runtimeStatus->update(['phase' => 'report', 'last_message' => 'Sending daily report']);
                    $this->logger->sendDailyEmailReport();
                    $payload['message'] = 'Daily report sent';
                    $payload['stats'] = ['report' => 'sent'];
                    break;
                case 'status':
                    $this->runtimeStatus->update(['phase' => 'status', 'last_message' => 'Reading sync status']);
                    $payload['message'] = 'Sync status';
                    $payload['stats'] = array_merge($this->store->getStats(), [
                        'runtime' => $this->runtimeStatus->get(),
                    ]);
                    break;
                case 'repair-mappings':
                    $payload['message'] = 'Mapping cleanup';
                    $payload['stats'] = $this->store->cleanupProductMappings();
                    break;
                default:
                    throw new \InvalidArgumentException("Unknown command: {$command}");
            }
        } catch (\Throwable $e) {
            $payload['ok'] = false;
            $payload['message'] = $e->getMessage();
            $this->logger->critical("Sync command [{$command}] failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        $elapsed = round(microtime(true) - $start, 2);
        $this->logger->info("=== Sync finished: {$command} in {$elapsed}s ===", [
            'ok' => $payload['ok'],
            'run_id' => $runId,
            'command' => $command,
        ]);
        $payload['elapsed'] = $elapsed;
        $this->runtimeStatus->finish((bool) $payload['ok'], is_array($payload['stats']) ? $payload['stats'] : null, $elapsed, (string) $payload['message']);
        return $payload;
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private static function normalizeIdList($raw, int $max): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $out[$id] = $id;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return array_values($out);
    }

    private function acquireRunLock(): mixed
    {
        $lockPath = (string) ($_ENV['SYNC_LOCK_FILE'] ?? (__DIR__ . '/../../logs/sync-run.lock'));
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }
}
