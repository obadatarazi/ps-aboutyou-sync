<?php

declare(strict_types=1);

namespace Sync\Tests;

use PHPUnit\Framework\TestCase;
use Sync\Services\AyImageNormalizer;

class AyImageNormalizerTest extends TestCase
{
    public function testComputeCropWideImage(): void
    {
        // 2000×1000 — too wide; use full height, crop width
        [$sx, $sy, $cw, $ch] = AyImageNormalizer::computeCrop(2000, 1000);
        $this->assertSame(0, $sy);
        $this->assertSame(1000, $ch);
        $this->assertSame(750, $cw);
        $this->assertSame(625, $sx);
        $this->assertEqualsWithDelta(750 / 1000, 3 / 4, 0.0001);
    }

    public function testComputeCropTallImage(): void
    {
        // 1000×2000 — too tall; use full width, crop height
        [$sx, $sy, $cw, $ch] = AyImageNormalizer::computeCrop(1000, 2000);
        $this->assertSame(0, $sx);
        $this->assertSame(1000, $cw);
        $this->assertSame(1333, $ch);
        $this->assertSame(333, $sy);
        $this->assertEqualsWithDelta(1000 / 1333, 3 / 4, 0.002);
    }

    public function testComputeCropAlreadyThreeFour(): void
    {
        [$sx, $sy, $cw, $ch] = AyImageNormalizer::computeCrop(1125, 1500);
        $this->assertSame([0, 0, 1125, 1500], [$sx, $sy, $cw, $ch]);
    }

    public function testComputeOutputSizeMeetsMinimumAndRatio(): void
    {
        [$tw, $th] = AyImageNormalizer::computeOutputSize(100, 133);
        $this->assertGreaterThanOrEqual(AyImageNormalizer::MIN_WIDTH, $tw);
        $this->assertGreaterThanOrEqual(AyImageNormalizer::MIN_HEIGHT, $th);
        $this->assertEqualsWithDelta($tw / $th, 3 / 4, 0.0001);
    }

    public function testComputeOutputSizeLargeSource(): void
    {
        [$tw, $th] = AyImageNormalizer::computeOutputSize(3000, 4000);
        $this->assertSame(1125, $tw);
        $this->assertSame(1500, $th);
    }

    public function testRedactUrlForLogStripsQuery(): void
    {
        $u = 'https://shop.example/api/images/products/1/2?ws_key=secret&x=1';
        $this->assertSame('https://shop.example/api/images/products/1/2', AyImageNormalizer::redactUrlForLog($u));
    }
}
