<?php

namespace Sync\Sync;

use Sync\PrestaShop\PsApiClient;
use Sync\AboutYou\AyApiClient;
use Sync\Config\AppConfig;
use Sync\Logger\SyncLogger;
use Sync\Services\AyImageNormalizer;
use Sync\Services\SyncRuntimeStatus;

/**
 * ProductSync
 *
 * Handles ONE-WAY product sync: PrestaShop → AboutYou.
 * PrestaShop is MASTER. AboutYou is SLAVE.
 * Products are NEVER created or modified in PrestaShop from AboutYou data.
 */
class ProductSync
{
    private PsApiClient  $ps;
    private AyApiClient  $ay;
    private DataMapper   $mapper;
    private MappingStore $store;
    private SyncLogger   $logger;
    private ?AyImageNormalizer $imageNormalizer;
    private ?TextileMaterialResolver $textileMaterial;
    private ?SyncRuntimeStatus $runtimeStatus;
    private int $maxProductAttempts;

    private array $stats = [
        'fetched'   => 0,
        'pushed'    => 0,
        'skipped'   => 0,
        'failed'    => 0,
    ];

    public function __construct(
        PsApiClient  $ps,
        AyApiClient  $ay,
        DataMapper   $mapper,
        MappingStore $store,
        SyncLogger   $logger,
        ?AppConfig   $config = null,
        ?AyImageNormalizer $imageNormalizer = null,
        ?TextileMaterialResolver $textileMaterial = null,
        ?SyncRuntimeStatus $runtimeStatus = null
    ) {
        $this->ps     = $ps;
        $this->ay     = $ay;
        $this->mapper = $mapper;
        $this->store  = $store;
        $this->logger = $logger;
        $this->imageNormalizer = $imageNormalizer;
        $this->textileMaterial = $textileMaterial;
        $this->runtimeStatus = $runtimeStatus;
        $this->maxProductAttempts = (int) ($_ENV['PRODUCT_SYNC_MAX_ATTEMPTS'] ?? 3);
    }

    // ----------------------------------------------------------------
    // FULL PRODUCT SYNC
    // ----------------------------------------------------------------

    /**
     * Full sync: fetch all PrestaShop products and push to AboutYou.
     */
    public function syncAll(): array
    {
        $this->resetStats();
        $this->logger->info('ProductSync::syncAll started');

        $products = $this->ps->getAllProducts();
        $this->stats['fetched'] = count($products);

        $this->logger->info("Fetched {$this->stats['fetched']} products from PrestaShop");

        $this->processProducts($products);

        $this->store->setLastSync('products', date('Y-m-d H:i:s'));
        $this->logger->info('ProductSync::syncAll completed', $this->stats);

        return $this->stats;
    }

    // ----------------------------------------------------------------
    // INCREMENTAL SYNC
    // ----------------------------------------------------------------

    /**
     * Incremental sync: only products changed since last run.
     */
    public function syncIncremental(): array
    {
        $this->resetStats();
        $lastSync = $this->store->getLastSync('products') ?? date('Y-m-d H:i:s', strtotime('-1 hour'));
        $this->logger->info("ProductSync::syncIncremental since {$lastSync}");

        $products = $this->ps->getProductsModifiedSince($lastSync);
        $this->stats['fetched'] = count($products);

        $this->logger->info("Fetched {$this->stats['fetched']} changed products");

        $this->processProducts($products);

        $this->store->setLastSync('products', date('Y-m-d H:i:s'));
        return $this->stats;
    }

    /**
     * @param list<int> $psProductIds
     */
    public function syncForProductIds(array $psProductIds): array
    {
        $this->resetStats();
        $this->logger->info('ProductSync::syncForProductIds started', ['count' => count($psProductIds)]);
        $products = [];
        $seen = [];
        foreach ($psProductIds as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $product = $this->ps->getProduct($id);
            if ($product !== null) {
                $products[] = $product;
            } else {
                $this->stats['failed']++;
                $this->store->markProductFailure($id, 'Product not found in PrestaShop', true, $this->maxProductAttempts);
            }
        }
        $this->stats['fetched'] = count($products);
        $this->processProducts($products);
        if ($this->stats['failed'] === 0) {
            $this->store->setLastSync('products', date('Y-m-d H:i:s'));
        }
        $this->logger->info('ProductSync::syncForProductIds completed', $this->stats);

        return $this->stats;
    }

    // ----------------------------------------------------------------
    // CORE PROCESSING
    // ----------------------------------------------------------------

    private function processProducts(array $products): void
    {
        // Build category name cache
        $categoryCache = [];
        $pushMode = strtolower(trim((string) ($_ENV['AY_PRODUCT_PUSH_MODE'] ?? 'single')));
        if ($pushMode !== 'batch') {
            $pushMode = 'single';
        }
        $ayBatch = [];
        $batchMappings = [];

        foreach ($products as $index => $psProduct) {
            try {
                $productId = (int) $psProduct['id'];
                $productSeq = $index + 1;
                $this->logger->info('ProductSync product start', [
                    'product_id' => $productId,
                    'sequence' => $productSeq,
                    'total' => count($products),
                ]);
                $this->runtimeStatus?->update([
                    'phase' => 'fetching_product',
                    'current_product_id' => $productId,
                    'current_sequence' => $productSeq,
                    'total_items' => count($products),
                    'done_items' => max(0, $productSeq - 1),
                    'pushed_items' => $this->stats['pushed'],
                    'failed_items' => $this->stats['failed'],
                    'last_message' => "Fetching product {$productId}",
                ]);

                // Resolve category name
                $categoryId = (int) ($psProduct['id_category_default'] ?? 0);
                if ($categoryId && !isset($categoryCache[$categoryId])) {
                    $this->logger->info('ProductSync fetching category', [
                        'product_id' => $productId,
                        'category_id' => $categoryId,
                    ]);
                    $cat = $this->ps->getCategory($categoryId);
                    $categoryCache[$categoryId] = $cat
                        ? ($cat['name'][0]['value'] ?? $cat['name'] ?? '')
                        : '';
                }
                $categoryName = $categoryCache[$categoryId] ?? '';

                // Fetch combinations (variants)
                $this->logger->info('ProductSync fetching combinations', ['product_id' => $productId]);
                $combinations = $this->ps->getCombinations($productId);

                $psForMaterial = $psProduct;
                if ($this->textileMaterial !== null) {
                    $this->logger->info('ProductSync fetching full product for textile material', ['product_id' => $productId]);
                    $full = $this->ps->getProduct($productId, true);
                    if ($full !== null) {
                        $psForMaterial = $full;
                    }
                }
                $materialTextile = $this->textileMaterial?->resolveForProduct($psForMaterial);

                // Fetch image URLs
                $this->logger->info('ProductSync fetching images', ['product_id' => $productId]);
                $rawImageUrls = $this->ps->getProductImageUrls($productId, $psProduct);
                $imageUrls = $rawImageUrls;
                if ($this->imageNormalizer !== null) {
                    $this->logger->info('ProductSync normalizing images', [
                        'product_id' => $productId,
                        'source_count' => count($rawImageUrls),
                    ]);
                    $imageUrls = $this->imageNormalizer->normalizeImageUrls($rawImageUrls);
                    if ($rawImageUrls !== [] && $imageUrls === []) {
                        $this->logger->warning(
                            'All product images were skipped (fetch/normalize failed); sending product without images',
                            ['product_id' => $productId, 'attempted' => count($rawImageUrls)]
                        );
                    }
                }

                // Map to AboutYou format
                $this->logger->info('ProductSync mapping product payload', ['product_id' => $productId]);
                $ayProduct = $this->mapper->mapProductToAy(
                    $psProduct,
                    $combinations,
                    $imageUrls,
                    $categoryName,
                    $materialTextile
                );

                $skuMap = $this->buildSkuMap($psProduct, $combinations, $ayProduct['variants']);
                $mapping = [
                    'product_id' => $productId,
                    'style_key' => $ayProduct['style_key'],
                    'skus' => array_values($skuMap),
                    'sku_map' => $skuMap,
                ];

                $this->logger->debug("Prepared product PS#{$productId} → AY:{$ayProduct['style_key']}", [
                    'variants' => count($ayProduct['variants']),
                ]);

                if ($pushMode === 'single') {
                    $this->logger->info('ProductSync pushing product to AboutYou', [
                        'product_id' => $productId,
                        'variant_count' => count($ayProduct['variants']),
                    ]);
                    $this->runtimeStatus?->update([
                        'phase' => 'pushing_to_aboutyou',
                        'current_product_id' => $productId,
                        'current_sequence' => $productSeq,
                        'total_items' => count($products),
                        'done_items' => max(0, $productSeq - 1),
                        'pushed_items' => $this->stats['pushed'],
                        'failed_items' => $this->stats['failed'],
                        'last_message' => "Pushing product {$productId} to AboutYou",
                    ]);
                    $this->flushBatch($ayProduct['variants'], [$mapping]);
                } else {
                    foreach ($ayProduct['variants'] as $variant) {
                        $ayBatch[] = $variant;
                    }
                    $batchMappings[] = $mapping;
                    if (count($ayBatch) >= (int) ($_ENV['SYNC_BATCH_SIZE'] ?? 50)) {
                        $this->flushBatch($ayBatch, $batchMappings);
                        $ayBatch = [];
                        $batchMappings = [];
                    }
                }
                $this->logger->info('ProductSync product done', [
                    'product_id' => $productId,
                    'sequence' => $productSeq,
                    'total' => count($products),
                    'pushed' => $this->stats['pushed'],
                    'failed' => $this->stats['failed'],
                ]);
                $this->runtimeStatus?->update([
                    'phase' => 'product_done',
                    'current_product_id' => $productId,
                    'current_sequence' => $productSeq,
                    'total_items' => count($products),
                    'done_items' => $productSeq,
                    'pushed_items' => $this->stats['pushed'],
                    'failed_items' => $this->stats['failed'],
                    'last_message' => "Finished product {$productId}",
                ]);
                $this->store->clearProductFailure($productId);
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $productId = (int) ($psProduct['id'] ?? 0);
                $this->store->markProductFailure(
                    $productId,
                    $e->getMessage(),
                    false,
                    $this->maxProductAttempts
                );
                $this->runtimeStatus?->update([
                    'phase' => 'product_failed',
                    'current_product_id' => $productId,
                    'current_sequence' => $index + 1,
                    'total_items' => count($products),
                    'done_items' => $index,
                    'pushed_items' => $this->stats['pushed'],
                    'failed_items' => $this->stats['failed'],
                    'last_message' => $e->getMessage(),
                ]);
                $this->logger->error("Product PS#{$psProduct['id']} processing failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($pushMode === 'batch' && !empty($ayBatch)) {
            $this->flushBatch($ayBatch, $batchMappings);
        }
    }

    private function flushBatch(array $ayProducts, array $batchMappings): void
    {
        $results = $this->ay->upsertProducts($ayProducts);

        $failed    = 0;
        $batchSize = count($ayProducts);

        foreach ($results as $result) {
            if (isset($result['error'])) {
                $failed++;
                $this->logger->error('AY batch push failed', ['result' => $result]);
            }
        }

        if ($failed > 0) {
            $this->stats['failed'] += $batchSize;
            return;
        }

        foreach ($batchMappings as $mapping) {
            $this->store->saveProductMapping(
                (int) $mapping['product_id'],
                (string) $mapping['style_key'],
                (array) $mapping['skus'],
                (array) $mapping['sku_map']
            );
        }
        $this->stats['pushed'] += $batchSize;
    }

    // ----------------------------------------------------------------
    // STOCK & PRICE SYNC (delegated from StockSync but can be triggered here)
    // ----------------------------------------------------------------

    public function syncStockAndPrice(): array
    {
        $this->resetStats();
        $this->logger->info('ProductSync::syncStockAndPrice started');

        $allStocks = $this->ps->getAllStocks();
        $maxItems = (int) ($_ENV['STOCK_SYNC_MAX_ITEMS'] ?? 0);
        if ($maxItems > 0 && count($allStocks) > $maxItems) {
            $allStocks = array_slice($allStocks, 0, $maxItems);
            $this->logger->warning('ProductSync::syncStockAndPrice using capped stock set', [
                'max_items' => $maxItems,
            ]);
        }
        $this->stats['fetched'] = count($allStocks);
        $this->logger->info('ProductSync::syncStockAndPrice stocks loaded', [
            'count' => $this->stats['fetched'],
        ]);
        $updates   = [];
        $productCache = [];
        $combinationCache = [];
        $processed = 0;

        foreach ($allStocks as $stock) {
            $psProductId  = (int) ($stock['id_product'] ?? 0);
            $psComboId    = (int) ($stock['id_product_attribute'] ?? 0);
            $quantity     = (int) ($stock['quantity'] ?? 0);

            // Resolve the SKU from our mapping
            $mapping = $this->store->getProductMapping($psProductId);
            if (!$mapping) {
                $this->stats['skipped']++;
                continue;
            }

            $sku = $this->resolveMappedSku($psProductId, $psComboId, $mapping);

            if (!$sku) {
                $this->stats['skipped']++;
                continue;
            }

            if (!array_key_exists($psProductId, $productCache)) {
                $productCache[$psProductId] = $this->ps->getProduct($psProductId);
            }
            $product = $productCache[$psProductId];
            $price   = $product ? (float) ($product['price'] ?? 0) : 0.0;
            if ($psComboId > 0) {
                if (!array_key_exists($psProductId, $combinationCache)) {
                    $combinationCache[$psProductId] = $this->ps->getCombinations($psProductId);
                }
                foreach ($combinationCache[$psProductId] as $combo) {
                    if ((int) ($combo['id'] ?? 0) !== $psComboId) {
                        continue;
                    }
                    $price += (float) ($combo['price'] ?? 0);
                    break;
                }
            }

            $updates[] = [
                'sku'      => $sku,
                'quantity' => $quantity,
                'price'    => round($price, 2),
            ];
            $processed++;
            if ($processed % 250 === 0) {
                $this->logger->info('ProductSync::syncStockAndPrice prepare progress', [
                    'processed' => $processed,
                    'updates_ready' => count($updates),
                    'skipped' => $this->stats['skipped'],
                ]);
            }
        }

        // Push in chunks
        $chunks = array_chunk($updates, 100);
        $chunkTotal = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $mappedUpdates = $this->mapper->mapStockUpdates($chunk);
            if (!$this->ay->updateStockAndPrice($mappedUpdates)) {
                $this->stats['failed'] += count($chunk);
            } else {
                $this->stats['pushed'] += count($chunk);
            }
            $this->logger->info('ProductSync::syncStockAndPrice push progress', [
                'chunk' => $index + 1,
                'chunk_total' => $chunkTotal,
                'pushed' => $this->stats['pushed'],
                'failed' => $this->stats['failed'],
            ]);
        }

        if ($this->stats['failed'] === 0) {
            $this->store->setLastSync('stock', date('Y-m-d H:i:s'));
        }
        $this->logger->info('ProductSync::syncStockAndPrice completed', $this->stats);

        return $this->stats;
    }

    /**
     * Like syncStockAndPrice but only stock rows whose id_product is in the given id list.
     *
     * @param list<int> $psProductIds
     */
    public function syncStockAndPriceForProductIds(array $psProductIds): array
    {
        $this->resetStats();
        $idSet = [];
        foreach ($psProductIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $idSet[$id] = true;
            }
        }
        if ($idSet === []) {
            $this->logger->warning('ProductSync::syncStockAndPriceForProductIds called with no valid ids');

            return $this->stats;
        }

        $this->logger->info('ProductSync::syncStockAndPriceForProductIds started', ['product_count' => count($idSet)]);

        $allStocks = $this->ps->getStocksForProductIds(array_keys($idSet));
        $maxItems = (int) ($_ENV['STOCK_SYNC_MAX_ITEMS'] ?? 0);
        if ($maxItems > 0 && count($allStocks) > $maxItems) {
            $allStocks = array_slice($allStocks, 0, $maxItems);
            $this->logger->warning('ProductSync::syncStockAndPriceForProductIds using capped stock set', [
                'max_items' => $maxItems,
            ]);
        }
        $this->stats['fetched'] = count($allStocks);
        $updates   = [];
        $productCache = [];
        $combinationCache = [];
        $processed = 0;

        foreach ($allStocks as $stock) {
            $psProductId  = (int) ($stock['id_product'] ?? 0);
            $psComboId    = (int) ($stock['id_product_attribute'] ?? 0);
            $quantity     = (int) ($stock['quantity'] ?? 0);

            if (!isset($idSet[$psProductId])) {
                $this->stats['skipped']++;
                continue;
            }

            $mapping = $this->store->getProductMapping($psProductId);
            if (!$mapping) {
                $this->stats['skipped']++;
                continue;
            }

            $sku = $this->resolveMappedSku($psProductId, $psComboId, $mapping);

            if (!$sku) {
                $this->stats['skipped']++;
                continue;
            }

            if (!array_key_exists($psProductId, $productCache)) {
                $productCache[$psProductId] = $this->ps->getProduct($psProductId);
            }
            $product = $productCache[$psProductId];
            $price   = $product ? (float) ($product['price'] ?? 0) : 0.0;
            if ($psComboId > 0) {
                if (!array_key_exists($psProductId, $combinationCache)) {
                    $combinationCache[$psProductId] = $this->ps->getCombinations($psProductId);
                }
                foreach ($combinationCache[$psProductId] as $combo) {
                    if ((int) ($combo['id'] ?? 0) !== $psComboId) {
                        continue;
                    }
                    $price += (float) ($combo['price'] ?? 0);
                    break;
                }
            }

            $updates[] = [
                'sku'      => $sku,
                'quantity' => $quantity,
                'price'    => round($price, 2),
            ];
            $processed++;
            if ($processed % 250 === 0) {
                $this->logger->info('ProductSync::syncStockAndPriceForProductIds prepare progress', [
                    'processed' => $processed,
                    'updates_ready' => count($updates),
                    'skipped' => $this->stats['skipped'],
                ]);
            }
        }

        $chunks = array_chunk($updates, 100);
        $chunkTotal = count($chunks);
        foreach ($chunks as $index => $chunk) {
            $mappedUpdates = $this->mapper->mapStockUpdates($chunk);
            if (!$this->ay->updateStockAndPrice($mappedUpdates)) {
                $this->stats['failed'] += count($chunk);
            } else {
                $this->stats['pushed'] += count($chunk);
            }
            $this->logger->info('ProductSync::syncStockAndPriceForProductIds push progress', [
                'chunk' => $index + 1,
                'chunk_total' => $chunkTotal,
                'pushed' => $this->stats['pushed'],
                'failed' => $this->stats['failed'],
            ]);
        }

        if ($this->stats['failed'] === 0 && $updates !== []) {
            $this->store->setLastSync('stock', date('Y-m-d H:i:s'));
        }
        $this->logger->info('ProductSync::syncStockAndPriceForProductIds completed', $this->stats);

        return $this->stats;
    }

    private function resetStats(): void
    {
        $this->stats = [
            'fetched' => 0,
            'pushed' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    private function buildSkuMap(array $product, array $combinations, array $variants): array
    {
        $productId = (int) ($product['id'] ?? 0);
        $skuMap = [];
        if (empty($combinations)) {
            $skuMap[0] = (string) ($variants[0]['sku'] ?? $this->mapper->resolveSku(
                $productId,
                $product['reference'] ?? null,
                0
            ));
            return $skuMap;
        }
        foreach ($combinations as $index => $combo) {
            $comboId = (int) ($combo['id'] ?? 0);
            $variantSku = (string) ($variants[$index]['sku'] ?? '');
            $skuMap[$comboId] = $variantSku !== ''
                ? $variantSku
                : $this->mapper->resolveSku($productId, $combo['reference'] ?? null, $comboId);
        }
        return $skuMap;
    }

    private function resolveMappedSku(int $productId, int $comboId, array $mapping): ?string
    {
        $skuMap = (array) ($mapping['sku_map'] ?? []);
        if ($comboId > 0) {
            $comboKey = (string) $comboId;
            $candidate = $skuMap[$comboKey] ?? $skuMap[$comboId] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
        $simple = $skuMap['0'] ?? $skuMap[0] ?? ($mapping['skus'][0] ?? null);
        if (is_string($simple) && trim($simple) !== '') {
            return trim($simple);
        }
        return null;
    }
}
