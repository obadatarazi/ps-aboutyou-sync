<?php

namespace Sync\Config;

class AppConfig
{
    public function runtimeStatusPath(): string
    {
        return (string) ($_ENV['SYNC_RUNTIME_STATUS_FILE'] ?? (__DIR__ . '/../../logs/runtime-status.json'));
    }

    public function webhookLogPath(): string
    {
        return (string) ($_ENV['WEBHOOK_LOG_PATH'] ?? (__DIR__ . '/../../logs/webhooks.log'));
    }

    public function isTestMode(): bool
    {
        return $this->envBool('TEST_MODE', false);
    }

    public function isDryRun(): bool
    {
        return $this->envBool('DRY_RUN', false);
    }

    public function syncBatchSize(): int
    {
        return (int) ($_ENV['SYNC_BATCH_SIZE'] ?? 50);
    }

    public function uiEnabled(): bool
    {
        return $this->envBool('UI_ENABLED', true);
    }

    private function envBool(string $name, bool $default): bool
    {
        $raw = $_ENV[$name] ?? null;
        if ($raw === null) {
            return $default;
        }
        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }
}
