<?php

namespace Sync\Logger;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

/**
 * SyncLogger
 *
 * Wraps Monolog for structured logging.
 * Writes to rotating daily log files + stdout.
 * Supports Slack / email notification hooks on errors.
 */
class SyncLogger
{
    private Logger $monolog;
    private array  $errorBuffer = [];

    public function __construct(string $channel = 'sync')
    {
        $logPath  = $_ENV['LOG_PATH']     ?? __DIR__ . '/../../logs/sync.log';
        $maxFiles = (int) ($_ENV['LOG_MAX_FILES'] ?? 30);
        $level    = $this->resolveLevel($_ENV['LOG_LEVEL'] ?? 'info');

        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );

        // Rotating file handler
        $fileHandler = new RotatingFileHandler($logPath, $maxFiles, $level);
        $fileHandler->setFormatter($formatter);

        // Console handler
        $consoleHandler = new StreamHandler('php://stdout', $level);
        $consoleHandler->setFormatter($formatter);

        $this->monolog = new Logger($channel, [$fileHandler, $consoleHandler]);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->monolog->debug($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->monolog->info($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->monolog->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->monolog->error($message, $context);
        $this->errorBuffer[] = ['message' => $message, 'context' => $context, 'time' => date('c')];
        $this->triggerNotifications($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->monolog->critical($message, $context);
        $this->triggerNotifications($message, $context, true);
    }

    // ----------------------------------------------------------------
    // NOTIFICATIONS
    // ----------------------------------------------------------------

    private function triggerNotifications(string $message, array $context, bool $critical = false): void
    {
        if (filter_var($_ENV['NOTIFY_SLACK_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->sendSlack($message, $context, $critical);
        }
        // Email notifications are sent in daily summary — see bin/report.php
    }

    private function sendSlack(string $message, array $context, bool $critical): void
    {
        $webhook = $_ENV['NOTIFY_SLACK_WEBHOOK'] ?? '';
        if (!$webhook) {
            return;
        }

        $emoji   = $critical ? ':red_circle:' : ':warning:';
        $text    = "{$emoji} *Sync Error*\n`{$message}`";
        if (!empty($context)) {
            $text .= "\n```" . json_encode($context, JSON_PRETTY_PRINT) . "```";
        }

        try {
            $ch = curl_init($webhook);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['text' => $text]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            // Silently swallow — logging a log failure would be recursive
        }
    }

    // ----------------------------------------------------------------
    // DAILY REPORT
    // ----------------------------------------------------------------

    public function getDailySummary(): string
    {
        $errors = count($this->errorBuffer);
        $lines  = ["=== Sync Report " . date('Y-m-d H:i:s') . " ===\n"];
        $lines[] = "Errors in this run: {$errors}";
        foreach ($this->errorBuffer as $e) {
            $lines[] = "[{$e['time']}] {$e['message']} " . json_encode($e['context']);
        }
        return implode("\n", $lines);
    }

    public function sendDailyEmailReport(): void
    {
        if (!filter_var($_ENV['NOTIFY_EMAIL_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $to      = $_ENV['NOTIFY_EMAIL_TO']   ?? '';
        $from    = $_ENV['NOTIFY_EMAIL_FROM'] ?? 'sync@yourstore.com';
        $subject = 'Daily Sync Report — ' . date('Y-m-d');
        $body    = $this->getDailySummary();

        $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
        mail($to, $subject, $body, $headers);
        $this->info('Daily email report sent', ['to' => $to]);
    }

    // ----------------------------------------------------------------
    // HELPERS
    // ----------------------------------------------------------------

    private function resolveLevel(string $level): int
    {
        return match (strtolower($level)){ 
            'debug'   => Logger::DEBUG,
            'info'    => Logger::INFO,
            'warning' => Logger::WARNING,
            'error'   => Logger::ERROR,
            default   => Logger::INFO,
        };
    }
}
