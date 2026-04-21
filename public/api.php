<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Sync\Config\AppConfig;
use Sync\Controllers\AdminController;
use Sync\Logger\SyncLogger;
use Sync\PrestaShop\PsApiClient;
use Sync\Services\AyImageNormalizer;
use Sync\Services\SyncRunner;
use Sync\Sync\DataMapper;
use Sync\Sync\MappingStore;
use Sync\Sync\TextileMaterialResolver;

require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

session_start();

function api_json(int $httpCode, array $body): void
{
    http_response_code($httpCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function allow_long_running_sync_request(): void
{
    $maxExecution = (int) ($_ENV['SYNC_MAX_EXECUTION_TIME'] ?? 0);
    if ($maxExecution < 0) {
        $maxExecution = 0;
    }
    @ini_set('max_execution_time', (string) $maxExecution);
    if (function_exists('set_time_limit')) {
        @set_time_limit($maxExecution);
    }
}

function ui_auth_credentials(): array
{
    $user = (string) ($_ENV['UI_AUTH_USER'] ?? 'admin');
    $pass = (string) ($_ENV['UI_AUTH_PASSWORD'] ?? 'prestasync2024');

    return [$user, $pass];
}

function regenerate_csrf(): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['ui_csrf'] = $token;

    return $token;
}

function require_csrf(array $input): void
{
    $sent = (string) ($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $expected = (string) ($_SESSION['ui_csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $sent)) {
        api_json(403, ['ok' => false, 'error' => 'Invalid or missing CSRF token']);
    }
}

function extract_ps_product_label(?array $product): string
{
    if ($product === null) {
        return '';
    }
    $name = $product['name'] ?? '';
    if (is_string($name)) {
        return $name;
    }
    if (is_array($name)) {
        foreach ($name as $lang) {
            if (is_array($lang) && isset($lang['value'])) {
                return (string) $lang['value'];
            }
        }
        $first = reset($name);
        if (is_string($first)) {
            return $first;
        }
    }

    return 'Product #' . (int) ($product['id'] ?? 0);
}

function extract_ps_product_price(?array $product): string
{
    if ($product === null) {
        return '—';
    }
    if (isset($product['price'])) {
        return (string) $product['price'];
    }

    return '—';
}

function extract_ps_product_qty(?array $product): string
{
    if ($product === null) {
        return '—';
    }
    if (isset($product['quantity'])) {
        return (string) (int) $product['quantity'];
    }
    if (isset($product['associations']['stock_availables'])) {
        $rows = $product['associations']['stock_availables'];
        if (isset($rows['stock_available']['quantity'])) {
            return (string) (int) $rows['stock_available']['quantity'];
        }
        if (isset($rows[0]['quantity'])) {
            return (string) (int) $rows[0]['quantity'];
        }
    }

    return '—';
}

function api_lang_value(mixed $value, int $languageId = 1): string
{
    if (is_string($value)) {
        return $value;
    }
    if (!is_array($value)) {
        return '';
    }
    if (isset($value[0]['value'])) {
        foreach ($value as $entry) {
            if ((int) ($entry['id'] ?? 0) === $languageId) {
                return (string) ($entry['value'] ?? '');
            }
        }
        return (string) ($value[0]['value'] ?? '');
    }

    return '';
}

function api_clean_text(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', trim($text));

    return $text ?? '';
}

function api_normalize_feature_rows(mixed $node): array
{
    if ($node === null || $node === []) {
        return [];
    }
    if (is_array($node) && isset($node['product_feature'])) {
        $node = $node['product_feature'];
    }
    if (!is_array($node)) {
        return [];
    }
    if (isset($node['id']) || isset($node['id_feature_value'])) {
        return [$node];
    }

    return array_values(array_filter($node, 'is_array'));
}

function api_build_product_detail(
    PsApiClient $ps,
    SyncLogger $logger,
    AppConfig $config,
    int $productId
): array {
    $languageId = (int) ($_ENV['PS_LANGUAGE_ID'] ?? 1);
    $product = $ps->getProduct($productId, true);
    if ($product === null) {
        throw new RuntimeException('Product not found in PrestaShop');
    }

    $combinations = $ps->getCombinations($productId);
    $mapper = new DataMapper();
    $imageUrls = $ps->getProductImageUrls($productId, $product);
    $imageNormalizer = AyImageNormalizer::createFromEnv($logger);
    $normalizedImageUrls = $imageNormalizer?->normalizeImageUrls($imageUrls) ?? [];
    $textileMaterial = TextileMaterialResolver::createFromEnv($ps, $logger);
    $materialCompositionTextile = $textileMaterial?->resolveForProduct($product);

    $defaultCategoryId = (int) ($product['id_category_default'] ?? 0);
    $categoryName = '';
    if ($defaultCategoryId > 0) {
        $cat = $ps->getCategory($defaultCategoryId);
        if (is_array($cat)) {
            $categoryName = api_lang_value($cat['name'] ?? '', $languageId);
        }
    }

    $mapped = $mapper->mapProductToAy(
        $product,
        $combinations,
        $normalizedImageUrls !== [] ? $normalizedImageUrls : $imageUrls,
        $categoryName,
        $materialCompositionTextile
    );

    $featureDiagnostics = [];
    foreach (api_normalize_feature_rows($product['associations']['product_features'] ?? null) as $row) {
        $featureValueId = (int) ($row['id_feature_value'] ?? 0);
        $featureId = (int) ($row['id'] ?? $row['id_feature'] ?? 0);
        $featureValue = $featureValueId > 0 ? $ps->getProductFeatureValue($featureValueId) : null;
        $featureDiagnostics[] = [
            'feature_id' => $featureId,
            'feature_value_id' => $featureValueId,
            'text' => api_clean_text(api_lang_value($featureValue['value'] ?? '', $languageId)),
        ];
    }

    $variantDiagnostics = [];
    foreach ($combinations as $index => $combo) {
        $variantDiagnostics[] = [
            'combination_id' => (int) ($combo['id'] ?? 0),
            'reference' => (string) ($combo['reference'] ?? ''),
            'quantity' => (int) ($combo['quantity'] ?? 0),
            'ean13' => (string) ($combo['ean13'] ?? ''),
            'option_values' => array_map(static function (array $value): array {
                return [
                    'id' => (int) ($value['id'] ?? 0),
                    'group' => (string) ($value['group_name'] ?? $value['attribute_group_name'] ?? ''),
                    'name' => (string) ($value['name'] ?? $value['value'] ?? ''),
                ];
            }, array_values(array_filter(
                is_array($combo['associations']['product_option_values'] ?? null)
                    ? ($combo['associations']['product_option_values']['product_option_value'] ?? $combo['associations']['product_option_values'])
                    : [],
                'is_array'
            ))),
            'mapped_variant' => $mapped['variants'][$index] ?? null,
        ];
    }

    return [
        'product_id' => $productId,
        'reference' => (string) ($product['reference'] ?? ''),
        'name' => api_lang_value($product['name'] ?? '', $languageId),
        'active' => (int) ($product['active'] ?? 0) === 1,
        'category_name' => $categoryName,
        'raw_image_urls' => $imageUrls,
        'normalized_image_urls' => $normalizedImageUrls,
        'image_normalization' => [
            'enabled' => $imageNormalizer !== null,
            'output_count' => count($normalizedImageUrls),
            'using_normalized' => $normalizedImageUrls !== [],
        ],
        'material_composition_textile' => $materialCompositionTextile,
        'feature_diagnostics' => $featureDiagnostics,
        'variant_diagnostics' => $variantDiagnostics,
        'aboutyou_payload' => $mapped,
        'checks' => [
            'has_images' => $imageUrls !== [],
            'has_normalized_images' => $normalizedImageUrls !== [],
            'has_material_textile' => $materialCompositionTextile !== null && $materialCompositionTextile !== [],
            'has_variants' => $combinations !== [],
            'has_ean_on_all_variants' => array_reduce($combinations, static function (bool $carry, array $combo): bool {
                return $carry && trim((string) ($combo['ean13'] ?? '')) !== '';
            }, $combinations !== []),
        ],
        'safety' => [
            'test_mode' => $config->isTestMode(),
            'dry_run' => $config->isDryRun(),
        ],
    ];
}

/**
 * @param array{ay_order_id: string, ps_order_id: int, failed: mixed} $entry
 */
function api_build_order_row(MappingStore $store, ?PsApiClient $ps, array $entry): array
{
    $psOrderId = (int) $entry['ps_order_id'];
    $customer = '—';
    $totalPaid = '—';
    $orderStatus = '—';
    $dateAdd = '—';
    if ($ps !== null && $psOrderId > 0) {
        $o = $ps->getOrder($psOrderId);
        if (is_array($o)) {
            $totalPaid = isset($o['total_paid']) ? (string) $o['total_paid'] : '—';
            $dateAdd = (string) ($o['date_add'] ?? '—');
            $orderStatus = (string) ($o['current_state'] ?? '—');
            $cid = (int) ($o['id_customer'] ?? 0);
            if ($cid > 0) {
                $cust = $ps->getCustomer($cid);
                if (is_array($cust)) {
                    $fn = (string) ($cust['firstname'] ?? '');
                    $ln = (string) ($cust['lastname'] ?? '');
                    $customer = trim($fn . ' ' . $ln) ?: ('#' . $cid);
                }
            }
        }
    }
    $syncStatus = 'synced';
    if (!empty($entry['failed'])) {
        $syncStatus = !empty($entry['failed']['quarantined']) ? 'error' : 'pending';
    } elseif ($psOrderId > 0 && $store->getAyOrderId($psOrderId) === null) {
        $syncStatus = 'not_mapped';
    }

    return [
        'ay_order_id' => (string) ($entry['ay_order_id'] ?? ''),
        'ps_order_id' => $psOrderId,
        'customer' => $customer,
        'total' => $totalPaid,
        'status' => $orderStatus,
        'sync_status' => $syncStatus,
        'date' => $dateAdd,
        'failure' => $entry['failed'] ?? null,
        'mapped' => $psOrderId > 0 && $store->getAyOrderId($psOrderId) !== null,
    ];
}

$config = new AppConfig();
if (!$config->uiEnabled()) {
    api_json(403, ['ok' => false, 'error' => 'UI is disabled. Set UI_ENABLED=true in .env to enable it.']);
}

$raw = file_get_contents('php://input');
$input = [];
if ($raw !== false && $raw !== '') {
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $input = is_array($decoded) ? $decoded : [];
    } catch (\JsonException $e) {
        api_json(400, ['ok' => false, 'error' => 'Invalid JSON body']);
    }
}

$action = (string) ($input['action'] ?? '');

$controller = new AdminController();

if ($action === 'login') {
    [$expectUser, $expectPass] = ui_auth_credentials();
    $user = (string) ($input['username'] ?? '');
    $pass = (string) ($input['password'] ?? '');
    if (!hash_equals($expectUser, $user) || !hash_equals($expectPass, $pass)) {
        api_json(401, ['ok' => false, 'error' => 'Invalid credentials']);
    }
    $_SESSION['ui_authenticated'] = true;
    $_SESSION['ui_login_at'] = time();
    $csrf = regenerate_csrf();
    $cfg = new AppConfig();
    api_json(200, [
        'ok' => true,
        'data' => [
            'csrf' => $csrf,
            'test_mode' => $cfg->isTestMode(),
            'dry_run' => $cfg->isDryRun(),
            'ps_base_url_configured' => trim((string) ($_ENV['PS_BASE_URL'] ?? '')) !== '',
        ],
    ]);
}

if (empty($_SESSION['ui_authenticated'])) {
    api_json(401, ['ok' => false, 'error' => 'Not authenticated']);
}

if ($action === 'logout') {
    require_csrf($input);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    api_json(200, ['ok' => true, 'data' => []]);
}

if ($action === 'bootstrap') {
    $csrf = (string) ($_SESSION['ui_csrf'] ?? '');
    if ($csrf === '') {
        $csrf = regenerate_csrf();
    }
    $cfg = new AppConfig();
    $base = (string) ($_ENV['PS_BASE_URL'] ?? '');
    $maskedBase = $base !== '' ? (preg_replace('#^(https?://[^/]+).*$#', '$1/…', $base) ?: 'configured') : '';

    api_json(200, [
        'ok' => true,
        'data' => [
            'csrf' => $csrf,
            'test_mode' => $cfg->isTestMode(),
            'dry_run' => $cfg->isDryRun(),
            'ps_base_url_hint' => $maskedBase,
            'ps_base_url_configured' => trim($base) !== '',
        ],
    ]);
}

$mutating = in_array($action, ['sync', 'toggle', 'logout'], true);
if ($mutating) {
    require_csrf($input);
}

switch ($action) {
    case 'status':
        allow_long_running_sync_request();
        $runner = new SyncRunner();
        $result = $runner->run('status');
        api_json(200, ['ok' => true, 'data' => $result]);

    case 'sync':
        $command = (string) ($input['command'] ?? '');
        $allowed = ['status', 'products:inc', 'products', 'stock', 'orders', 'order-status', 'repair-mappings', 'all'];
        if (!in_array($command, $allowed, true)) {
            api_json(400, ['ok' => false, 'error' => 'Unknown or disallowed command']);
        }
        allow_long_running_sync_request();
        $context = [];
        if (isset($input['ps_product_ids']) && is_array($input['ps_product_ids'])) {
            $context['ps_product_ids'] = array_slice(array_map('intval', $input['ps_product_ids']), 0, 50);
        }
        if (isset($input['ps_order_ids']) && is_array($input['ps_order_ids'])) {
            $context['ps_order_ids'] = array_slice(array_map('intval', $input['ps_order_ids']), 0, 50);
        }
        $result = $controller->runCommand($command, $context);
        if (($result['ok'] ?? true) === false && isset($result['message']) && is_string($result['message'])) {
            if (strpos($result['message'], 'Another sync run is already in progress') !== false) {
                api_json(409, ['ok' => false, 'error' => $result['message'], 'data' => $result]);
            }
        }
        api_json(200, ['ok' => (bool) ($result['ok'] ?? true), 'data' => $result]);

    case 'toggle':
        $key = (string) ($input['key'] ?? '');
        $value = filter_var($input['value'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $ok = $controller->toggleSafety($key, $value);
        if (!$ok) {
            api_json(400, ['ok' => false, 'error' => 'Toggle failed']);
        }
        $dotenv->safeLoad();
        $cfg = new AppConfig();
        api_json(200, [
            'ok' => true,
            'data' => [
                'test_mode' => $cfg->isTestMode(),
                'dry_run' => $cfg->isDryRun(),
            ],
        ]);

    case 'logs':
        $max = (int) ($input['max_lines'] ?? 200);
        if ($max < 1) {
            $max = 1;
        }
        if ($max > 500) {
            $max = 500;
        }
        api_json(200, [
            'ok' => true,
            'data' => [
                'path' => $controller->getResolvedLogPath(),
                'lines' => $controller->getLogTail($max),
            ],
        ]);

    case 'webhook_logs':
        $max = (int) ($input['max_lines'] ?? 100);
        if ($max < 1) {
            $max = 1;
        }
        if ($max > 500) {
            $max = 500;
        }
        api_json(200, [
            'ok' => true,
            'data' => [
                'path' => $controller->getResolvedWebhookLogPath(),
                'lines' => $controller->getWebhookLogTail($max),
            ],
        ]);

    case 'test_connection':
        try {
            $logger = new SyncLogger('ui');
            $ps = new PsApiClient($logger, $config);
            $ping = $ps->testConnection();
            api_json(200, ['ok' => $ping['ok'], 'data' => $ping]);
        } catch (\Throwable $e) {
            api_json(200, ['ok' => false, 'data' => ['ok' => false, 'message' => $e->getMessage()]]);
        }

    case 'products':
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? 20);
        if ($perPage < 1) {
            $perPage = 20;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }
        $source = (string) ($input['source'] ?? 'mapping');
        if (!in_array($source, ['mapping', 'prestashop', 'failed'], true)) {
            $source = 'mapping';
        }
        $store = new MappingStore();
        $logger = new SyncLogger('ui');
        $ps = null;
        try {
            $ps = new PsApiClient($logger, $config);
        } catch (\Throwable $e) {
            $ps = null;
        }
        $rows = [];
        $total = 0;
        $hasMore = false;

        if ($source === 'mapping') {
            $all = $store->getAllProductMappings();
            $ids = array_keys($all);
            $ids = array_map(static function ($id) {
                return (int) $id;
            }, $ids);
            sort($ids);
            $total = count($ids);
            $offset = ($page - 1) * $perPage;
            $slice = array_slice($ids, $offset, $perPage);
            foreach ($slice as $psId) {
                $map = $store->getProductMapping($psId) ?? [];
                $row = [
                    'id' => $psId,
                    'name' => 'AY: ' . (string) ($map['ay_style_key'] ?? ''),
                    'price' => '—',
                    'stock' => '—',
                    'status' => 'synced',
                    'last_synced' => (string) ($map['synced_at'] ?? ''),
                    'mapped' => true,
                    'ay_style_key' => (string) ($map['ay_style_key'] ?? ''),
                    'source' => 'mapping',
                ];
                if ($ps !== null) {
                    $p = $ps->getProduct($psId, true);
                    if ($p !== null) {
                        $label = extract_ps_product_label($p);
                        if ($label !== '') {
                            $row['name'] = $label;
                        }
                        $row['price'] = extract_ps_product_price($p);
                        $row['stock'] = extract_ps_product_qty($p);
                    } else {
                        $row['status'] = 'error';
                    }
                }
                $rows[] = $row;
            }
        } elseif ($source === 'prestashop') {
            if ($ps === null) {
                api_json(400, ['ok' => false, 'error' => 'PrestaShop client unavailable']);
            }
            $offset = ($page - 1) * $perPage;
            $slice = $ps->listProductIds($offset, $perPage);
            $hasMore = count($slice) === $perPage;
            $total = $hasMore ? ($offset + count($slice) + 1) : ($offset + count($slice));
            foreach ($slice as $psId) {
                $mapped = $store->productMappingExists($psId);
                $map = $store->getProductMapping($psId) ?? [];
                $row = [
                    'id' => $psId,
                    'name' => $mapped ? ('AY: ' . (string) ($map['ay_style_key'] ?? '')) : '—',
                    'price' => '—',
                    'stock' => '—',
                    'status' => $mapped ? 'synced' : 'not_mapped',
                    'last_synced' => (string) ($map['synced_at'] ?? ''),
                    'mapped' => $mapped,
                    'ay_style_key' => (string) ($map['ay_style_key'] ?? ''),
                    'source' => 'prestashop',
                ];
                $p = $ps->getProduct($psId, true);
                if ($p !== null) {
                    $label = extract_ps_product_label($p);
                    if ($label !== '') {
                        $row['name'] = $label;
                    }
                    $row['price'] = extract_ps_product_price($p);
                    $row['stock'] = extract_ps_product_qty($p);
                } else {
                    $row['status'] = 'error';
                }
                $rows[] = $row;
            }
        } else {
            $all = $store->getFailedProducts();
            $ids = array_map('intval', array_keys($all));
            sort($ids);
            $total = count($ids);
            $offset = ($page - 1) * $perPage;
            $slice = array_slice($ids, $offset, $perPage);
            foreach ($slice as $psId) {
                $failure = $store->getProductFailure($psId) ?? [];
                $row = [
                    'id' => $psId,
                    'name' => 'Failed product #' . $psId,
                    'price' => '—',
                    'stock' => '—',
                    'status' => !empty($failure['quarantined']) ? 'error' : 'pending',
                    'last_synced' => (string) ($failure['last_failed_at'] ?? ''),
                    'mapped' => $store->productMappingExists($psId),
                    'ay_style_key' => (string) (($store->getProductMapping($psId) ?? [])['ay_style_key'] ?? ''),
                    'source' => 'failed',
                    'failure' => $failure,
                ];
                if ($ps !== null) {
                    $p = $ps->getProduct($psId, true);
                    if ($p !== null) {
                        $label = extract_ps_product_label($p);
                        if ($label !== '') {
                            $row['name'] = $label;
                        }
                        $row['price'] = extract_ps_product_price($p);
                        $row['stock'] = extract_ps_product_qty($p);
                    }
                }
                $rows[] = $row;
            }
        }

        api_json(200, [
            'ok' => true,
            'data' => [
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'source' => $source,
                'has_more' => $source === 'prestashop' ? $hasMore : null,
            ],
        ]);

    case 'product_detail':
        $productId = (int) ($input['product_id'] ?? 0);
        if ($productId <= 0) {
            api_json(400, ['ok' => false, 'error' => 'Missing product_id']);
        }
        $logger = new SyncLogger('ui');
        $ps = new PsApiClient($logger, $config);
        try {
            $detail = api_build_product_detail($ps, $logger, $config, $productId);
            api_json(200, ['ok' => true, 'data' => $detail]);
        } catch (\Throwable $e) {
            api_json(404, ['ok' => false, 'error' => $e->getMessage()]);
        }

    case 'orders':
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? 20);
        if ($perPage < 1) {
            $perPage = 20;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }
        $source = (string) ($input['source'] ?? 'mapping');
        if ($source !== 'prestashop') {
            $source = 'mapping';
        }
        $store = new MappingStore();
        $logger = new SyncLogger('ui');
        $ps = null;
        try {
            $ps = new PsApiClient($logger, $config);
        } catch (\Throwable $e) {
            $ps = null;
        }
        $rows = [];
        $total = 0;
        $hasMore = false;

        if ($source === 'mapping') {
            $mapped = $store->getAllOrderMappings();
            $failed = $store->getFailedOrders();
            $ayIds = array_unique(array_merge(array_keys($mapped), array_keys($failed)));
            $entries = [];
            foreach ($ayIds as $ayId) {
                $entries[] = [
                    'ay_order_id' => (string) $ayId,
                    'ps_order_id' => isset($mapped[$ayId]) ? (int) $mapped[$ayId] : 0,
                    'failed' => $failed[$ayId] ?? null,
                ];
            }
            usort($entries, static function (array $a, array $b): int {
                return ($b['ps_order_id'] ?? 0) <=> ($a['ps_order_id'] ?? 0);
            });
            $total = count($entries);
            $offset = ($page - 1) * $perPage;
            $slice = array_slice($entries, $offset, $perPage);
            foreach ($slice as $entry) {
                $rows[] = api_build_order_row($store, $ps, $entry);
            }
        } else {
            if ($ps === null) {
                api_json(400, ['ok' => false, 'error' => 'PrestaShop client unavailable']);
            }
            $offset = ($page - 1) * $perPage;
            $orderIds = $ps->listOrderIds($offset, $perPage);
            $hasMore = count($orderIds) === $perPage;
            $total = $hasMore ? ($offset + count($orderIds) + 1) : ($offset + count($orderIds));
            foreach ($orderIds as $psOrderId) {
                $ayId = $store->getAyOrderId($psOrderId);
                $entry = [
                    'ay_order_id' => $ayId !== null ? (string) $ayId : '',
                    'ps_order_id' => $psOrderId,
                    'failed' => $ayId !== null ? ($store->getOrderFailure((string) $ayId)) : null,
                ];
                $rows[] = api_build_order_row($store, $ps, $entry);
            }
        }

        api_json(200, [
            'ok' => true,
            'data' => [
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'source' => $source,
                'has_more' => $source === 'prestashop' ? $hasMore : null,
            ],
        ]);

    default:
        api_json(400, ['ok' => false, 'error' => 'Unknown action']);
}
