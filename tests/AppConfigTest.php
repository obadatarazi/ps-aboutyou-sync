<?php

namespace Sync\Tests;

use PHPUnit\Framework\TestCase;
use Sync\Config\AppConfig;

class AppConfigTest extends TestCase
{
    public function testBooleanConfigFlags(): void
    {
        $_ENV['TEST_MODE'] = 'true';
        $_ENV['DRY_RUN'] = 'false';
        $_ENV['UI_ENABLED'] = 'true';

        $config = new AppConfig();
        $this->assertTrue($config->isTestMode());
        $this->assertFalse($config->isDryRun());
        $this->assertTrue($config->uiEnabled());
    }
}
