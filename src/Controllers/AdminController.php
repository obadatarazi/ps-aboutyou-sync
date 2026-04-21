<?php

namespace Sync\Controllers;

use Sync\Config\AppConfig;
use Sync\Services\SyncRunner;

class AdminController
{
    private string $envPath;

    public function __construct()
    {
        $this->envPath = dirname(__DIR__, 2) . '/.env';
    }

    public function index(): array
    {
        $config = new AppConfig();
        return [
            'test_mode' => $config->isTestMode(),
            'dry_run' => $config->isDryRun(),
            'ui_enabled' => $config->uiEnabled(),
            'commands' => ['status', 'products:inc', 'products', 'stock', 'orders', 'order-status', 'repair-mappings', 'all'],
            'log_path' => $this->getResolvedLogPath(),
            'logs' => $this->getLogTail(150),
        ];
    }

    public function runCommand(string $command, array $context = []): array
    {
        $runner = new SyncRunner();

        return $runner->run($command, $context);
    }

    public function toggleSafety(string $key, bool $value): bool
    {
        if (!in_array($key, ['TEST_MODE', 'DRY_RUN'], true)) {
            return false;
        }
        if (!file_exists($this->envPath)) {
            return false;
        }
        $content = (string) file_get_contents($this->envPath);
        $newLine = $key . '=' . ($value ? 'true' : 'false');
        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        if (preg_match($pattern, $content)) {
            $content = (string) preg_replace($pattern, $newLine, $content);
        } else {
            $content .= PHP_EOL . $newLine . PHP_EOL;
        }
        return file_put_contents($this->envPath, $content, LOCK_EX) !== false;
    }

    public function getResolvedLogPath(): string
    {
        return $this->resolveLogPath();
    }

    public function getResolvedWebhookLogPath(): string
    {
        $config = new AppConfig();

        return $config->webhookLogPath();
    }

    /**
     * @return list<string>
     */
    public function getLogTail(int $maxLines = 150): array
    {
        return $this->tailLog($this->resolveLogPath(), $maxLines);
    }

    /**
     * @return list<string>
     */
    public function getWebhookLogTail(int $maxLines = 150): array
    {
        return $this->tailLog($this->getResolvedWebhookLogPath(), $maxLines);
    }

    private function tailLog(string $path, int $maxLines): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }
        return array_slice($lines, -1 * $maxLines);
    }

    private function resolveLogPath(): string
    {
        $configured = $_ENV['LOG_PATH'] ?? __DIR__ . '/../../logs/sync.log';
        if (file_exists($configured)) {
            return $configured;
        }
        $dir = dirname($configured);
        $candidates = glob($dir . '/sync-*.log') ?: [];
        if (empty($candidates)) {
            return $configured;
        }
        rsort($candidates);
        return (string) $candidates[0];
    }
}
