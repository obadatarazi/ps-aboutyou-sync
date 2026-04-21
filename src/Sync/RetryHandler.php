<?php

namespace Sync\Sync;

use GuzzleHttp\Exception\RequestException;
use Sync\Logger\SyncLogger;

/**
 * RetryHandler
 *
 * Wraps any callable with exponential backoff retry logic.
 * Use for all external API calls to handle transient failures.
 */
class RetryHandler
{
    private SyncLogger $logger;
    private int $maxRetries;
    private int $baseDelayMs;    // milliseconds
    private float $multiplier;
    private int $maxDelayMs;

    public function __construct(
        SyncLogger $logger,
        int $maxRetries   = 3,
        int $baseDelayMs  = 500,
        float $multiplier = 2.0,
        int $maxDelayMs   = 30_000
    ) {
        $this->logger      = $logger;
        $this->maxRetries  = $maxRetries;
        $this->baseDelayMs = $baseDelayMs;
        $this->multiplier  = $multiplier;
        $this->maxDelayMs  = $maxDelayMs;
    }

    /**
     * Execute $callable with retry on exception.
     *
     * @param callable $callable  Must return a value or throw on failure.
     * @param string   $context   Label for log messages.
     * @return mixed The return value of $callable on success.
     * @throws \Throwable On final failure after all retries exhausted.
     */
    public function run(callable $callable, string $context = 'operation'): mixed
    {
        $attempt   = 0;
        $delayMs   = $this->baseDelayMs;
        $lastError = null;

        while ($attempt <= $this->maxRetries) {
            try {
                return $callable();
            } catch (\Throwable $e) {
                $lastError = $e;
                $attempt++;
                $retryable = $this->isRetryable($e);

                if ($attempt > $this->maxRetries || !$retryable) {
                    break;
                }

                $this->logger->warning("Retry {$attempt}/{$this->maxRetries} for [{$context}]", [
                    'error'    => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'delay_ms' => $delayMs,
                    'retryable' => $retryable,
                    'http_status' => $this->extractStatusCode($e),
                ]);

                $jitter = random_int(0, 100);
                usleep(($delayMs + $jitter) * 1_000);
                $delayMs = (int) min($delayMs * $this->multiplier, $this->maxDelayMs);
            }
        }

        $this->logger->error("All retries exhausted for [{$context}]", [
            'error' => $lastError?->getMessage(),
            'exception_class' => $lastError ? get_class($lastError) : null,
            'http_status' => $lastError ? $this->extractStatusCode($lastError) : null,
            'http_response' => $lastError ? $this->extractResponseBody($lastError) : null,
        ]);

        throw $lastError;
    }

    /**
     * Execute and return null instead of throwing on final failure.
     */
    public function runSafe(callable $callable, string $context = 'operation'): mixed
    {
        try {
            return $this->run($callable, $context);
        } catch (\Throwable $e) {
            $this->logger->error("runSafe swallowed exception for [{$context}]", [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'http_status' => $this->extractStatusCode($e),
            ]);
            return null;
        }
    }

    private function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof RequestException) {
            return true;
        }
        $status = $this->extractStatusCode($e);
        if ($status === null) {
            return true;
        }
        if ($status === 429) {
            return true;
        }
        return $status >= 500;
    }

    private function extractStatusCode(\Throwable $e): ?int
    {
        if (!$e instanceof RequestException || !$e->hasResponse()) {
            return null;
        }
        return $e->getResponse()->getStatusCode();
    }

    private function extractResponseBody(\Throwable $e): ?string
    {
        if (!$e instanceof RequestException || !$e->hasResponse()) {
            return null;
        }
        return substr((string) $e->getResponse()->getBody(), 0, 1000);
    }
}
