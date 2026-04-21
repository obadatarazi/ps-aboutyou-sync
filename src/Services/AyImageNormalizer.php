<?php

namespace Sync\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sync\Logger\SyncLogger;

/**
 * Downloads PrestaShop (or other) image URLs, crops to 3:4, scales to min 1125×1500,
 * writes JPEGs under public/ay-normalized/, returns public URLs for AboutYou.
 */
class AyImageNormalizer
{
    public const MIN_WIDTH = 1125;
    public const MIN_HEIGHT = 1500;
    public const ASPECT_W = 3;
    public const ASPECT_H = 4;

    private SyncLogger $logger;
    private Client $http;
    private string $publicBaseUrl;
    private string $outputDir;
    private int $jpegQuality;

    public function __construct(
        SyncLogger $logger,
        string $publicBaseUrl,
        ?Client $http = null,
        ?string $outputDir = null,
        int $jpegQuality = 92
    ) {
        $this->logger = $logger;
        $this->publicBaseUrl = rtrim($publicBaseUrl, '/') . '/';
        $this->http = $http ?? new Client([
            'timeout' => 120,
            'connect_timeout' => 30,
            'http_errors' => false,
        ]);
        $this->outputDir = $outputDir ?? (dirname(__DIR__, 2) . '/public/ay-normalized');
        $this->jpegQuality = max(60, min(100, $jpegQuality));
    }

    public static function createFromEnv(SyncLogger $logger): ?self
    {
        $enabled = filter_var(
            $_ENV['IMAGE_NORMALIZE_FOR_AY'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if (!$enabled) {
            return null;
        }
        $base = trim((string) ($_ENV['IMAGE_NORMALIZE_PUBLIC_BASE_URL'] ?? ''));
        if ($base === '') {
            $logger->warning('IMAGE_NORMALIZE_FOR_AY is true but IMAGE_NORMALIZE_PUBLIC_BASE_URL is empty; skipping image normalization');
            return null;
        }
        if (!extension_loaded('gd')) {
            $logger->error('IMAGE_NORMALIZE_FOR_AY enabled but PHP GD extension is not loaded; skipping image normalization');
            return null;
        }

        return new self($logger, $base);
    }

    /**
     * @param list<string> $sourceUrls
     * @return list<string>
     */
    public function normalizeImageUrls(array $sourceUrls): array
    {
        if (!is_dir($this->outputDir)) {
            if (!@mkdir($this->outputDir, 0755, true) && !is_dir($this->outputDir)) {
                $this->logger->error('AyImageNormalizer: cannot create output directory', [
                    'dir' => $this->outputDir,
                ]);
                return [];
            }
        }

        $out = [];
        foreach ($sourceUrls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $normalized = $this->normalizeOne($url);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
            // Failed fetch / decode: omit this image (do not block sync); see debug log per URL.
        }

        return $out;
    }

    /** Log-safe URL without query (hides ws_key). */
    public static function redactUrlForLog(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '[invalid-url]';
        }
        $path = $parts['path'] ?? '';

        return $parts['scheme'] . '://' . $parts['host'] . $path;
    }

    private function normalizeOne(string $sourceUrl): ?string
    {
        $binary = $this->download($sourceUrl);
        if ($binary === null) {
            return null;
        }

        $im = @imagecreatefromstring($binary);
        if ($im === false) {
            $this->logSkip('could not decode image bytes', $sourceUrl);
            return null;
        }

        try {
            $sw = imagesx($im);
            $sh = imagesy($im);
            if ($sw < 2 || $sh < 2) {
                imagedestroy($im);
                $this->logSkip('image too small', $sourceUrl);
                return null;
            }

            [$sx, $sy, $cw, $ch] = self::computeCrop($sw, $sh);
            $cropped = imagecrop($im, [
                'x' => $sx,
                'y' => $sy,
                'width' => $cw,
                'height' => $ch,
            ]);
            imagedestroy($im);
            if ($cropped === false) {
                $this->logger->warning('AyImageNormalizer: crop failed', [
                    'url' => self::redactUrlForLog($sourceUrl),
                ]);
                return null;
            }

            [$tw, $th] = self::computeOutputSize($cw, $ch);
            $scaled = imagecreatetruecolor($tw, $th);
            if ($scaled === false) {
                imagedestroy($cropped);
                return null;
            }
            imagealphablending($scaled, false);
            imagesavealpha($scaled, false);
            $white = imagecolorallocate($scaled, 255, 255, 255);
            imagefilledrectangle($scaled, 0, 0, $tw, $th, $white);

            imagecopyresampled(
                $scaled,
                $cropped,
                0,
                0,
                0,
                0,
                $tw,
                $th,
                $cw,
                $ch
            );
            imagedestroy($cropped);

            $baseName = hash('sha256', $sourceUrl . '|' . self::MIN_WIDTH . 'x' . self::MIN_HEIGHT) . '.jpg';
            $path = $this->outputDir . '/' . $baseName;
            if (!imagejpeg($scaled, $path, $this->jpegQuality)) {
                imagedestroy($scaled);
                $this->logger->warning('AyImageNormalizer: failed to write JPEG', [
                    'path' => $path,
                ]);
                return null;
            }
            imagedestroy($scaled);

            return $this->publicBaseUrl . $baseName;
        } catch (\Throwable $e) {
            $this->logger->warning('AyImageNormalizer: processing error', [
                'url' => self::redactUrlForLog($sourceUrl),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function logSkip(string $reason, string $sourceUrl): void
    {
        $verbose = filter_var(
            $_ENV['IMAGE_NORMALIZE_VERBOSE_SKIPS'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $ctx = [
            'url' => self::redactUrlForLog($sourceUrl),
            'reason' => $reason,
        ];
        if ($verbose) {
            $this->logger->notice('AyImageNormalizer: skipped image', $ctx);
        } else {
            $this->logger->debug('AyImageNormalizer: skipped image', $ctx);
        }
    }

    private function download(string $url): ?string
    {
        try {
            $resp = $this->http->get($url);
            if ($resp->getStatusCode() >= 400) {
                $this->logSkip('HTTP ' . $resp->getStatusCode(), $url);
                return null;
            }
            $body = (string) $resp->getBody();
            if ($body === '') {
                $this->logSkip('empty response body', $url);
                return null;
            }
            return $body;
        } catch (GuzzleException $e) {
            $this->logSkip($e->getMessage(), $url);
            return null;
        }
    }

    /**
     * @return array{0:int,1:int,2:int,3:int} sx, sy, crop width, crop height
     */
    public static function computeCrop(int $sw, int $sh): array
    {
        $rw = self::ASPECT_W;
        $rh = self::ASPECT_H;
        $srcRatio = $sw / $sh;
        $targetRatio = $rw / $rh;

        if ($srcRatio > $targetRatio) {
            $ch = $sh;
            $cw = (int) floor($ch * $targetRatio);
            $sx = (int) floor(($sw - $cw) / 2);
            $sy = 0;
        } elseif ($srcRatio < $targetRatio) {
            $cw = $sw;
            $ch = (int) floor($cw / $targetRatio);
            $sx = 0;
            $sy = (int) floor(($sh - $ch) / 2);
        } else {
            $cw = $sw;
            $ch = $sh;
            $sx = 0;
            $sy = 0;
        }

        $cw = max(1, $cw);
        $ch = max(1, $ch);

        return [$sx, $sy, $cw, $ch];
    }

    /**
     * @return array{0:int,1:int} output width, height (exact 3:4, both >= min)
     */
    public static function computeOutputSize(int $cw, int $ch): array
    {
        $scale = max(self::MIN_WIDTH / $cw, self::MIN_HEIGHT / $ch);
        $th = (int) ceil($ch * $scale);
        $tw = (int) ceil($th * self::ASPECT_W / self::ASPECT_H);
        if ($tw < self::MIN_WIDTH) {
            $tw = self::MIN_WIDTH;
            $th = (int) ceil($tw * self::ASPECT_H / self::ASPECT_W);
        }
        if ($th < self::MIN_HEIGHT) {
            $th = self::MIN_HEIGHT;
            $tw = (int) ceil($th * self::ASPECT_W / self::ASPECT_H);
        }

        return [$tw, $th];
    }
}
