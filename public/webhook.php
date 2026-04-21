<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Sync\Config\AppConfig;
use Sync\Logger\SyncLogger;
use Sync\Services\SyncRunner;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');

function webhook_json(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function webhook_log_path(AppConfig $config): string
{
    return $config->webhookLogPath();
}

function webhook_append_log(AppConfig $config, array $entry): void
{
    $path = webhook_log_path($config);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$config = new AppConfig();
$logger = new SyncLogger('webhook');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    webhook_json(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || trim($raw) === '') {
    webhook_json(400, ['ok' => false, 'error' => 'Empty payload']);
}

$signatureSecret = trim((string) ($_ENV['WEBHOOK_SECRET'] ?? $_ENV['AY_WEBHOOK_SECRET'] ?? ''));
$providedSecret = (string) ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_X_AY_SIGNATURE'] ?? ($_GET['secret'] ?? ''));
if ($signatureSecret !== '' && !hash_equals($signatureSecret, $providedSecret)) {
    webhook_append_log($config, [
        'timestamp' => date('c'),
        'status' => 'rejected',
        'reason' => 'invalid secret',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    webhook_json(403, ['ok' => false, 'error' => 'Invalid webhook secret']);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    webhook_append_log($config, [
        'timestamp' => date('c'),
        'status' => 'rejected',
        'reason' => 'invalid json',
        'raw' => mb_substr($raw, 0, 5000),
    ]);
    webhook_json(400, ['ok' => false, 'error' => 'Invalid JSON payload']);
}

$event = (string) ($payload['event'] ?? $payload['type'] ?? $payload['topic'] ?? 'unknown');
$orderId = $payload['order_id'] ?? $payload['id'] ?? $payload['resource_id'] ?? null;
$command = null;
if (in_array($event, ['order.created', 'order.updated', 'order', 'ay.order.created', 'ay.order.updated'], true)) {
    $command = 'orders';
}

$entry = [
    'timestamp' => date('c'),
    'status' => 'accepted',
    'event' => $event,
    'order_id' => $orderId,
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'command' => $command,
    'payload' => $payload,
];

$result = null;
if ($command !== null) {
    try {
        $runner = new SyncRunner();
        $result = $runner->run($command);
        $entry['result'] = [
            'ok' => (bool) ($result['ok'] ?? true),
            'message' => (string) ($result['message'] ?? ''),
            'elapsed' => (float) ($result['elapsed'] ?? 0),
        ];
    } catch (Throwable $e) {
        $entry['status'] = 'error';
        $entry['error'] = $e->getMessage();
        webhook_append_log($config, $entry);
        $logger->error('Webhook handling failed', ['event' => $event, 'error' => $e->getMessage()]);
        webhook_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

webhook_append_log($config, $entry);
$logger->info('Webhook received', ['event' => $event, 'command' => $command, 'order_id' => $orderId]);

webhook_json(200, [
    'ok' => true,
    'event' => $event,
    'command' => $command,
    'result' => $result,
]);
