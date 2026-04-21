<?php

namespace Sync\Services;

use Sync\Config\AppConfig;

class SyncRuntimeStatus
{
    private string $path;
    private array $state = [];

    public function __construct(?AppConfig $config = null)
    {
        $config = $config ?? new AppConfig();
        $this->path = $config->runtimeStatusPath();
    }

    public function start(string $runId, string $command): void
    {
        $this->state = [
            'active' => true,
            'run_id' => $runId,
            'command' => $command,
            'started_at' => date('c'),
            'phase' => 'starting',
            'current_product_id' => null,
            'current_sequence' => 0,
            'total_items' => 0,
            'done_items' => 0,
            'pushed_items' => 0,
            'failed_items' => 0,
            'last_message' => 'Starting sync',
            'updated_at' => date('c'),
        ];
        $this->persist();
    }

    public function update(array $patch): void
    {
        $this->state = array_merge($this->read(), $patch, [
            'updated_at' => date('c'),
        ]);
        $this->persist();
    }

    public function finish(bool $ok, ?array $stats, float $elapsedSeconds, string $message): void
    {
        $state = $this->read();
        $state['active'] = false;
        $state['ok'] = $ok;
        $state['stats'] = $stats;
        $state['elapsed'] = $elapsedSeconds;
        $state['last_message'] = $message;
        $state['finished_at'] = date('c');
        $state['updated_at'] = date('c');
        $this->state = $state;
        $this->persist();
    }

    public function get(): array
    {
        return $this->read();
    }

    private function read(): array
    {
        if ($this->state !== []) {
            return $this->state;
        }
        if (!file_exists($this->path)) {
            return [];
        }
        $raw = file_get_contents($this->path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function persist(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->path, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
