<?php

namespace Sync\PrestaShop;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Sync\Config\AppConfig;
use Sync\Logger\SyncLogger;

/**
 * PrestaShop Webservice API Client
 * Handles all communication with the PrestaShop REST API.
 * PrestaShop is the MASTER — all reads are authoritative.
 */
class PsApiClient
{
    private Client $http;
    private string $baseUrl;
    private string $apiKey;
    private SyncLogger $logger;
    private AppConfig $config;
    /** @var array<int,array<string,mixed>|null> */
    private array $productOptionValueCache = [];
    /** @var array<int,array<string,mixed>|null> */
    private array $productOptionCache = [];

    public function __construct(SyncLogger $logger, ?AppConfig $config = null)
    {
        $this->baseUrl = rtrim($_ENV['PS_BASE_URL'], '/') . '/api';
        $this->apiKey  = $_ENV['PS_API_KEY'];
        $this->logger  = $logger;
        $this->config  = $config ?? new AppConfig();
        $timeout = (float) ($_ENV['PS_HTTP_TIMEOUT'] ?? 20);
        $connectTimeout = (float) ($_ENV['PS_HTTP_CONNECT_TIMEOUT'] ?? 8);

        $this->http = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout'  => $timeout,
            'connect_timeout' => $connectTimeout,
            'auth'     => [$this->apiKey, ''],   // HTTP Basic: key as username, empty password
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // PRODUCTS
    // ----------------------------------------------------------------

    /**
     * Fetch all products (IDs). Then hydrate each one.
     */
    public function getAllProducts(): array
    {
        $ids = $this->getResourceIds('products');
        $products = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            foreach ($chunk as $id) {
                $product = $this->getProduct($id);
                if ($product) {
                    $products[] = $product;
                }
            }
        }

        return $products;
    }

    public function getProduct(int $id, bool $displayFull = false): ?array
    {
        $query = $displayFull ? ['display' => 'full'] : [];

        return $this->get("products/{$id}", $query);
    }

    public function getProductFeatureValue(int $id): ?array
    {
        return $this->get('product_feature_values/' . $id);
    }

    public function getProductOptionValue(int $id): ?array
    {
        if (array_key_exists($id, $this->productOptionValueCache)) {
            return $this->productOptionValueCache[$id];
        }

        return $this->productOptionValueCache[$id] = $this->get('product_option_values/' . $id);
    }

    public function getProductOption(int $id): ?array
    {
        if (array_key_exists($id, $this->productOptionCache)) {
            return $this->productOptionCache[$id];
        }

        return $this->productOptionCache[$id] = $this->get('product_options/' . $id);
    }

    /**
     * Get products modified after a given date (incremental sync).
     */
    public function getProductsModifiedSince(string $dateIso): array
    {
        $ids = $this->getResourceIds('products', [
            'date'      => '1',
            'filter[date_upd]' => '[' . $dateIso . ',' . date('Y-m-d H:i:s') . ']',
        ]);

        $products = [];
        foreach ($ids as $id) {
            $p = $this->getProduct($id);
            if ($p) {
                $products[] = $p;
            }
        }
        return $products;
    }

    // ----------------------------------------------------------------
    // COMBINATIONS (VARIANTS)
    // ----------------------------------------------------------------

    public function getCombinations(int $productId): array
    {
        $ids = $this->getResourceIds('combinations', [
            'filter[id_product]' => $productId,
        ]);

        $combinations = [];
        foreach ($ids as $id) {
            $combo = $this->get("combinations/{$id}");
            if ($combo) {
                $combo = $this->hydrateCombinationOptionValues($combo);
                $combinations[] = $combo;
            }
        }
        return $combinations;
    }

    // ----------------------------------------------------------------
    // STOCK
    // ----------------------------------------------------------------

    public function getStockAvailable(int $productId, ?int $combinationId = null): ?array
    {
        $filter = ['filter[id_product]' => $productId];
        if ($combinationId !== null) {
            $filter['filter[id_product_attribute]'] = $combinationId;
        }
        $ids = $this->getResourceIds('stock_availables', $filter);
        if (empty($ids)) {
            return null;
        }
        return $this->get("stock_availables/{$ids[0]}");
    }

    public function updateStockAvailableQuantity(int $productId, ?int $combinationId, int $quantity): bool
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            return true;
        }

        $current = $this->getStockAvailable($productId, $combinationId);
        if (!is_array($current) || (int) ($current['id'] ?? 0) <= 0) {
            $this->logger->error('PS stock update failed: stock_available row not found', [
                'product_id' => $productId,
                'combination_id' => $combinationId,
                'quantity' => $quantity,
            ]);
            return false;
        }

        $xml = $this->stockAvailableArrayToXml($current, $quantity);
        $result = $this->put('stock_availables/' . (int) $current['id'], $xml, 'application/xml');

        return $result !== null;
    }

    public function getAllStocks(): array
    {
        $this->logger->info('PS stock fetch started');
        $ids = $this->getResourceIds('stock_availables');
        $stocks = [];
        $total = count($ids);
        $this->logger->info('PS stock ids fetched', ['count' => $total]);
        $processed = 0;
        $progressStep = max(1, (int) ($_ENV['PS_STOCK_PROGRESS_STEP'] ?? 50));
        foreach ($ids as $id) {
            $s = $this->get("stock_availables/{$id}");
            if ($s) {
                $stocks[] = $s;
            }
            $processed++;
            if ($processed % $progressStep === 0 || $processed === $total) {
                $this->logger->info('PS stock fetch progress', [
                    'processed' => $processed,
                    'total' => $total,
                ]);
            }
        }
        $this->logger->info('PS stock fetch completed', ['count' => count($stocks)]);
        return $stocks;
    }

    /**
     * @param list<int> $productIds
     * @return list<array<string,mixed>>
     */
    public function getStocksForProductIds(array $productIds): array
    {
        $normalizedIds = [];
        foreach ($productIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalizedIds[$id] = $id;
            }
        }
        if ($normalizedIds === []) {
            return [];
        }

        $filter = '[' . implode('|', array_values($normalizedIds)) . ']';
        $this->logger->info('PS targeted stock fetch started', [
            'product_count' => count($normalizedIds),
            'filter' => $filter,
        ]);
        $ids = $this->getResourceIds('stock_availables', [
            'filter[id_product]' => $filter,
        ]);
        $stocks = [];
        $total = count($ids);
        foreach ($ids as $index => $id) {
            $stock = $this->get("stock_availables/{$id}");
            if ($stock) {
                $stocks[] = $stock;
            }
            if ((($index + 1) % 50) === 0 || ($index + 1) === $total) {
                $this->logger->info('PS targeted stock fetch progress', [
                    'processed' => $index + 1,
                    'total' => $total,
                ]);
            }
        }
        $this->logger->info('PS targeted stock fetch completed', ['count' => count($stocks)]);

        return $stocks;
    }

    // ----------------------------------------------------------------
    // CATEGORIES
    // ----------------------------------------------------------------

    public function getCategory(int $id): ?array
    {
        return $this->get("categories/{$id}");
    }

    public function getAllCategories(): array
    {
        $ids = $this->getResourceIds('categories');
        $cats = [];
        foreach ($ids as $id) {
            $c = $this->getCategory($id);
            if ($c) {
                $cats[] = $c;
            }
        }
        return $cats;
    }

    // ----------------------------------------------------------------
    // IMAGES
    // ----------------------------------------------------------------

    /**
     * Returns an array of image URLs for a product.
     */
    public function getProductImageUrls(int $productId, ?array $product = null): array
    {
        $imageIds = [];

        // Prefer image IDs from product payload to avoid extra failing API calls.
        if (
            is_array($product) &&
            isset($product['associations']['images']) &&
            is_array($product['associations']['images'])
        ) {
            $imagesAssoc = $product['associations']['images'];
            $imagesRaw = $imagesAssoc['image'] ?? $imagesAssoc;
            if (is_array($imagesRaw)) {
                if (isset($imagesRaw['id'])) {
                    $imagesRaw = [$imagesRaw];
                }
                foreach ($imagesRaw as $img) {
                    $id = $img['id'] ?? null;
                    if ($id !== null && $id !== '') {
                        $imageIds[] = (string) $id;
                    }
                }
            }
        }

        if (empty($imageIds)) {
            $data = $this->get("images/products/{$productId}");
            if (!$data || !isset($data['image'])) {
                return [];
            }

            $images = $data['image'];
            if (isset($images['id'])) {
                // single image — wrap in array
                $images = [$images];
            }
            foreach ($images as $img) {
                $id = $img['id'] ?? null;
                if ($id !== null && $id !== '') {
                    $imageIds[] = (string) $id;
                }
            }
        }

        $urls = [];
        foreach ($imageIds as $id) {
            $urls[] = "{$this->baseUrl}/images/products/{$productId}/{$id}?ws_key={$this->apiKey}";
        }
        return $urls;
    }

    // ----------------------------------------------------------------
    // ORDERS
    // ----------------------------------------------------------------

    /**
     * Create an order in PrestaShop from AboutYou data.
     * Returns the new PrestaShop order ID.
     */
    public function createOrder(array $orderData): ?int
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            return -1;
        }
        if ((int) ($orderData['id_cart'] ?? 0) <= 0) {
            $cartId = $this->createCart($orderData, $orderData['items'] ?? []);
            if ($cartId === null) {
                return null;
            }
            $orderData['id_cart'] = $cartId;
        }
        $xml  = $this->orderArrayToXml($orderData);
        $resp = $this->post('orders', $xml, 'application/xml');
        if ($resp && isset($resp['order']['id'])) {
            return (int) $resp['order']['id'];
        }
        return null;
    }

    public function getOrder(int $id): ?array
    {
        return $this->get("orders/{$id}");
    }

    public function getCustomer(int $id): ?array
    {
        return $this->get('customers/' . $id);
    }

    public function updateOrderStatus(int $orderId, int $statusId): bool
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            return true;
        }
        // PrestaShop uses order_histories to change status
        $xml = $this->buildOrderHistoryXml($orderId, $statusId);
        $result = $this->post('order_histories', $xml, 'application/xml');
        return $result !== null;
    }

    public function getOrdersModifiedSince(string $dateIso): array
    {
        $ids = $this->getResourceIds('orders', [
            'date' => '1',
            'filter[date_upd]' => '[' . $dateIso . ',' . date('Y-m-d H:i:s') . ']',
        ]);

        $orders = [];
        foreach ($ids as $id) {
            $o = $this->getOrder($id);
            if ($o) {
                $orders[] = $o;
            }
        }
        return $orders;
    }

    // ----------------------------------------------------------------
    // CUSTOMERS
    // ----------------------------------------------------------------

    public function findOrCreateCustomer(array $customerData): ?int
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            return -1;
        }
        // Try find by email
        $ids = $this->getResourceIds('customers', [
            'filter[email]' => $customerData['email'],
        ]);

        if (!empty($ids)) {
            return $ids[0];
        }

        $xml    = $this->customerArrayToXml($customerData);
        $result = $this->post('customers', $xml, 'application/xml');
        return $result['customer']['id'] ?? null;
    }

    public function findOrCreateAddress(int $customerId, array $addressData): ?int
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            return -1;
        }
        $xml    = $this->addressArrayToXml($customerId, $addressData);
        $result = $this->post('addresses', $xml, 'application/xml');
        return $result['address']['id'] ?? null;
    }

    public function findProductIdByReference(string $reference): ?int
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        $ids = $this->getResourceIds('products', ['filter[reference]' => $reference]);
        return !empty($ids) ? (int) $ids[0] : null;
    }

    public function findCombinationByReference(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        $ids = $this->getResourceIds('combinations', ['filter[reference]' => $reference]);
        if (empty($ids)) {
            return null;
        }
        $combo = $this->get('combinations/' . (int) $ids[0]);
        if (!$combo) {
            return null;
        }
        return [
            'product_id' => (int) ($combo['id_product'] ?? 0),
            'combo_id' => (int) ($combo['id'] ?? 0),
        ];
    }

    public function findProductIdByEan(string $ean): ?int
    {
        $ean = trim($ean);
        if ($ean === '') {
            return null;
        }
        $ids = $this->getResourceIds('products', ['filter[ean13]' => $ean]);
        return !empty($ids) ? (int) $ids[0] : null;
    }

    public function findCombinationByEan(string $ean): ?array
    {
        $ean = trim($ean);
        if ($ean === '') {
            return null;
        }
        $ids = $this->getResourceIds('combinations', ['filter[ean13]' => $ean]);
        if (empty($ids)) {
            return null;
        }
        $combo = $this->get('combinations/' . (int) $ids[0]);
        if (!$combo) {
            return null;
        }
        return [
            'product_id' => (int) ($combo['id_product'] ?? 0),
            'combo_id' => (int) ($combo['id'] ?? 0),
        ];
    }

    // ----------------------------------------------------------------
    // INTERNAL HELPERS
    // ----------------------------------------------------------------

    private function getResourceIds(string $resource, array $params = []): array
    {
        $params['output_format'] = 'JSON';
        try {
            $resp = $this->http->get($resource, ['query' => $params]);
            $body = json_decode($resp->getBody()->getContents(), true);

            // PrestaShop returns e.g. {"products":[{"id":1},{"id":2}]}
            $key = $resource;
            if (isset($body[$key])) {
                return array_column($body[$key], 'id');
            }
        } catch (RequestException $e) {
            $this->logger->error("PS getResourceIds({$resource}) failed", [
                'error' => $e->getMessage(),
            ]);
        }
        return [];
    }

    private function get(string $path, array $extraQuery = []): ?array
    {
        $startedAt = microtime(true);
        try {
            $query = array_merge(['output_format' => 'JSON'], $extraQuery);
            $resp = $this->http->get($path, [
                'query' => $query,
            ]);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->info('PS API GET ok', [
                'path' => $path,
                'status' => $resp->getStatusCode(),
                'elapsed_ms' => $elapsedMs,
            ]);
            $body = json_decode($resp->getBody()->getContents(), true);

            // PS wraps single resources: {"product":{...}}
            // Return the inner object
            if (is_array($body)) {
                return reset($body) ?: null;
            }
        } catch (RequestException $e) {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->error("PS GET {$path} failed", [
                'error' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
            ]);
        }
        return null;
    }

    private function post(string $path, string $body, string $contentType = 'application/json'): ?array
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            $this->logger->info("PS POST {$path} skipped by safety mode", [
                'dry_run' => $this->config->isDryRun(),
                'test_mode' => $this->config->isTestMode(),
            ]);
            return ['skipped' => true, 'path' => $path];
        }

        $startedAt = microtime(true);
        try {
            $resp = $this->http->post($path, [
                'query'   => ['output_format' => 'JSON'],
                'headers' => ['Content-Type' => $contentType],
                'body'    => $body,
            ]);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->info('PS API POST ok', [
                'path' => $path,
                'status' => $resp->getStatusCode(),
                'elapsed_ms' => $elapsedMs,
            ]);
            return json_decode($resp->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $responseBody = null;
            if ($e->hasResponse()) {
                $responseBody = (string) $e->getResponse()->getBody();
            }
            $this->logger->error("PS POST {$path} failed", [
                'error' => $e->getMessage(),
                'response_body' => $responseBody,
                'elapsed_ms' => $elapsedMs,
            ]);
            return null;
        }
    }

    private function put(string $path, string $body, string $contentType = 'application/json'): ?array
    {
        if ($this->config->isDryRun() || $this->config->isTestMode()) {
            $this->logger->info("PS PUT {$path} skipped by safety mode", [
                'dry_run' => $this->config->isDryRun(),
                'test_mode' => $this->config->isTestMode(),
            ]);
            return ['skipped' => true, 'path' => $path];
        }

        $startedAt = microtime(true);
        try {
            $resp = $this->http->put($path, [
                'query' => ['output_format' => 'JSON'],
                'headers' => ['Content-Type' => $contentType],
                'body' => $body,
            ]);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logger->info('PS API PUT ok', [
                'path' => $path,
                'status' => $resp->getStatusCode(),
                'elapsed_ms' => $elapsedMs,
            ]);
            return json_decode($resp->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $responseBody = null;
            if ($e->hasResponse()) {
                $responseBody = (string) $e->getResponse()->getBody();
            }
            $this->logger->error("PS PUT {$path} failed", [
                'error' => $e->getMessage(),
                'response_body' => $responseBody,
                'elapsed_ms' => $elapsedMs,
            ]);
            return null;
        }
    }

    // ---- XML builders (PrestaShop Webservice requires XML for writes) ----

    private function orderArrayToXml(array $d): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
  <order>
    <id_customer><![CDATA[{$d['id_customer']}]]></id_customer>
    <id_address_delivery><![CDATA[{$d['id_address_delivery']}]]></id_address_delivery>
    <id_address_invoice><![CDATA[{$d['id_address_invoice']}]]></id_address_invoice>
    <id_cart><![CDATA[{$d['id_cart']}]]></id_cart>
    <id_currency><![CDATA[{$d['id_currency']}]]></id_currency>
    <id_lang><![CDATA[{$d['id_lang']}]]></id_lang>
    <id_shop><![CDATA[{$d['id_shop']}]]></id_shop>
    <id_carrier><![CDATA[{$d['id_carrier']}]]></id_carrier>
    <current_state><![CDATA[{$d['current_state']}]]></current_state>
    <payment><![CDATA[{$d['payment']}]]></payment>
    <module><![CDATA[{$d['module']}]]></module>
    <total_paid><![CDATA[{$d['total_paid']}]]></total_paid>
    <total_paid_real><![CDATA[{$d['total_paid_real']}]]></total_paid_real>
    <total_products><![CDATA[{$d['total_products']}]]></total_products>
    <total_products_wt><![CDATA[{$d['total_products_wt']}]]></total_products_wt>
    <total_shipping><![CDATA[{$d['total_shipping']}]]></total_shipping>
    <conversion_rate>1</conversion_rate>
    <reference><![CDATA[{$d['reference']}]]></reference>
  </order>
</prestashop>
XML;
    }

    private function buildOrderHistoryXml(int $orderId, int $statusId): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
  <order_history>
    <id_order><![CDATA[{$orderId}]]></id_order>
    <id_order_state><![CDATA[{$statusId}]]></id_order_state>
  </order_history>
</prestashop>
XML;
    }

    private function customerArrayToXml(array $d): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
  <customer>
    <firstname><![CDATA[{$d['firstname']}]]></firstname>
    <lastname><![CDATA[{$d['lastname']}]]></lastname>
    <email><![CDATA[{$d['email']}]]></email>
    <passwd><![CDATA[{$d['password']}]]></passwd>
    <id_default_group>3</id_default_group>
    <newsletter>0</newsletter>
    <optin>0</optin>
    <active>1</active>
  </customer>
</prestashop>
XML;
    }

    private function addressArrayToXml(int $customerId, array $d): string
    {
        $address2 = $d['address2'] ?? '';
        $phone = $d['phone'] ?? '';
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
  <address>
    <id_customer><![CDATA[{$customerId}]]></id_customer>
    <id_country><![CDATA[{$d['id_country']}]]></id_country>
    <alias><![CDATA[{$d['alias']}]]></alias>
    <firstname><![CDATA[{$d['firstname']}]]></firstname>
    <lastname><![CDATA[{$d['lastname']}]]></lastname>
    <address1><![CDATA[{$d['address1']}]]></address1>
    <address2><![CDATA[{$address2}]]></address2>
    <postcode><![CDATA[{$d['postcode']}]]></postcode>
    <city><![CDATA[{$d['city']}]]></city>
    <phone><![CDATA[{$phone}]]></phone>
  </address>
</prestashop>
XML;
    }

    /**
     * Read-only webservice check for admin dashboards.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $resp = $this->http->get('products', [
                'query' => [
                    'output_format' => 'JSON',
                    'limit' => 1,
                ],
            ]);
            $code = $resp->getStatusCode();
            if ($code >= 400) {
                return ['ok' => false, 'message' => 'HTTP ' . $code];
            }

            return ['ok' => true, 'message' => 'PrestaShop webservice responded OK'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Paginated product IDs (webservice limit = offset,count; newest id first).
     *
     * @return list<int>
     */
    public function listProductIds(int $offset, int $limit): array
    {
        $offset = max(0, $offset);
        $limit = max(1, min(100, $limit));
        $ids = $this->getResourceIds('products', [
            'limit' => $offset . ',' . $limit,
            'sort' => '[id_DESC]',
        ]);

        return array_map(static function ($id) {
            return (int) $id;
        }, $ids);
    }

    /**
     * Paginated order IDs (newest first).
     *
     * @return list<int>
     */
    public function listOrderIds(int $offset, int $limit): array
    {
        $offset = max(0, $offset);
        $limit = max(1, min(100, $limit));
        $ids = $this->getResourceIds('orders', [
            'limit' => $offset . ',' . $limit,
            'sort' => '[id_DESC]',
        ]);

        return array_map(static function ($id) {
            return (int) $id;
        }, $ids);
    }

    private function createCart(array $orderData, array $items): ?int
    {
        if (empty($items)) {
            $this->logger->error('Cannot create cart: no order items');
            return null;
        }
        $hasResolvableRows = false;
        foreach ($items as $item) {
            if ((int) ($item['product_id'] ?? 0) > 0) {
                $hasResolvableRows = true;
                break;
            }
        }
        if (!$hasResolvableRows) {
            $this->logger->error('Cannot create cart: order items do not contain resolvable product IDs');
            return null;
        }
        $xml = $this->cartArrayToXml($orderData, $items);
        $resp = $this->post('carts', $xml, 'application/xml');
        if ($resp && isset($resp['cart']['id'])) {
            return (int) $resp['cart']['id'];
        }
        return null;
    }

    private function cartArrayToXml(array $d, array $items): string
    {
        $rowsXml = '';
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $comboId = (int) ($item['combo_id'] ?? 0);
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            if ($productId <= 0) {
                continue;
            }
            $rowsXml .= '<cart_row>';
            $rowsXml .= '<id_product><![CDATA[' . $productId . ']]></id_product>';
            $rowsXml .= '<id_product_attribute><![CDATA[' . $comboId . ']]></id_product_attribute>';
            $rowsXml .= '<id_address_delivery><![CDATA[' . (int) $d['id_address_delivery'] . ']]></id_address_delivery>';
            $rowsXml .= '<quantity><![CDATA[' . $qty . ']]></quantity>';
            $rowsXml .= '</cart_row>';
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
  <cart>
    <id_currency><![CDATA[{$d['id_currency']}]]></id_currency>
    <id_lang><![CDATA[{$d['id_lang']}]]></id_lang>
    <id_address_delivery><![CDATA[{$d['id_address_delivery']}]]></id_address_delivery>
    <id_address_invoice><![CDATA[{$d['id_address_invoice']}]]></id_address_invoice>
    <id_carrier><![CDATA[{$d['id_carrier']}]]></id_carrier>
    <id_customer><![CDATA[{$d['id_customer']}]]></id_customer>
    <id_shop><![CDATA[{$d['id_shop']}]]></id_shop>
    <id_shop_group><![CDATA[1]]></id_shop_group>
    <associations>
      <cart_rows>
        {$rowsXml}
      </cart_rows>
    </associations>
  </cart>
</prestashop>
XML;
    }

    private function stockAvailableArrayToXml(array $row, int $quantity): string
    {
        $id = (int) ($row['id'] ?? 0);
        $productId = (int) ($row['id_product'] ?? 0);
        $comboId = (int) ($row['id_product_attribute'] ?? 0);
        $shopId = (int) ($row['id_shop'] ?? 1);
        $shopGroupId = (int) ($row['id_shop_group'] ?? 0);
        $dependsOnStock = (int) ($row['depends_on_stock'] ?? 0);
        $outOfStock = (int) ($row['out_of_stock'] ?? 0);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<prestashop xmlns:xlink="http://www.w3.org/1999/xlink">
  <stock_available>
    <id><![CDATA[{$id}]]></id>
    <id_product><![CDATA[{$productId}]]></id_product>
    <id_product_attribute><![CDATA[{$comboId}]]></id_product_attribute>
    <id_shop><![CDATA[{$shopId}]]></id_shop>
    <id_shop_group><![CDATA[{$shopGroupId}]]></id_shop_group>
    <quantity><![CDATA[{$quantity}]]></quantity>
    <depends_on_stock><![CDATA[{$dependsOnStock}]]></depends_on_stock>
    <out_of_stock><![CDATA[{$outOfStock}]]></out_of_stock>
  </stock_available>
</prestashop>
XML;
    }

    private function hydrateCombinationOptionValues(array $combo): array
    {
        $raw = $combo['associations']['product_option_values'] ?? null;
        if (!is_array($raw)) {
            return $combo;
        }

        $rows = $raw['product_option_value'] ?? $raw;
        if (!is_array($rows)) {
            return $combo;
        }
        if (isset($rows['id'])) {
            $rows = [$rows];
        }

        $hydrated = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                $hydrated[] = $row;
                continue;
            }

            $full = $this->getProductOptionValue($id);
            if (!is_array($full)) {
                $hydrated[] = $row;
                continue;
            }

            $optionGroupId = (int) ($full['id_attribute_group'] ?? 0);
            $optionGroup = $optionGroupId > 0 ? $this->getProductOption($optionGroupId) : null;
            $row['name'] = $this->readLangValue($full['name'] ?? '');
            $row['value'] = $row['name'];
            $row['attribute_group_id'] = $optionGroupId;
            $row['group_name'] = $this->readLangValue($optionGroup['name'] ?? '');
            $row['attribute_group_name'] = $row['group_name'];
            $hydrated[] = $row;
        }

        $combo['associations']['product_option_values'] = $hydrated;

        return $combo;
    }

    private function readLangValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value[0]['value'])) {
            $languageId = (int) ($_ENV['PS_LANGUAGE_ID'] ?? 1);
            foreach ($value as $entry) {
                if ((int) ($entry['id'] ?? 0) === $languageId) {
                    return (string) ($entry['value'] ?? '');
                }
            }
            return (string) ($value[0]['value'] ?? '');
        }

        return '';
    }
}
