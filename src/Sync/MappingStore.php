<?php

namespace Sync\Sync;

use Sync\Config\AppConfig;

/**
 * MappingStore
 *
 * Persists ID mappings between PrestaShop and AboutYou in a JSON file.
 * In production, replace with a database (PDO/MySQL) for better performance.
 *
 * Stores:
 *   products:  PS product ID  ↔ AY style_key + SKUs
 *   orders:    AY order ID    ↔ PS order ID
 *   processed: AY order IDs already imported (dedup set)
 */
class MappingStore
{
    private string $filePath;
    private AppConfig $config;
    private array $data = [
        'products'  => [],  // ['ps_id' => ['ay_style_key' => ..., 'skus' => [...]]]
        'orders'    => [],  // ['ay_order_id' => ps_order_id]
        'processed' => [],  // ['ay_order_id' => true]
        'failed_products' => [], // ['ps_id' => ['attempts' => n, 'reason' => ..., ...]]
        'failed_orders' => [], // ['ay_order_id' => ['attempts' => n, 'reason' => ..., ...]]
        'last_sync' => [],  // ['products' => ISO8601, 'orders' => ISO8601, 'stock' => ISO8601]
    ];

    public function __construct()
    {
        $this->config = new AppConfig();
        $this->filePath = $_ENV['SYNC_LAST_SYNC_FILE'] ?? __DIR__ . '/../../logs/mapping.json';
        $this->load();
    }

    // ----------------------------------------------------------------
    // PRODUCT MAPPINGS
    // ----------------------------------------------------------------

    public function saveProductMapping(int $psId, string $ayStyleKey, array $skus = [], array $skuMap = []): void
    {
        $normalizedSkus = $this->normalizeSkus($skus);
        if (empty($skuMap) && !empty($normalizedSkus)) {
            $skuMap = [0 => $normalizedSkus[0]];
        }
        $this->data['products'][$psId] = [
            'ay_style_key' => $ayStyleKey,
            'skus'         => $normalizedSkus,
            'sku_map'      => $skuMap,
            'synced_at'    => date('c'),
        ];
        unset($this->data['failed_products'][$psId]);
        $this->persist();
    }

    public function getProductMapping(int $psId): ?array
    {
        return $this->data['products'][$psId] ?? null;
    }

    public function getAyStyleKey(int $psId): ?string
    {
        return $this->data['products'][$psId]['ay_style_key'] ?? null;
    }

    public function productMappingExists(int $psId): bool
    {
        return isset($this->data['products'][$psId]);
    }

    public function markProductFailure(
        int $psId,
        string $reason,
        bool $permanent = false,
        int $maxAttempts = 3
    ): array {
        $current = $this->data['failed_products'][$psId] ?? [];
        $attempts = (int) ($current['attempts'] ?? 0) + 1;
        $quarantined = $permanent || $attempts >= max(1, $maxAttempts);
        $record = [
            'attempts' => $attempts,
            'reason' => $reason,
            'permanent' => $permanent,
            'quarantined' => $quarantined,
            'last_failed_at' => date('c'),
        ];
        $this->data['failed_products'][$psId] = $record;
        $this->persist();
        return $record;
    }

    public function getProductFailure(int $psId): ?array
    {
        $record = $this->data['failed_products'][$psId] ?? null;
        return is_array($record) ? $record : null;
    }

    public function clearProductFailure(int $psId): void
    {
        unset($this->data['failed_products'][$psId]);
        $this->persist();
    }

    public function getFailedProducts(): array
    {
        return $this->data['failed_products'];
    }

    // ----------------------------------------------------------------
    // ORDER MAPPINGS
    // ----------------------------------------------------------------

    public function saveOrderMapping(string $ayOrderId, int $psOrderId): void
    {
        $this->data['orders'][$ayOrderId]    = $psOrderId;
        $this->data['processed'][$ayOrderId] = true;
        unset($this->data['failed_orders'][$ayOrderId]);
        $this->persist();
    }

    public function getPsOrderId(string $ayOrderId): ?int
    {
        return isset($this->data['orders'][$ayOrderId])
            ? (int) $this->data['orders'][$ayOrderId]
            : null;
    }

    public function getAyOrderId(int $psOrderId): ?string
    {
        $flipped = array_flip($this->data['orders']);
        return $flipped[$psOrderId] ?? null;
    }

    public function isOrderProcessed(string $ayOrderId): bool
    {
        return !empty($this->data['processed'][$ayOrderId]);
    }

    public function markOrderFailure(
        string $ayOrderId,
        string $reason,
        bool $permanent = false,
        int $maxAttempts = 3
    ): array {
        $current = $this->data['failed_orders'][$ayOrderId] ?? [];
        $attempts = (int) ($current['attempts'] ?? 0) + 1;
        $quarantined = $permanent || $attempts >= max(1, $maxAttempts);
        $record = [
            'attempts' => $attempts,
            'reason' => $reason,
            'permanent' => $permanent,
            'quarantined' => $quarantined,
            'last_failed_at' => date('c'),
        ];
        $this->data['failed_orders'][$ayOrderId] = $record;
        $this->persist();
        return $record;
    }

    public function getOrderFailure(string $ayOrderId): ?array
    {
        $record = $this->data['failed_orders'][$ayOrderId] ?? null;
        return is_array($record) ? $record : null;
    }

    public function clearOrderFailure(string $ayOrderId): void
    {
        unset($this->data['failed_orders'][$ayOrderId]);
        $this->persist();
    }

    public function getFailedOrders(): array
    {
        return $this->data['failed_orders'];
    }

    // ----------------------------------------------------------------
    // LAST SYNC TIMESTAMPS (for incremental sync)
    // ----------------------------------------------------------------

    public function setLastSync(string $type, string $isoDate): void
    {
        $this->data['last_sync'][$type] = $isoDate;
        $this->persist();
    }

    public function getLastSync(string $type): ?string
    {
        return $this->data['last_sync'][$type] ?? null;
    }

    // ----------------------------------------------------------------
    // PERSISTENCE
    // ----------------------------------------------------------------

    private function load(): void
    {
        if (file_exists($this->filePath)) {
            $raw = file_get_contents($this->filePath);
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->data = array_merge($this->data, $decoded);
                }
            }
        }
    }

    private function persist(): void
    {
        if ($this->config->isDryRun()) {
            return;
        }
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->filePath,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    public function getAllProductMappings(): array
    {
        return $this->data['products'];
    }

    public function resolvePsItemBySku(string $sku): ?array
    {
        $sku = strtoupper(trim($sku));
        if ($sku === '') {
            return null;
        }

        foreach ($this->data['products'] as $psId => $mapping) {
            $skus = array_map(
                static fn($mappedSku) => strtoupper(trim((string) $mappedSku)),
                (array) ($mapping['skus'] ?? [])
            );
            if (!in_array($sku, $skus, true)) {
                continue;
            }

            $comboId = 0;
            $skuMap = $mapping['sku_map'] ?? [];
            foreach ($skuMap as $mappedComboId => $mappedSku) {
                if (strtoupper(trim((string) $mappedSku)) === $sku) {
                    $comboId = (int) $mappedComboId;
                    break;
                }
            }
            if ($comboId === 0 && preg_match('/^PS-(\d+)-(\d+)$/', $sku, $m) === 1) {
                $comboId = (int) $m[2];
            }
            return [
                'product_id' => (int) $psId,
                'combo_id' => $comboId,
            ];
        }

        return null;
    }

    public function getAllOrderMappings(): array
    {
        return $this->data['orders'];
    }

    public function getStats(): array
    {
        return [
            'products_mapped' => count($this->data['products']),
            'failed_products' => count($this->data['failed_products']),
            'orders_mapped'   => count($this->data['orders']),
            'failed_orders'   => count($this->data['failed_orders']),
            'last_sync'       => $this->data['last_sync'],
        ];
    }

    public function cleanupProductMappings(): array
    {
        $stats = [
            'products_checked' => 0,
            'products_changed' => 0,
            'empty_skus_removed' => 0,
        ];
        foreach ($this->data['products'] as $psId => $mapping) {
            $stats['products_checked']++;
            $before = $mapping['skus'] ?? [];
            $normalized = $this->normalizeSkus((array) $before);
            $removed = count($before) - count($normalized);
            if ($removed > 0) {
                $stats['empty_skus_removed'] += $removed;
            }
            $skuMap = [];
            foreach ($normalized as $candidate) {
                $comboId = 0;
                if (preg_match('/^PS-(\d+)-(\d+)$/', $candidate, $m) === 1) {
                    $comboId = (int) $m[2];
                } elseif (!isset($skuMap[0])) {
                    $comboId = 0;
                }
                $skuMap[$comboId] = $candidate;
            }
            if ($normalized !== $before || ($mapping['sku_map'] ?? []) !== $skuMap) {
                $this->data['products'][$psId]['skus'] = $normalized;
                $this->data['products'][$psId]['sku_map'] = $skuMap;
                $stats['products_changed']++;
            }
        }
        if ($stats['products_changed'] > 0) {
            $this->persist();
        }
        return $stats;
    }

    private function normalizeSkus(array $skus): array
    {
        $normalized = [];
        foreach ($skus as $sku) {
            $candidate = trim((string) $sku);
            if ($candidate === '') {
                continue;
            }
            if (in_array($candidate, $normalized, true)) {
                continue;
            }
            $normalized[] = $candidate;
        }
        return $normalized;
    }
}
