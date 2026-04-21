<?php

namespace Sync\Tests;

use PHPUnit\Framework\TestCase;
use Sync\Sync\MappingStore;

class MappingStoreTest extends TestCase
{
    private string $tmpFile;
    private MappingStore $store;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/sync_test_mapping_' . uniqid() . '.json';
        $_ENV['SYNC_LAST_SYNC_FILE'] = $this->tmpFile;
        $_ENV['DRY_RUN'] = 'false';
        $this->store = new MappingStore();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testProductMappingSaveAndLoad(): void
    {
        $this->store->saveProductMapping(42, 'PS-42', ['SKU-A', 'SKU-B']);
        $this->assertTrue($this->store->productMappingExists(42));
        $this->assertEquals('PS-42', $this->store->getAyStyleKey(42));
        $this->assertFalse($this->store->productMappingExists(999));
    }

    public function testOrderMappingSaveAndLoad(): void
    {
        $this->store->saveOrderMapping('AY-123', 456);
        $this->assertTrue($this->store->isOrderProcessed('AY-123'));
        $this->assertEquals(456, $this->store->getPsOrderId('AY-123'));
        $this->assertEquals('AY-123', $this->store->getAyOrderId(456));
        $this->assertFalse($this->store->isOrderProcessed('AY-999'));
    }

    public function testLastSyncTimestamp(): void
    {
        $ts = '2024-01-15 10:30:00';
        $this->store->setLastSync('products', $ts);
        $this->assertEquals($ts, $this->store->getLastSync('products'));
        $this->assertNull($this->store->getLastSync('orders'));
    }

    public function testStatsRetrieval(): void
    {
        $this->store->saveProductMapping(1, 'PS-1', ['S1']);
        $this->store->saveProductMapping(2, 'PS-2', ['S2']);
        $this->store->saveOrderMapping('AY-1', 100);
        $this->store->markProductFailure(3, 'push failed', false, 2);

        $stats = $this->store->getStats();
        $this->assertEquals(2, $stats['products_mapped']);
        $this->assertEquals(1, $stats['failed_products']);
        $this->assertEquals(1, $stats['orders_mapped']);
    }

    public function testResolvePsItemBySkuIsCaseInsensitive(): void
    {
        $this->store->saveProductMapping(20, 'PS-20', ['AbC-20'], [0 => 'AbC-20']);
        $resolved = $this->store->resolvePsItemBySku('abc-20');
        $this->assertNotNull($resolved);
        $this->assertEquals(20, $resolved['product_id']);
        $this->assertEquals(0, $resolved['combo_id']);
    }

    public function testOrderFailureTrackingAndQuarantine(): void
    {
        $record1 = $this->store->markOrderFailure('AY-FAIL-1', 'bad payload', false, 2);
        $this->assertEquals(1, $record1['attempts']);
        $this->assertFalse($record1['quarantined']);

        $record2 = $this->store->markOrderFailure('AY-FAIL-1', 'still bad', false, 2);
        $this->assertEquals(2, $record2['attempts']);
        $this->assertTrue($record2['quarantined']);
    }

    public function testCleanupProductMappingsRemovesEmptySkus(): void
    {
        $this->store->saveProductMapping(33, 'PS-33', ['', 'SKU-33', 'SKU-33'], []);
        $report = $this->store->cleanupProductMappings();
        $mapping = $this->store->getProductMapping(33);

        $this->assertEquals(1, $report['products_checked']);
        $this->assertEquals(['SKU-33'], $mapping['skus']);
    }

    public function testProductFailureTrackingAndClearOnSuccessfulMapping(): void
    {
        $record1 = $this->store->markProductFailure(55, 'AY push failed', false, 2);
        $this->assertEquals(1, $record1['attempts']);
        $this->assertFalse($record1['quarantined']);

        $record2 = $this->store->markProductFailure(55, 'still failing', false, 2);
        $this->assertEquals(2, $record2['attempts']);
        $this->assertTrue($record2['quarantined']);
        $this->assertNotNull($this->store->getProductFailure(55));

        $this->store->saveProductMapping(55, 'PS-55', ['SKU-55']);
        $this->assertNull($this->store->getProductFailure(55));
    }
}
