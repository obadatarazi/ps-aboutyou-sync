<?php

namespace Sync\Tests;

use PHPUnit\Framework\TestCase;
use Sync\Sync\MappingStore;

class MappingStoreDryRunTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/sync_test_mapping_dry_' . uniqid() . '.json';
        $_ENV['SYNC_LAST_SYNC_FILE'] = $this->tmpFile;
        $_ENV['DRY_RUN'] = 'true';
    }

    protected function tearDown(): void
    {
        $_ENV['DRY_RUN'] = 'false';
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testDoesNotPersistInDryRun(): void
    {
        $store = new MappingStore();
        $store->saveProductMapping(11, 'PS-11', ['SKU-11']);
        $this->assertFalse(file_exists($this->tmpFile));
    }
}
