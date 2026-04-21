<?php

declare(strict_types=1);

namespace Sync\Tests;

use PHPUnit\Framework\TestCase;
use Sync\Sync\TextileMaterialResolver;

class TextileMaterialResolverTest extends TestCase
{
    public function testParsePercentFirst(): void
    {
        $p = TextileMaterialResolver::parseCompositionText('70% Cotton, 30% Polyester');
        $this->assertCount(2, $p);
        $this->assertSame('Cotton', $p[0]['label']);
        $this->assertSame(70, $p[0]['fraction']);
        $this->assertSame('Polyester', $p[1]['label']);
        $this->assertSame(30, $p[1]['fraction']);
    }

    public function testParseEqualsSyntax(): void
    {
        $p = TextileMaterialResolver::parseCompositionText('Cotton=70; Polyester=30');
        $this->assertCount(2, $p);
        $this->assertSame('Cotton', $p[0]['label']);
        $this->assertSame(70, $p[0]['fraction']);
        $this->assertSame(30, $p[1]['fraction']);
    }

    public function testParseSingleMaterialFallback100(): void
    {
        $p = TextileMaterialResolver::parseCompositionText('Organic cotton');
        $this->assertCount(1, $p);
        $this->assertSame(100, $p[0]['fraction']);
    }

    public function testNormalizeFractions(): void
    {
        $n = TextileMaterialResolver::normalizeFractionsTo100([
            ['material_id' => 1, 'fraction' => 33],
            ['material_id' => 2, 'fraction' => 33],
            ['material_id' => 3, 'fraction' => 33],
        ]);
        $this->assertCount(3, $n);
        $this->assertSame(100, array_sum(array_column($n, 'fraction')));
    }

    public function testDataMapperIncludesMaterialWhenProvided(): void
    {
        $_ENV['PS_LANGUAGE_ID'] = '1';
        $_ENV['AY_BRAND_ID'] = '1';
        $_ENV['AY_CATEGORY_ID'] = '2';
        $_ENV['PS_SHOP_ID'] = '1';
        $mapper = new \Sync\Sync\DataMapper();
        $comp = [
            [
                'cluster_id' => 164588,
                'components' => [
                    ['material_id' => 293177, 'fraction' => 100],
                ],
            ],
        ];
        $r = $mapper->mapProductToAy(
            [
                'id' => 1,
                'reference' => 'R',
                'price' => '1',
                'name' => [['id' => '1', 'value' => 'T']],
                'description' => [],
                'description_short' => [],
            ],
            [],
            [],
            'Cat',
            $comp
        );
        $this->assertSame($comp, $r['variants'][0]['material_composition_textile']);
    }
}
