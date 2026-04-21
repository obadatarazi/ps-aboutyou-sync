<?php

namespace Sync\Tests;

use PHPUnit\Framework\TestCase;
use Sync\Sync\DataMapper;

class DataMapperTest extends TestCase
{
    private DataMapper $mapper;

    protected function setUp(): void
    {
        $_ENV['PS_LANGUAGE_ID'] = '1';
        $_ENV['AY_BRAND_ID'] = '7';
        $_ENV['AY_CATEGORY_ID'] = '11';
        $_ENV['PS_SHOP_ID'] = '1';
        $_ENV['AY_DESCRIPTION_LOCALE'] = 'en';
        $_ENV['AY_COLOR_MAP'] = json_encode(['blue' => 101, 'black' => 102]);
        $_ENV['AY_SIZE_MAP'] = json_encode(['s' => 201, 'm' => 202, 'one size' => 299]);
        $_ENV['AY_ATTRIBUTE_MAP'] = json_encode(['material:cotton' => 301]);
        $this->mapper = new DataMapper();
    }

    public function testSimpleProductMapping(): void
    {
        $psProduct = [
            'id' => 42,
            'reference' => 'SHIRT-001',
            'price' => '29.99',
            'ean13' => '1234567890123',
            'quantity' => '10',
            'id_category_default' => '3',
            'name' => [['id' => '1', 'value' => 'Cool T-Shirt']],
            'description' => [['id' => '1', 'value' => 'A very cool t-shirt.']],
            'description_short' => [['id' => '1', 'value' => 'Cool shirt']],
        ];

        $result = $this->mapper->mapProductToAy($psProduct, [], [], 'Shirts');

        $this->assertEquals('PS-42', $result['style_key']);
        $this->assertEquals('Cool T-Shirt', $result['variants'][0]['name']);
        $this->assertEquals('A very cool t-shirt.', $result['variants'][0]['descriptions']['en']);
        $this->assertCount(1, $result['variants']);
        $this->assertEquals('SHIRT-001', $result['variants'][0]['sku']);
        $this->assertEquals(2999, $result['variants'][0]['prices'][0]['retail_price']);
        $this->assertEquals(10, $result['variants'][0]['quantity']);
        $this->assertEquals('1234567890123', $result['variants'][0]['ean']);
        $this->assertEquals(7, $result['variants'][0]['brand']);
        $this->assertEquals(11, $result['variants'][0]['category']);
        $this->assertEquals(['DE'], $result['variants'][0]['countries']);
    }

    public function testSimpleProductMappingFallsBackToGeneratedSku(): void
    {
        $psProduct = [
            'id' => 77,
            'reference' => '   ',
            'price' => '12.50',
            'ean13' => '',
            'quantity' => '2',
            'name' => [['id' => '1', 'value' => 'Fallback']],
            'description' => [],
            'description_short' => [],
        ];

        $result = $this->mapper->mapProductToAy($psProduct, [], []);
        $this->assertEquals('PS-77', $result['variants'][0]['sku']);
    }

    public function testProductWithCombinations(): void
    {
        $psProduct = [
            'id' => 5,
            'reference' => 'JEANS',
            'price' => '59.99',
            'name' => [['id' => '1', 'value' => 'Blue Jeans']],
            'description' => [],
            'description_short' => [],
        ];

        $combinations = [
            [
                'id' => 101,
                'reference' => 'JEANS-S',
                'price' => '0.00',
                'ean13' => '',
                'quantity' => 5,
                'weight' => 0,
                'associations' => ['product_option_values' => [
                    ['group_name' => 'Size', 'name' => 'S'],
                    ['group_name' => 'Color', 'name' => 'Blue'],
                ]],
            ],
            [
                'id' => 102,
                'reference' => 'JEANS-M',
                'price' => '0.00',
                'ean13' => '',
                'quantity' => 8,
                'weight' => 0,
                'associations' => ['product_option_values' => [
                    ['group_name' => 'Size', 'name' => 'M'],
                    ['group_name' => 'Color', 'name' => 'Blue'],
                ]],
            ],
        ];

        $result = $this->mapper->mapProductToAy($psProduct, $combinations, [], null);

        $this->assertCount(2, $result['variants']);
        $this->assertEquals('JEANS-S', $result['variants'][0]['sku']);
        $this->assertEquals('JEANS-M', $result['variants'][1]['sku']);
        $this->assertEquals(5999, $result['variants'][0]['prices'][0]['retail_price']);
        $this->assertEquals(201, $result['variants'][0]['size']);
        $this->assertEquals(101, $result['variants'][0]['color']);
        $this->assertEquals(202, $result['variants'][1]['size']);
    }

    public function testImageMapping(): void
    {
        $psProduct = [
            'id' => 1,
            'reference' => 'IMG-TEST',
            'price' => '10.00',
            'name' => [['id' => '1', 'value' => 'Image Test']],
            'description' => [],
            'description_short' => [],
        ];

        $imageUrls = [
            'https://shop.com/img/1/1.jpg',
            'https://shop.com/img/1/2.jpg',
        ];

        $result = $this->mapper->mapProductToAy($psProduct, [], $imageUrls);

        $this->assertCount(2, $result['variants'][0]['images']);
        $this->assertSame('https://shop.com/img/1/1.jpg', $result['variants'][0]['images'][0]);
        $this->assertSame('https://shop.com/img/1/2.jpg', $result['variants'][0]['images'][1]);
    }

    public function testPsToAyStatusMapping(): void
    {
        $this->assertEquals('open', $this->mapper->mapPsStatusToAy(2));
        $this->assertEquals('shipped', $this->mapper->mapPsStatusToAy(4));
        $this->assertEquals('cancelled', $this->mapper->mapPsStatusToAy(6));
        $this->assertEquals('returned', $this->mapper->mapPsStatusToAy(7));
    }

    public function testAyToPsStatusMapping(): void
    {
        $this->assertEquals(2, $this->mapper->mapAyStatusToPs('payment_accepted'));
        $this->assertEquals(4, $this->mapper->mapAyStatusToPs('shipped'));
        $this->assertEquals(6, $this->mapper->mapAyStatusToPs('cancelled'));
        $this->assertEquals(3, $this->mapper->mapAyStatusToPs('processing'));
    }

    public function testUnknownStatusFallback(): void
    {
        $this->assertEquals('open', $this->mapper->mapPsStatusToAy(999));
        $this->assertEquals(3, $this->mapper->mapAyStatusToPs('unknown_status'));
    }

    public function testAyOrderToPs(): void
    {
        $ayOrder = [
            'id' => 'AY-ORD-9999',
            'status' => 'payment_accepted',
            'total_price' => 79.98,
            'subtotal' => 69.98,
            'shipping_price' => 10.00,
            'customer' => [
                'email' => 'test@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            'shipping_address' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'street' => 'Main St 1',
                'zip' => '12345',
                'city' => 'Berlin',
                'country' => 'DE',
            ],
            'items' => [
                [
                    'sku' => 'SHIRT-001',
                    'quantity' => 2,
                    'price' => 29.99,
                ],
            ],
        ];

        $result = $this->mapper->mapAyOrderToPs($ayOrder);

        $this->assertEquals('AY-AY-ORD-9999', $result['reference']);
        $this->assertEquals('AY-ORD-9999', $result['ay_order_id']);
        $this->assertEquals(79.98, $result['total_paid']);
        $this->assertEquals('test@example.com', $result['customer']['email']);
        $this->assertEquals('John', $result['address']['firstname']);
        $this->assertEquals('Berlin', $result['address']['city']);
        $this->assertEquals(8, $result['address']['id_country']);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('SHIRT-001', $result['items'][0]['sku']);
    }

    public function testAyOrderLineItemsAndEmptyNestedAddressFallback(): void
    {
        $ayOrder = [
            'id' => 'AY-LINE-1',
            'status' => 'open',
            'shipping_recipient_first_name' => 'Jane',
            'shipping_recipient_last_name' => 'Smith',
            'shipping_street' => 'Street 5',
            'shipping_zip_code' => '12345',
            'shipping_city' => 'Berlin',
            'shipping_country_code' => 'DE',
            'customer_email' => 'jane@example.com',
            'shipping_address' => [],
            'line_items' => [
                [
                    'ean' => '4012345678901',
                    'quantity' => 1,
                    'unit_price' => 19.95,
                ],
            ],
        ];

        $mapped = $this->mapper->mapAyOrderToPs($ayOrder);
        $this->assertEquals('Street 5', $mapped['address']['address1']);
        $this->assertCount(1, $mapped['items']);
        $this->assertEquals('4012345678901', $mapped['items'][0]['sku']);
    }

    public function testStockMapping(): void
    {
        $psStocks = [
            ['sku' => 'SKU-A', 'quantity' => 10, 'price' => 19.99],
            ['sku' => 'SKU-B', 'quantity' => 0, 'price' => 49.00],
        ];

        $result = $this->mapper->mapStockUpdates($psStocks);

        $this->assertCount(2, $result);
        $this->assertEquals('SKU-A', $result[0]['sku']);
        $this->assertEquals(10, $result[0]['quantity']);
        $this->assertEquals(1999, $result[0]['price']['retail_price']);
        $this->assertEquals(0, $result[1]['quantity']);
    }

    public function testCollectsAttributeIdsFromProductAndVariantMaps(): void
    {
        $psProduct = [
            'id' => 12,
            'reference' => 'TEE',
            'price' => '15.00',
            'name' => [['id' => '1', 'value' => 'Tee']],
            'description' => [],
            'description_short' => [],
            'ay_attribute_ids' => '401,402',
        ];
        $combinations = [[
            'id' => 1,
            'reference' => 'TEE-BLUE-S',
            'quantity' => 1,
            'price' => '0.00',
            'associations' => ['product_option_values' => [
                ['group_name' => 'Material', 'name' => 'Cotton'],
                ['ay_attribute_id' => 403],
                ['group_name' => 'Size', 'name' => 'S'],
                ['group_name' => 'Color', 'name' => 'Blue'],
            ]],
        ]];

        $result = $this->mapper->mapProductToAy($psProduct, $combinations);

        $this->assertSame([401, 402, 301, 403], $result['variants'][0]['attributes']);
    }
}
