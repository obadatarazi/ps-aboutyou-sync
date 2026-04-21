<?php

namespace Sync\Sync;

/**
 * DataMapper
 *
 * Transforms PrestaShop product/order structures into AboutYou API format.
 * PrestaShop is the MASTER — this class only converts PS → AY, never the reverse.
 */
class DataMapper
{
    private int $languageId;
    private string $descriptionLocale;
    /** @var array<string,int> */
    private array $sizeMap;
    /** @var array<string,int> */
    private array $colorMap;
    /** @var array<string,int> */
    private array $attributeMap;

    // Map PrestaShop order state IDs → AboutYou allowed status strings
    // Allowed by AY in your account: open, shipped, cancelled, returned, mixed.
    private array $orderStatusMap = [
        1  => 'open',                 // Awaiting check payment
        2  => 'open',                 // Payment accepted
        3  => 'open',                 // Processing in progress
        4  => 'shipped',              // Shipped
        5  => 'shipped',              // Delivered (closest AY state)
        6  => 'cancelled',            // Cancelled
        7  => 'returned',             // Refunded
        8  => 'cancelled',            // Payment error
        9  => 'open',                 // On backorder (paid)
        10 => 'open',                 // Awaiting bank wire payment
        11 => 'open',                 // Awaiting PayPal payment
        12 => 'open',                 // Remote payment accepted
    ];

    // Map AboutYou order statuses → PrestaShop order state IDs
    private array $ayToPs_orderStatusMap = [
        'open'             => 3,
        'new'              => 2,
        'payment_accepted' => 2,
        'processing'       => 3,
        'shipped'          => 4,
        'cancelled'        => 6,
        'returned'         => 7,
        'mixed'            => 3,
        'delivered'        => 5,
        'refunded'         => 7,
        'return_initiated' => 7,
    ];

    public function __construct()
    {
        $this->languageId = (int) ($_ENV['PS_LANGUAGE_ID'] ?? 1);
        $this->descriptionLocale = (string) ($_ENV['AY_DESCRIPTION_LOCALE'] ?? 'en');
        $this->sizeMap = $this->parseLookupMap((string) ($_ENV['AY_SIZE_MAP'] ?? ''));
        $this->colorMap = $this->parseLookupMap((string) ($_ENV['AY_COLOR_MAP'] ?? ''));
        $this->attributeMap = $this->parseLookupMap((string) ($_ENV['AY_ATTRIBUTE_MAP'] ?? ''));
    }

    // ----------------------------------------------------------------
    // PRODUCT MAPPING: PrestaShop → AboutYou
    // ----------------------------------------------------------------

    /**
     * Map a single PrestaShop product (with combinations) to AboutYou format.
     *
     * @param array $psProduct     Full PS product array (from PS API)
     * @param array $combinations  Array of PS combination arrays
     * @param array $imageUrls     Array of image URLs (from PS API)
     * @param string|null $categoryName  Resolved category name
     * @param list<array{cluster_id:int, components:list<array{material_id:int, fraction:int}>}>|null $materialCompositionTextile AboutYou textile composition
     * @return array{style_key:string,variants:list<array<string,mixed>>} AboutYou product payload
     */
    public function mapProductToAy(
        array $psProduct,
        array $combinations = [],
        array $imageUrls = [],
        ?string $categoryName = null,
        ?array $materialCompositionTextile = null
    ): array {
        $productId    = (int) ($psProduct['id'] ?? 0);
        $styleKey     = 'PS-' . $productId;
        $name         = $this->getLangValue($psProduct['name'] ?? []);
        $description  = $this->getLangValue($psProduct['description'] ?? []);
        $descShort    = $this->getLangValue($psProduct['description_short'] ?? []);
        $basePrice    = (float) ($psProduct['price'] ?? 0);
        $brandId      = $this->resolveIntId([
            $psProduct['ay_brand_id'] ?? null,
            $_ENV['AY_BRAND_ID'] ?? null,
        ]);
        $categoryId   = $this->resolveIntId([
            $psProduct['ay_category_id'] ?? null,
            $_ENV['AY_CATEGORY_ID'] ?? null,
            $categoryName,
        ]);
        $countryCodes = $this->resolveCountryCodes($psProduct);
        $countryOfOrigin = $this->resolveCountryOfOrigin($psProduct);
        $shared = [
            'style_key' => $styleKey,
            'name' => $name,
            'descriptions' => $this->mapDescriptions($description ?: $descShort),
            'brand' => $brandId,
            'category' => $categoryId,
            'countries' => $countryCodes,
            'country_of_origin' => $countryOfOrigin,
            'images' => $this->mapImages($imageUrls),
            'hs_code' => $this->resolveString([
                $psProduct['hs_code'] ?? null,
                $_ENV['AY_HS_CODE'] ?? null,
            ]),
            'attributes' => $this->collectAttributeIds($psProduct),
            'material_composition_textile' => $materialCompositionTextile ?? [],
            'material_composition_non_textile' => $this->normalizeMaterialComposition($psProduct['material_composition_non_textile'] ?? []),
        ];

        $variants = [];
        if (empty($combinations)) {
            $variants[] = $this->buildAyVariant($shared, $psProduct, null, $basePrice);
        } else {
            foreach ($combinations as $combo) {
                $variants[] = $this->buildAyVariant($shared, $psProduct, $combo, $basePrice);
            }
        }

        return [
            'style_key' => $styleKey,
            'variants' => $variants,
        ];
    }

    /**
     * Map a PrestaShop combination to an AboutYou variant.
     */
    public function mapCombination(array $psProduct, array $combo, float $basePrice): array
    {
        return $this->buildAyVariant([], $psProduct, $combo, $basePrice);
    }

    // ----------------------------------------------------------------
    // STOCK / PRICE MAPPING
    // ----------------------------------------------------------------

    /**
     * Build a stock+price update payload for AboutYou.
     * Input: array of ['sku' => ..., 'quantity' => ..., 'price' => ...]
     */
    public function mapStockUpdates(array $psStocks): array
    {
        $updates = [];
        foreach ($psStocks as $stock) {
            $updates[] = [
                'sku'      => $stock['sku'],
                'quantity' => (int) $stock['quantity'],
                'price'    => [
                    'country_code' => $this->resolvePriceCountryCode($stock),
                    'retail_price' => $this->toMinorUnits((float) $stock['price']),
                    'sale_price' => $this->toMinorUnits((float) ($stock['sale_price'] ?? $stock['price'])),
                ],
            ];
        }
        return $updates;
    }

    // ----------------------------------------------------------------
    // ORDER MAPPING: AboutYou → PrestaShop
    // ----------------------------------------------------------------

    /**
     * Map an AboutYou order to PrestaShop order creation fields.
     * Returns everything needed to create customer, address, and order in PS.
     */
    public function mapAyOrderToPs(array $ayOrder): array
    {
        $billingFallback = [
            'first_name' => $ayOrder['billing_recipient_first_name'] ?? null,
            'last_name' => $ayOrder['billing_recipient_last_name'] ?? null,
            'street' => $ayOrder['billing_street'] ?? null,
            'zip' => $ayOrder['billing_zip_code'] ?? null,
            'city' => $ayOrder['billing_city'] ?? null,
            'country' => $ayOrder['billing_country_code'] ?? null,
            'email' => $ayOrder['customer_email'] ?? null,
        ];
        $shippingFallback = [
            'first_name' => $ayOrder['shipping_recipient_first_name'] ?? null,
            'last_name' => $ayOrder['shipping_recipient_last_name'] ?? null,
            'street' => $ayOrder['shipping_street'] ?? null,
            'address2' => $ayOrder['shipping_additional'] ?? null,
            'zip' => $ayOrder['shipping_zip_code'] ?? null,
            'city' => $ayOrder['shipping_city'] ?? null,
            'country' => $ayOrder['shipping_country_code'] ?? null,
        ];
        $customerFallback = [
            'first_name' => $ayOrder['shipping_recipient_first_name']
                ?? $ayOrder['billing_recipient_first_name']
                ?? null,
            'last_name' => $ayOrder['shipping_recipient_last_name']
                ?? $ayOrder['billing_recipient_last_name']
                ?? null,
            'email' => $ayOrder['customer_email'] ?? null,
        ];
        $billing = $this->mergeFallbackMap($ayOrder['billing_address'] ?? null, $billingFallback);
        $shipping = $this->mergeFallbackMap($ayOrder['shipping_address'] ?? null, $shippingFallback);
        $customer = $this->mergeFallbackMap($ayOrder['customer'] ?? null, $customerFallback);
        $ayOrderId = $this->resolveAyOrderId($ayOrder);
        $firstName = $this->firstNonEmpty([
            $shipping['first_name'] ?? null,
            $customer['first_name'] ?? null,
            $billing['first_name'] ?? null,
            'Unknown',
        ]);
        $lastName = $this->firstNonEmpty([
            $shipping['last_name'] ?? null,
            $customer['last_name'] ?? null,
            $billing['last_name'] ?? null,
            'Unknown',
        ]);
        $address1  = $shipping['street']
            ?? $shipping['address1']
            ?? $shipping['line1']
            ?? $shipping['address_line1']
            ?? $billing['street']
            ?? $billing['address1']
            ?? $billing['line1']
            ?? $billing['address_line1']
            ?? '';
        if ($address1 === '' && (!empty($shipping['street_name']) || !empty($shipping['house_number']))) {
            $address1 = trim(($shipping['street_name'] ?? '') . ' ' . ($shipping['house_number'] ?? ''));
        }
        if ($address1 === '') {
            $address1 = 'Unknown address';
        }
        $postcode = $this->firstNonEmpty([
            $shipping['zip'] ?? null,
            $shipping['postcode'] ?? null,
            $shipping['postal_code'] ?? null,
            $billing['zip'] ?? null,
            $billing['postcode'] ?? null,
            $billing['postal_code'] ?? null,
            '0000',
        ]);
        $city = $this->firstNonEmpty([
            $shipping['city'] ?? null,
            $billing['city'] ?? null,
            'Unknown',
        ]);
        $rawItems = $ayOrder['items']
            ?? $ayOrder['order_items']
            ?? $ayOrder['lines']
            ?? $ayOrder['order_lines']
            ?? $ayOrder['line_items']
            ?? [];

        $totalWithTax = $ayOrder['total_price']
            ?? $ayOrder['cost_with_tax']
            ?? 0;
        $totalWithoutTax = $ayOrder['subtotal']
            ?? $ayOrder['cost_without_tax']
            ?? $totalWithTax;
        $shippingTotal = $ayOrder['shipping_price'] ?? 0;
        if (($ayOrder['cost_with_tax'] ?? null) !== null) {
            $totalWithTax = ((float) $totalWithTax) / 100;
        }
        if (($ayOrder['cost_without_tax'] ?? null) !== null) {
            $totalWithoutTax = ((float) $totalWithoutTax) / 100;
        }
        if (($ayOrder['shipping_price'] ?? null) !== null && is_int($ayOrder['shipping_price'])) {
            $shippingTotal = ((float) $shippingTotal) / 100;
        }

        $customerEmail = $this->firstNonEmpty([
            $customer['email'] ?? null,
            $billing['email'] ?? null,
            $ayOrder['customer_email'] ?? null,
            $this->buildFallbackEmail($ayOrderId, (string) ($ayOrder['customer_key'] ?? '')),
        ]);

        return [
            'reference'    => 'AY-' . $ayOrderId,
            'ay_order_id'  => $ayOrderId,
            'payment'      => 'AboutYou',
            'module'       => 'aboutyou',
            'current_state' => $this->mapAyStatusToPs($ayOrder['status'] ?? 'new'),
            'id_currency'  => 1,
            'id_lang'      => (int) ($_ENV['PS_LANGUAGE_ID'] ?? 1),
            'id_shop'      => (int) ($_ENV['PS_SHOP_ID'] ?? 1),
            'id_carrier'   => 1, // adjust to your carrier ID
            'total_paid'   => (float) $totalWithTax,
            'total_paid_real' => (float) $totalWithTax,
            'total_products' => (float) $totalWithoutTax,
            'total_products_wt' => (float) $totalWithoutTax,
            'total_shipping' => (float) $shippingTotal,
            'customer'     => [
                'firstname' => $this->firstNonEmpty([$customer['first_name'] ?? null, $billing['first_name'] ?? null, $firstName]),
                'lastname'  => $this->firstNonEmpty([$customer['last_name'] ?? null, $billing['last_name'] ?? null, $lastName]),
                'email'     => $customerEmail,
                'password'  => substr(md5(uniqid()), 0, 12),
            ],
            'address'      => [
                'alias'     => 'AboutYou-' . substr($ayOrderId, 0, 24),
                'firstname' => $firstName,
                'lastname'  => $lastName,
                'address1'  => $address1,
                'address2'  => $shipping['street2']    ?? $shipping['address2'] ?? '',
                'postcode'  => $postcode,
                'city'      => $city,
                'phone'     => $shipping['phone']      ?? '',
                'id_country'=> $this->mapCountryCode($shipping['country'] ?? 'DE'),
            ],
            'items'        => $this->mapAyOrderItems(is_array($rawItems) ? $rawItems : []),
        ];
    }

    public function mapAyOrderItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $price = $item['price'] ?? $item['unit_price'] ?? $item['price_without_tax'] ?? 0;
            if (is_int($price)) {
                $price = $price / 100;
            }
            $mapped[] = [
                'sku'         => trim((string) (
                    $item['sku']
                    ?? $item['reference']
                    ?? $item['vendor_sku']
                    ?? $item['product_reference']
                    ?? $item['ean']
                    ?? $item['ean13']
                    ?? $item['gtin']
                    ?? ''
                )),
                'product_id'  => $item['ps_product_id']     ?? 0,
                'combo_id'    => $item['ps_combination_id'] ?? 0,
                'quantity'    => (int) ($item['quantity']   ?? $item['qty'] ?? 1),
                'unit_price'  => (float) $price,
            ];
        }
        return $mapped;
    }

    // ----------------------------------------------------------------
    // STATUS MAPPING
    // ----------------------------------------------------------------

    public function mapPsStatusToAy(int $psStateId): string
    {
        return $this->orderStatusMap[$psStateId] ?? 'open';
    }

    public function mapAyStatusToPs(string $ayStatus): int
    {
        return $this->ayToPs_orderStatusMap[$ayStatus] ?? 3;
    }

    // ----------------------------------------------------------------
    // HELPERS
    // ----------------------------------------------------------------

    private function getLangValue(array $langArray): string
    {
        if (empty($langArray)) {
            return '';
        }
        // PS returns language values as [{id:1, value:'...'}, ...]
        if (isset($langArray[0]['value'])) {
            foreach ($langArray as $entry) {
                if ((int) $entry['id'] === $this->languageId) {
                    return $entry['value'];
                }
            }
            return $langArray[0]['value'] ?? '';
        }
        // Sometimes it's a flat string
        if (is_string($langArray)) {
            return $langArray;
        }
        return '';
    }

    /** AY expects image URLs as strings (same as GET /products), not {url, position} objects. */
    private function mapImages(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $out[] = $url;
            }
        }
        return $out;
    }

    private function mapCountryCode(string $countryCode): int
    {
        // PrestaShop country IDs — extend as needed
        $map = [
            'DE' => 8,
            'AT' => 2,
            'CH' => 19,
            'NL' => 13,
            'BE' => 3,
            'FR' => 8,
            'GB' => 17,
            'US' => 21,
        ];
        return $map[strtoupper($countryCode)] ?? 8; // default DE
    }

    private function resolveAyOrderId(array $ayOrder): string
    {
        $candidate = $ayOrder['id'] ?? $ayOrder['order_id'] ?? $ayOrder['order_number'] ?? null;
        if (is_scalar($candidate) && (string) $candidate !== '') {
            return (string) $candidate;
        }
        return 'unknown-' . substr(md5(json_encode($ayOrder)), 0, 12);
    }

    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $v = trim((string) $value);
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    private function buildFallbackEmail(string $ayOrderId, string $customerKey): string
    {
        $seed = $customerKey !== '' ? $customerKey : $ayOrderId;
        $seed = strtolower(preg_replace('/[^a-z0-9]+/', '-', $seed) ?? 'aboutyou');
        $seed = trim($seed, '-');
        if ($seed === '') {
            $seed = 'aboutyou';
        }
        $domain = (string) ($_ENV['AY_FALLBACK_EMAIL_DOMAIN'] ?? 'example.invalid');
        return "ay-{$seed}@{$domain}";
    }

    private function mergeFallbackMap(mixed $preferred, array $fallback): array
    {
        $preferred = is_array($preferred) ? $preferred : [];
        $merged = $fallback;
        foreach ($preferred as $key => $value) {
            if (is_scalar($value) && trim((string) $value) === '') {
                continue;
            }
            $merged[$key] = $value;
        }
        return $merged;
    }

    public function resolveSku(int $productId, ?string $reference, int $combinationId = 0): string
    {
        $candidate = trim((string) ($reference ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
        if ($combinationId > 0) {
            return "PS-{$productId}-{$combinationId}";
        }
        return "PS-{$productId}";
    }

    private function buildAyVariant(array $shared, array $psProduct, ?array $combo, float $basePrice): array
    {
        $productId = (int) ($psProduct['id'] ?? 0);
        $comboId = (int) (($combo['id'] ?? 0));
        $sku = $this->resolveSku($productId, $combo['reference'] ?? $psProduct['reference'] ?? null, $comboId);
        $price = $basePrice + (float) ($combo['price'] ?? 0);
        $weight = (float) ($combo['weight'] ?? $psProduct['weight'] ?? 0);
        $optionValues = $this->normalizeOptionValues($combo['associations']['product_option_values'] ?? []);
        $attributeIds = array_values(array_unique(array_merge(
            $shared['attributes'] ?? [],
            $this->collectAttributeIds($combo ?? []),
            $this->collectAttributeIds(['associations' => ['product_option_values' => $optionValues]])
        )));

        $variant = array_merge($shared, [
            'sku' => $sku,
            'ean' => $this->resolveString([$combo['ean13'] ?? null, $psProduct['ean13'] ?? null]),
            'quantity' => (int) ($combo['quantity'] ?? $psProduct['quantity'] ?? 0),
            'weight' => $this->normalizeWeight($weight),
            'prices' => [[
                'country_code' => $this->resolveDefaultCountryCode($shared['countries'] ?? []),
                'retail_price' => $this->toMinorUnits($price),
                'sale_price' => $this->toMinorUnits((float) ($combo['sale_price'] ?? $psProduct['sale_price'] ?? $price)),
            ]],
            'attributes' => $attributeIds,
        ]);

        $colorId = $this->resolveDimensionId(
            [$combo['ay_color_id'] ?? null, $psProduct['ay_color_id'] ?? null],
            $optionValues,
            ['color', 'colour'],
            $this->colorMap
        );
        if ($colorId !== null) {
            $variant['color'] = $colorId;
        }

        $sizeId = $this->resolveDimensionId(
            [$combo['ay_size_id'] ?? null, $psProduct['ay_size_id'] ?? null],
            $optionValues,
            ['size'],
            $this->sizeMap
        );
        if ($sizeId !== null) {
            $variant['size'] = $sizeId;
        }

        $secondSizeId = $this->resolveDimensionId(
            [$combo['ay_second_size_id'] ?? null, $psProduct['ay_second_size_id'] ?? null],
            $optionValues,
            ['second size', 'length', 'inseam'],
            $this->sizeMap
        );
        if ($secondSizeId !== null) {
            $variant['second_size'] = $secondSizeId;
        }

        return array_filter(
            $variant,
            static fn($value) => !($value === null || $value === '' || $value === [])
        );
    }

    private function mapDescriptions(string $description): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }

        return [$this->descriptionLocale => $description];
    }

    private function normalizeOptionValues(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $rows = $raw['product_option_value'] ?? $raw;
        if (!is_array($rows)) {
            return [];
        }
        if (isset($rows['id']) || isset($rows['name']) || isset($rows['group_name'])) {
            $rows = [$rows];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    private function collectAttributeIds(array $source): array
    {
        $ids = [];
        foreach ([
            $source['ay_attribute_ids'] ?? null,
            $source['attributes'] ?? null,
            $source['attribute_ids'] ?? null,
        ] as $candidate) {
            foreach ($this->normalizeIntList($candidate) as $id) {
                $ids[$id] = $id;
            }
        }

        $optionValues = $this->normalizeOptionValues($source['associations']['product_option_values'] ?? []);
        foreach ($optionValues as $value) {
            foreach ($this->normalizeIntList($value['ay_attribute_ids'] ?? null) as $id) {
                $ids[$id] = $id;
            }
            $singleId = $this->resolveIntId([$value['ay_attribute_id'] ?? null]);
            if ($singleId !== null) {
                $ids[$singleId] = $singleId;
                continue;
            }

            $group = strtolower(trim((string) ($value['group_name'] ?? $value['attribute_group_name'] ?? '')));
            $name = strtolower(trim((string) ($value['name'] ?? $value['value'] ?? '')));
            if ($group !== '' && $name !== '') {
                $key = $group . ':' . $name;
                if (isset($this->attributeMap[$key])) {
                    $ids[$this->attributeMap[$key]] = $this->attributeMap[$key];
                }
            }
        }

        return array_values($ids);
    }

    private function resolveDimensionId(array $candidates, array $optionValues, array $groupHints, array $lookup): ?int
    {
        $direct = $this->resolveIntId($candidates);
        if ($direct !== null) {
            return $direct;
        }

        $groupHints = array_map(static fn(string $s) => strtolower($s), $groupHints);
        foreach ($optionValues as $value) {
            $groupName = strtolower(trim((string) ($value['group_name'] ?? $value['attribute_group_name'] ?? '')));
            $name = strtolower(trim((string) ($value['name'] ?? $value['value'] ?? '')));
            if ($name === '') {
                continue;
            }
            $matchesHint = $groupName === '' ? false : $this->containsAnyHint($groupName, $groupHints);
            if (!$matchesHint) {
                foreach ($groupHints as $hint) {
                    if (isset($value[$hint])) {
                        $name = strtolower(trim((string) $value[$hint]));
                        $matchesHint = $name !== '';
                        break;
                    }
                }
            }
            if (!$matchesHint) {
                continue;
            }
            if (isset($lookup[$name])) {
                return $lookup[$name];
            }
        }

        return null;
    }

    private function containsAnyHint(string $value, array $hints): bool
    {
        foreach ($hints as $hint) {
            if ($hint !== '' && str_contains($value, $hint)) {
                return true;
            }
        }
        return false;
    }

    private function resolveCountryCodes(array $psProduct): array
    {
        $countries = $psProduct['ay_countries']
            ?? $psProduct['countries']
            ?? $_ENV['AY_COUNTRY_CODES']
            ?? $_ENV['AY_COUNTRIES']
            ?? 'DE';

        $normalized = [];
        foreach ($this->normalizeStringList($countries) as $country) {
            $normalized[] = strtoupper($country);
        }

        return $normalized === [] ? ['DE'] : array_values(array_unique($normalized));
    }

    private function resolveCountryOfOrigin(array $psProduct): string
    {
        return strtoupper($this->resolveString([
            $psProduct['country_of_origin'] ?? null,
            $psProduct['ay_country_of_origin'] ?? null,
            $_ENV['AY_COUNTRY_OF_ORIGIN'] ?? null,
            $this->resolveDefaultCountryCode($this->resolveCountryCodes($psProduct)),
        ]));
    }

    private function resolveDefaultCountryCode(array $countries): string
    {
        $first = $countries[0] ?? 'DE';
        return strtoupper(trim((string) $first)) ?: 'DE';
    }

    private function resolvePriceCountryCode(array $stock): string
    {
        return strtoupper(trim((string) ($stock['country_code'] ?? $_ENV['AY_PRICE_COUNTRY_CODE'] ?? $_ENV['AY_COUNTRY_CODES'] ?? 'DE')));
    }

    private function normalizeWeight(float $weight): int
    {
        if ($weight <= 0) {
            return 0;
        }
        if ($weight < 10) {
            return (int) round($weight * 1000);
        }
        return (int) round($weight);
    }

    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function normalizeMaterialComposition(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function resolveString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function resolveIntId(array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            if (is_int($candidate) && $candidate > 0) {
                return $candidate;
            }
            if (is_string($candidate) && preg_match('/^\d+$/', trim($candidate))) {
                $value = (int) trim($candidate);
                if ($value > 0) {
                    return $value;
                }
            }
        }
        return null;
    }

    private function normalizeIntList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_int($value)) {
            return $value > 0 ? [$value] : [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->normalizeIntList($decoded);
            }
            $parts = preg_split('/[\s,;|]+/', $value) ?: [];
            $out = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if (preg_match('/^\d+$/', $part)) {
                    $id = (int) $part;
                    if ($id > 0) {
                        $out[] = $id;
                    }
                }
            }
            return $out;
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $id = $this->resolveIntId([$item, is_array($item) ? ($item['id'] ?? null) : null]);
            if ($id !== null) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    private function normalizeStringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->normalizeStringList($decoded);
            }
            return array_values(array_filter(array_map('trim', preg_split('/[\s,;|]+/', $value) ?: [])));
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @return array<string,int>
     */
    private function parseLookupMap(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            if (!is_scalar($key)) {
                continue;
            }
            $id = $this->resolveIntId([$value]);
            if ($id === null) {
                continue;
            }
            $map[strtolower(trim((string) $key))] = $id;
        }
        return $map;
    }
}
