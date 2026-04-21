<?php

namespace Sync\Sync;

use Sync\PrestaShop\PsApiClient;
use Sync\AboutYou\AyApiClient;
use Sync\Config\AppConfig;
use Sync\Logger\SyncLogger;

/**
 * OrderSync
 *
 * Handles:
 *  1) AboutYou → PrestaShop: Import new AY orders into PS.
 *  2) PrestaShop → AboutYou: Push PS order status changes to AY.
 *
 * RULE: Orders originate in AboutYou but PS is authoritative for status.
 */
class OrderSync
{
    private PsApiClient  $ps;
    private AyApiClient  $ay;
    private DataMapper   $mapper;
    private MappingStore $store;
    private SyncLogger   $logger;
    private int $maxOrderAttempts;

    private array $stats = [
        'ay_orders_fetched'   => 0,
        'orders_imported'     => 0,
        'orders_skipped'      => 0,
        'orders_failed'       => 0,
        'orders_quarantined'  => 0,
        'statuses_pushed'     => 0,
        'status_push_failed'  => 0,
    ];

    public function __construct(
        PsApiClient  $ps,
        AyApiClient  $ay,
        DataMapper   $mapper,
        MappingStore $store,
        SyncLogger   $logger,
        ?AppConfig   $config = null
    ) {
        $this->ps     = $ps;
        $this->ay     = $ay;
        $this->mapper = $mapper;
        $this->store  = $store;
        $this->logger = $logger;
        $this->maxOrderAttempts = (int) ($_ENV['ORDER_IMPORT_MAX_ATTEMPTS'] ?? 3);
    }

    // ----------------------------------------------------------------
    // 1) AboutYou → PrestaShop: Import new orders
    // ----------------------------------------------------------------

    public function importNewOrders(): array
    {
        $this->resetStats();
        $this->logger->info('OrderSync::importNewOrders started');

        $ayOrders = $this->ay->getNewOrders();
        $this->stats['ay_orders_fetched'] = count($ayOrders);

        $this->logger->info("Fetched {$this->stats['ay_orders_fetched']} new orders from AboutYou");

        foreach ($ayOrders as $ayOrder) {
            $this->importSingleOrder($ayOrder);
        }

        $this->store->setLastSync('orders', date('Y-m-d H:i:s'));
        $this->logger->info('OrderSync::importNewOrders completed', $this->stats);

        return $this->stats;
    }

    private function importSingleOrder(array $ayOrder): void
    {
        $ayOrderId = (string) ($ayOrder['id'] ?? $ayOrder['order_id'] ?? $ayOrder['order_number'] ?? '');
        if (empty($ayOrder)) {
            $this->stats['orders_skipped']++;
            $this->logger->warning('Skipped empty AY order payload');
            return;
        }

        // DEDUP check — never import the same order twice
        if ($this->store->isOrderProcessed($ayOrderId)) {
            $this->stats['orders_skipped']++;
            $this->logger->debug("Order AY#{$ayOrderId} already imported — skipping");
            return;
        }
        $failure = $this->store->getOrderFailure($ayOrderId);
        if (($failure['quarantined'] ?? false) === true) {
            $this->stats['orders_quarantined']++;
            $this->logger->warning("Order AY#{$ayOrderId} is quarantined — skipping", [
                'failure' => $failure,
            ]);
            return;
        }

        try {
            // Map AY order to PS structure
            $mapped = $this->mapper->mapAyOrderToPs($ayOrder);
            if (!$ayOrderId) {
                $ayOrderId = (string) ($mapped['ay_order_id'] ?? '');
            }
            $preflightErrors = $this->validateMappedOrder($mapped);
            if (!empty($preflightErrors)) {
                throw new \InvalidArgumentException(
                    'Order preflight validation failed: ' . implode('; ', $preflightErrors)
                );
            }

            // 1. Find or create customer in PS
            $psCustomerId = $this->ps->findOrCreateCustomer($mapped['customer']);
            if (!$psCustomerId) {
                throw new \RuntimeException("Could not find/create customer for AY#{$ayOrderId}");
            }

            // 2. Create delivery address
            $psAddressId = $this->ps->findOrCreateAddress($psCustomerId, $mapped['address']);
            if (!$psAddressId) {
                throw new \RuntimeException("Could not create address for AY#{$ayOrderId}");
            }

            // 3. Build PS order payload
            $resolvedItems = $this->resolveOrderItems($mapped['items'] ?? []);
            if (empty($resolvedItems)) {
                throw new \RuntimeException("Could not resolve AY items to PS product references for AY#{$ayOrderId}");
            }
            $stockBefore = $this->snapshotStockLevels($resolvedItems);
            $orderPayload = array_merge($mapped, [
                'id_customer'          => $psCustomerId,
                'id_address_delivery'  => $psAddressId,
                'id_address_invoice'   => $psAddressId,
                'id_cart'              => 0, // created in PsApiClient::createOrder
                'items'                => $resolvedItems,
            ]);

            // 4. Create order in PS
            $psOrderId = $this->ps->createOrder($orderPayload);
            if (!$psOrderId) {
                throw new \RuntimeException("Failed to create PS order for AY#{$ayOrderId}");
            }

            // 5. Save mapping
            $this->store->saveOrderMapping($ayOrderId, $psOrderId);
            $this->store->clearOrderFailure($ayOrderId);
            $this->stats['orders_imported']++;

            $this->logger->info("Order imported AY#{$ayOrderId} → PS#{$psOrderId}");
            $this->ensurePrestashopStockUpdated($ayOrderId, $resolvedItems, $stockBefore);

            // 6. Acknowledge the order in AboutYou (mark as processing)
            $this->ay->updateOrderStatus($ayOrderId, 'processing');

        } catch (\Throwable $e) {
            $this->stats['orders_failed']++;
            $isPermanent = $this->isPermanentOrderFailure($e);
            $failureRecord = $this->store->markOrderFailure(
                $ayOrderId,
                $e->getMessage(),
                $isPermanent,
                $this->maxOrderAttempts
            );
            if (($failureRecord['quarantined'] ?? false) === true) {
                $this->stats['orders_quarantined']++;
            }
            $this->logger->error("Failed to import AY order #{$ayOrderId}", [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'permanent' => $isPermanent,
                'failure' => $failureRecord,
            ]);
        }
    }

    // ----------------------------------------------------------------
    // 2) PrestaShop → AboutYou: Push order status updates
    // ----------------------------------------------------------------

    /**
     * Find PS orders with status changes and push them to AboutYou.
     */
    public function pushOrderStatusUpdates(): array
    {
        $this->resetStats();
        $this->logger->info('OrderSync::pushOrderStatusUpdates started');

        $lastSync = $this->store->getLastSync('order_status') ?? date('Y-m-d H:i:s', strtotime('-1 hour'));
        $psOrders = $this->ps->getOrdersModifiedSince($lastSync);

        foreach ($psOrders as $psOrder) {
            $this->pushSingleOrderStatus($psOrder);
        }

        if ($this->stats['status_push_failed'] === 0) {
            $this->store->setLastSync('order_status', date('Y-m-d H:i:s'));
        } else {
            $this->logger->warning('Order status sync has failures; last_sync watermark not advanced', [
                'status_push_failed' => $this->stats['status_push_failed'],
            ]);
        }
        $this->logger->info('OrderSync::pushOrderStatusUpdates completed', $this->stats);

        return $this->stats;
    }

    /**
     * Push current PrestaShop order state to AboutYou for specific PS order IDs
     * (only orders that have an AY mapping are processed).
     *
     * @param list<int> $psOrderIds
     */
    public function pushOrderStatusForPsOrderIds(array $psOrderIds): array
    {
        $this->resetStats();
        $this->logger->info('OrderSync::pushOrderStatusForPsOrderIds started', ['count' => count($psOrderIds)]);

        foreach ($psOrderIds as $psOrderId) {
            $psOrderId = (int) $psOrderId;
            if ($psOrderId <= 0) {
                continue;
            }
            $psOrder = $this->ps->getOrder($psOrderId);
            if ($psOrder !== null) {
                $this->pushSingleOrderStatus($psOrder);
            }
        }

        $this->logger->info('OrderSync::pushOrderStatusForPsOrderIds completed', $this->stats);

        return $this->stats;
    }

    private function pushSingleOrderStatus(array $psOrder): void
    {
        $psOrderId = (int) ($psOrder['id'] ?? 0);
        $ayOrderId = $this->store->getAyOrderId($psOrderId);

        if (!$ayOrderId) {
            // Not an AY-imported order — skip
            return;
        }

        $psStateId = (int) ($psOrder['current_state'] ?? 0);
        $ayStatus  = $this->mapper->mapPsStatusToAy($psStateId);

        $extra = [];
        // If shipped, try to pass tracking number
        if ($ayStatus === 'shipped' && !empty($psOrder['shipping_number'])) {
            $extra['tracking_number'] = $psOrder['shipping_number'];
        }

        $success = $this->ay->updateOrderStatus($ayOrderId, $ayStatus, $extra);

        if ($success) {
            $this->stats['statuses_pushed']++;
            $this->logger->info("Status pushed PS#{$psOrderId} → AY#{$ayOrderId}: {$ayStatus}");
        } else {
            $this->stats['status_push_failed']++;
            $this->logger->error("Status push failed PS#{$psOrderId} → AY#{$ayOrderId}", [
                'ps_order_id' => $psOrderId,
                'ay_order_id' => $ayOrderId,
                'target_status' => $ayStatus,
            ]);
        }
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    private function resetStats(): void
    {
        $this->stats = [
            'ay_orders_fetched' => 0,
            'orders_imported' => 0,
            'orders_skipped' => 0,
            'orders_failed' => 0,
            'orders_quarantined' => 0,
            'statuses_pushed' => 0,
            'status_push_failed' => 0,
        ];
    }

    private function resolveOrderItems(array $items): array
    {
        $resolved = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $comboId = (int) ($item['combo_id'] ?? 0);
            $sku = (string) ($item['sku'] ?? '');

            if ($productId > 0) {
                $resolved[] = [
                    'product_id' => $productId,
                    'combo_id' => $comboId,
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ];
                continue;
            }

            $fromMap = $this->store->resolvePsItemBySku($sku);
            if ($fromMap) {
                $resolved[] = [
                    'product_id' => (int) $fromMap['product_id'],
                    'combo_id' => (int) $fromMap['combo_id'],
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ];
                continue;
            }

            $combo = $this->ps->findCombinationByReference($sku);
            if ($combo && (int) $combo['product_id'] > 0) {
                $resolved[] = [
                    'product_id' => (int) $combo['product_id'],
                    'combo_id' => (int) $combo['combo_id'],
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ];
                continue;
            }

            $product = $this->ps->findProductIdByReference($sku);
            if ($product && $product > 0) {
                $resolved[] = [
                    'product_id' => $product,
                    'combo_id' => 0,
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ];
                continue;
            }

            // Some AY order item SKUs are EANs instead of PS references.
            $comboByEan = $this->ps->findCombinationByEan($sku);
            if ($comboByEan && (int) $comboByEan['product_id'] > 0) {
                $resolved[] = [
                    'product_id' => (int) $comboByEan['product_id'],
                    'combo_id' => (int) $comboByEan['combo_id'],
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ];
                continue;
            }

            $productByEan = $this->ps->findProductIdByEan($sku);
            if ($productByEan && $productByEan > 0) {
                $resolved[] = [
                    'product_id' => $productByEan,
                    'combo_id' => 0,
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ];
                continue;
            }

            $this->logger->warning('Could not resolve AY item SKU to PS product', ['sku' => $sku]);
        }

        return $resolved;
    }

    private function validateMappedOrder(array $mapped): array
    {
        $errors = [];
        $customer = $mapped['customer'] ?? [];
        $address = $mapped['address'] ?? [];
        $items = $mapped['items'] ?? [];

        $email = trim((string) ($customer['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'customer.email missing or invalid';
        }
        if (trim((string) ($customer['lastname'] ?? '')) === '') {
            $errors[] = 'customer.lastname missing';
        }
        if (trim((string) ($address['address1'] ?? '')) === '') {
            $errors[] = 'address.address1 missing';
        }
        if (trim((string) ($address['lastname'] ?? '')) === '') {
            $errors[] = 'address.lastname missing';
        }
        if (!is_array($items) || empty($items)) {
            $errors[] = 'order items missing';
        }

        return $errors;
    }

    private function snapshotStockLevels(array $resolvedItems): array
    {
        $snapshot = [];
        foreach ($resolvedItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $comboId = (int) ($item['combo_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $stock = $this->ps->getStockAvailable($productId, $comboId > 0 ? $comboId : null);
            $snapshot[$productId . ':' . $comboId] = [
                'product_id' => $productId,
                'combo_id' => $comboId,
                'quantity' => is_array($stock) ? (int) ($stock['quantity'] ?? 0) : null,
            ];
        }

        return $snapshot;
    }

    private function ensurePrestashopStockUpdated(string $ayOrderId, array $resolvedItems, array $stockBefore): void
    {
        foreach ($resolvedItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $comboId = (int) ($item['combo_id'] ?? 0);
            $orderedQty = max(1, (int) ($item['quantity'] ?? 1));
            if ($productId <= 0) {
                continue;
            }
            $key = $productId . ':' . $comboId;
            $beforeQty = $stockBefore[$key]['quantity'] ?? null;
            if (!is_int($beforeQty)) {
                continue;
            }

            $after = $this->ps->getStockAvailable($productId, $comboId > 0 ? $comboId : null);
            $afterQty = is_array($after) ? (int) ($after['quantity'] ?? 0) : null;
            if (!is_int($afterQty)) {
                continue;
            }
            if ($afterQty <= $beforeQty - $orderedQty) {
                continue;
            }

            $targetQty = max(0, $beforeQty - $orderedQty);
            $updated = $this->ps->updateStockAvailableQuantity($productId, $comboId > 0 ? $comboId : null, $targetQty);
            if ($updated) {
                $this->logger->warning('Adjusted PrestaShop stock after order import because quantity was not reduced automatically', [
                    'ay_order_id' => $ayOrderId,
                    'product_id' => $productId,
                    'combo_id' => $comboId,
                    'before_quantity' => $beforeQty,
                    'after_quantity' => $afterQty,
                    'target_quantity' => $targetQty,
                    'ordered_quantity' => $orderedQty,
                ]);
            } else {
                $this->logger->error('Failed to adjust PrestaShop stock after order import', [
                    'ay_order_id' => $ayOrderId,
                    'product_id' => $productId,
                    'combo_id' => $comboId,
                    'before_quantity' => $beforeQty,
                    'after_quantity' => $afterQty,
                    'ordered_quantity' => $orderedQty,
                ]);
            }
        }
    }

    private function isPermanentOrderFailure(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        $permanentHints = [
            'validation error',
            'missing or invalid',
            'malformed',
            'could not resolve ay items',
            'could not find/create customer',
            'could not create address',
        ];
        foreach ($permanentHints as $hint) {
            if (str_contains($message, $hint)) {
                return true;
            }
        }
        return false;
    }
}
