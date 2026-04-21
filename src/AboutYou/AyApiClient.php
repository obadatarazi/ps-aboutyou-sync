<?php

namespace Sync\AboutYou;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Sync\Config\AppConfig;
use Sync\Logger\SyncLogger;

/**
 * AboutYou Seller Center API Client
 * AboutYou is a SLAVE / CHANNEL — PrestaShop data is always authoritative.
 */
class AyApiClient
{
    private Client $http;
    private SyncLogger $logger;
    private string $merchantId;
    private AppConfig $config;

    // Rate-limit: track calls per minute
    private array $callTimestamps = [];
    private int $maxCallsPerMinute = 60;

    public function __construct(SyncLogger $logger, ?AppConfig $config = null)
    {
        $this->merchantId = $_ENV['AY_MERCHANT_ID'];
        $this->logger     = $logger;
        $this->config     = $config ?? new AppConfig();
        $timeout = (float) ($_ENV['AY_HTTP_TIMEOUT'] ?? 20);
        $connectTimeout = (float) ($_ENV['AY_HTTP_CONNECT_TIMEOUT'] ?? 8);

        $this->http = new Client([
            'base_uri' => rtrim($_ENV['AY_BASE_URL'], '/') . '/',
            'timeout'  => $timeout,
            'connect_timeout' => $connectTimeout,
            'headers'  => [
                'X-API-Key'    => $_ENV['AY_API_KEY'],
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // PRODUCTS
    // ----------------------------------------------------------------

    /**
     * Upsert a batch of products to AboutYou.
     * $products = array of mapped AboutYou product objects.
     */
    public function upsertProducts(array $products): array
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            $this->logger->info('AY upsertProducts skipped by safety mode', ['count' => count($products)]);
            return [['skipped' => true, 'count' => count($products)]];
        }
        $this->rateLimit();

        $results = [];
        $batchSize = max(1, (int) ($_ENV['SYNC_BATCH_SIZE'] ?? 50));
        foreach (array_chunk($products, $batchSize) as $batch) {
            try {
                $batchRequestId = $this->newBatchRequestId();
                $startedAt = microtime(true);
                $resp = $this->http->post('products', [
                    'json' => [
                        'items' => $batch,
                    ],
                ]);
                $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
                $body = json_decode($resp->getBody()->getContents(), true);
                $results[] = $body;

                $this->logger->info('AY upsertProducts batch ok', [
                    'count' => count($batch),
                    'status' => $resp->getStatusCode(),
                    'batch_request_id' => $batchRequestId,
                    'elapsed_ms' => $elapsedMs,
                    'style_keys' => array_values(array_filter(array_map(
                        static fn(array $item): string => (string) ($item['style_key'] ?? ''),
                        $batch
                    ))),
                ]);
            } catch (RequestException $e) {
                $this->logger->error('AY upsertProducts batch failed', [
                    'error'    => $e->getMessage(),
                    'response' => $e->hasResponse()
                        ? $e->getResponse()->getBody()->getContents()
                        : null,
                ]);
                $results[] = ['error' => $e->getMessage()];
            }
        }
        return $results;
    }

    public function getProducts(int $page = 1, int $perPage = 100): array
    {
        $this->rateLimit();
        try {
            $resp = $this->http->get('products', [
                'query' => ['page' => $page, 'per_page' => $perPage],
            ]);
            return json_decode($resp->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            $this->logger->error('AY getProducts failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ----------------------------------------------------------------
    // STOCK & PRICE
    // ----------------------------------------------------------------

    /**
     * Update stock and price for a list of SKUs.
     * $updates = [['sku' => 'SKU123', 'quantity' => 10, 'price' => 29.99], ...]
     */
    public function updateStockAndPrice(array $updates): bool
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            $this->logger->info('AY stock/price update skipped by safety mode', ['count' => count($updates)]);
            return true;
        }
        if ($updates === []) {
            return true;
        }
        $this->rateLimit();
        $stockItems = [];
        $priceItems = [];
        foreach ($updates as $update) {
            $sku = trim((string) ($update['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $stockItems[] = [
                'sku' => $sku,
                'quantity' => (int) ($update['quantity'] ?? 0),
            ];
            if (isset($update['price']) && is_array($update['price'])) {
                $priceItems[] = [
                    'sku' => $sku,
                    'price' => $update['price'],
                ];
            }
        }

        try {
            if ($stockItems !== []) {
                $startedAt = microtime(true);
                $resp = $this->http->put('products/stocks', [
                    'json' => [
                        'items' => $stockItems,
                    ],
                ]);
                $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->logger->info('AY stock update ok', [
                    'count' => count($stockItems),
                    'status' => $resp->getStatusCode(),
                    'elapsed_ms' => $elapsedMs,
                ]);
            }
            if ($priceItems !== []) {
                $startedAt = microtime(true);
                $resp = $this->http->put('products/prices', [
                    'json' => [
                        'items' => $priceItems,
                    ],
                ]);
                $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
                $this->logger->info('AY price update ok', [
                    'count' => count($priceItems),
                    'status' => $resp->getStatusCode(),
                    'elapsed_ms' => $elapsedMs,
                ]);
            }
            return true;
        } catch (RequestException $e) {
            $this->logger->error('AY stock/price update failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse()
                    ? (string) $e->getResponse()->getBody()
                    : null,
            ]);
            return false;
        }
    }

    public function updateProductStatuses(array $items): bool
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            $this->logger->info('AY product status update skipped by safety mode', ['count' => count($items)]);
            return true;
        }
        if (empty($items)) {
            return true;
        }
        $this->rateLimit();
        try {
            $startedAt = microtime(true);
            $resp = $this->http->put('products/status', [
                'json' => [
                    'items' => $items,
                ],
            ]);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->info('AY product status update ok', [
                'count' => count($items),
                'status' => $resp->getStatusCode(),
                'elapsed_ms' => $elapsedMs,
            ]);
            return $resp->getStatusCode() < 300;
        } catch (RequestException $e) {
            $this->logger->error('AY product status update failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse()
                    ? (string) $e->getResponse()->getBody()
                    : null,
            ]);
            return false;
        }
    }

    // ----------------------------------------------------------------
    // IMAGES
    // ----------------------------------------------------------------

    /**
     * Upload an image URL for a product SKU.
     * AboutYou typically accepts image URLs in the product payload,
     * but this method handles dedicated image endpoint if available.
     */
    public function uploadProductImage(string $sku, string $imageUrl, int $position = 0): bool
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            $this->logger->info('AY image upload skipped by safety mode', ['sku' => $sku]);
            return true;
        }
        $this->rateLimit();
        try {
            $resp = $this->http->post("products/{$sku}/images", [
                'json' => [
                    'url'      => $imageUrl,
                    'position' => $position,
                ],
            ]);
            return $resp->getStatusCode() < 300;
        } catch (RequestException $e) {
            $this->logger->error("AY uploadProductImage failed for SKU {$sku}", [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ----------------------------------------------------------------
    // ORDERS
    // ----------------------------------------------------------------

    /**
     * Fetch new/pending orders from AboutYou.
     */
    public function getNewOrders(): array
    {
        $this->rateLimit();
        try {
            $resp = $this->http->get('orders', [
                'query' => [
                    'order_status'   => 'open',
                    'per_page' => 100,
                ],
            ]);
            $body = json_decode($resp->getBody()->getContents(), true);
            return $this->normalizeOrders($body ?? []);
        } catch (RequestException $e) {
            $this->logger->error('AY getNewOrders failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAllOrders(string $status = '', int $page = 1): array
    {
        $this->rateLimit();
        $query = ['page' => $page, 'per_page' => 100];
        if ($status) {
            $query['status'] = $status;
        }
        try {
            $resp = $this->http->get('orders', ['query' => $query]);
            $body = json_decode($resp->getBody()->getContents(), true);
            return $this->normalizeOrders($body ?? []);
        } catch (RequestException $e) {
            $this->logger->error('AY getAllOrders failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Update order status on AboutYou (e.g. shipped, cancelled).
     */
    public function updateOrderStatus(string $ayOrderId, string $status, array $extra = []): bool
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            $this->logger->info('AY order status update skipped by safety mode', [
                'order_id' => $ayOrderId,
                'status' => $status,
            ]);
            return true;
        }
        $this->rateLimit();
        try {
            $payload = array_merge([
                'status' => $status,
                'batch_request_id' => $this->newBatchRequestId(),
            ], $extra);
            $resp = $this->http->patch("orders/{$ayOrderId}", ['json' => $payload]);
            $this->logger->info("AY order {$ayOrderId} status → {$status}");
            return $resp->getStatusCode() < 300;
        } catch (RequestException $e) {
            $this->logger->error("AY updateOrderStatus failed for {$ayOrderId}", [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ----------------------------------------------------------------
    // CATEGORIES / ATTRIBUTES
    // ----------------------------------------------------------------

    public function getCategories(): array
    {
        $this->rateLimit();
        try {
            $resp = $this->http->get('categories');
            return json_decode($resp->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            $this->logger->error('AY getCategories failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAttributes(?int $categoryId = null): array
    {
        $this->rateLimit();
        try {
            $endpoint = $categoryId !== null
                ? "categories/{$categoryId}/attribute-groups"
                : 'categories';
            $resp = $this->http->get($endpoint);
            return json_decode($resp->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            $this->logger->error('AY getAttributes failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ----------------------------------------------------------------
    // RATE LIMITER (simple sliding window)
    // ----------------------------------------------------------------

    private function rateLimit(): void
    {
        $now = microtime(true);
        // Remove timestamps older than 60 seconds
        $this->callTimestamps = array_filter(
            $this->callTimestamps,
            fn($t) => $now - $t < 60
        );

        if (count($this->callTimestamps) >= $this->maxCallsPerMinute) {
            $oldest  = min($this->callTimestamps);
            $waitFor = 60 - ($now - $oldest);
            if ($waitFor > 0) {
                $this->logger->info("Rate limit reached — sleeping {$waitFor}s");
                usleep((int) ($waitFor * 1_000_000));
            }
        }

        $this->callTimestamps[] = microtime(true);
    }

    private function normalizeOrders(array $body): array
    {
        $candidates = [];

        if (isset($body['orders']) && is_array($body['orders'])) {
            $candidates = $body['orders'];
        } elseif (isset($body['items']) && is_array($body['items'])) {
            $candidates = $body['items'];
        } elseif (isset($body['data']['orders']) && is_array($body['data']['orders'])) {
            $candidates = $body['data']['orders'];
        } elseif (isset($body['data']) && is_array($body['data']) && array_is_list($body['data'])) {
            $candidates = $body['data'];
        } elseif (array_is_list($body)) {
            $candidates = $body;
        } elseif (!empty($body)) {
            // Single order object fallback.
            $candidates = [$body];
        }

        $orders = [];
        foreach ($candidates as $entry) {
            if (!is_array($entry) || empty($entry)) {
                continue;
            }
            if (isset($entry['pagination']) && count($entry) <= 2) {
                continue;
            }
            // Keep only plausible order objects; skip pagination/meta wrappers.
            if (
                isset($entry['id']) ||
                isset($entry['order_id']) ||
                isset($entry['order_number']) ||
                isset($entry['customer']) ||
                isset($entry['shipping_address']) ||
                isset($entry['billing_address']) ||
                isset($entry['line_items']) ||
                isset($entry['order_items']) ||
                isset($entry['lines']) ||
                isset($entry['order_lines'])
            ) {
                $orders[] = $entry;
            }
        }

        return $orders;
    }

    private function newBatchRequestId(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
