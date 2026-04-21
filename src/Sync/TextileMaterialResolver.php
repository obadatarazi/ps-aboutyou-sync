<?php

namespace Sync\Sync;

use Sync\Logger\SyncLogger;
use Sync\PrestaShop\PsApiClient;

/**
 * Builds AboutYou material_composition_textile from PrestaShop feature text and/or env defaults.
 * AboutYou requires approved cluster_id / material_id integers — map free-text via AY_MATERIAL_NAME_TO_ID.
 */
class TextileMaterialResolver
{
    private PsApiClient $ps;
    private SyncLogger $logger;
    private int $languageId;
    /** @var array<string, int> lowercase key => material_id */
    private array $nameToId;
    private int $defaultClusterId;
    /** @var list<int> */
    private array $psMaterialFeatureIds;
    /** @var list<array{cluster_id:int, components:list<array{material_id:int, fraction:int}>}>|null */
    private ?array $defaultComposition = null;
    /** @var array<int, string> */
    private array $featureValueTextCache = [];

    /**
     * @param list<int> $psMaterialFeatureIds PrestaShop product_feature ids (not value ids)
     */
    public function __construct(
        PsApiClient $ps,
        SyncLogger $logger,
        array $nameToId,
        int $defaultClusterId,
        array $psMaterialFeatureIds,
        ?array $defaultComposition
    ) {
        $this->ps = $ps;
        $this->logger = $logger;
        $this->languageId = (int) ($_ENV['PS_LANGUAGE_ID'] ?? 1);
        $this->nameToId = $nameToId;
        $this->defaultClusterId = $defaultClusterId;
        $this->psMaterialFeatureIds = $psMaterialFeatureIds;
        $this->defaultComposition = $defaultComposition;
    }

    public static function createFromEnv(PsApiClient $ps, SyncLogger $logger): ?self
    {
        $enabled = filter_var(
            $_ENV['AY_MATERIAL_COMPOSITION_TEXTILE_ENABLED'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if (!$enabled) {
            return null;
        }

        $defaultRaw = trim((string) ($_ENV['AY_DEFAULT_MATERIAL_COMPOSITION_TEXTILE'] ?? ''));
        $defaultDecoded = null;
        if ($defaultRaw !== '') {
            $defaultDecoded = json_decode($defaultRaw, true);
            if (!is_array($defaultDecoded) || $defaultDecoded === []) {
                $logger->warning('AY_DEFAULT_MATERIAL_COMPOSITION_TEXTILE is set but not valid non-empty JSON array');
                $defaultDecoded = null;
            }
        }

        $mapRaw = trim((string) ($_ENV['AY_MATERIAL_NAME_TO_ID'] ?? ''));
        $nameToId = [];
        if ($mapRaw !== '') {
            $decoded = json_decode($mapRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $nameToId[strtolower(trim((string) $k))] = (int) $v;
                }
            }
        }

        $featureIds = [];
        $idsRaw = trim((string) ($_ENV['PS_MATERIAL_FEATURE_IDS'] ?? ''));
        if ($idsRaw !== '') {
            foreach (preg_split('/\s*,\s*/', $idsRaw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
                $featureIds[] = (int) $p;
            }
        }

        $clusterId = (int) ($_ENV['AY_TEXTILE_DEFAULT_CLUSTER_ID'] ?? 0);

        $canParsePs = $featureIds !== [] && $clusterId > 0 && $nameToId !== [];
        if ($defaultDecoded === null && !$canParsePs) {
            $logger->warning(
                'AY_MATERIAL_COMPOSITION_TEXTILE_ENABLED is true but configure either '
                . 'AY_DEFAULT_MATERIAL_COMPOSITION_TEXTILE or '
                . '(PS_MATERIAL_FEATURE_IDS + AY_TEXTILE_DEFAULT_CLUSTER_ID + AY_MATERIAL_NAME_TO_ID)'
            );

            return null;
        }

        return new self($ps, $logger, $nameToId, $clusterId, $featureIds, $defaultDecoded);
    }

    /**
     * @return list<array{cluster_id:int, components:list<array{material_id:int, fraction:int}>}>|null
     */
    public function resolveForProduct(array $psProduct): ?array
    {
        $fromPs = $this->extractFromPrestaShop($psProduct);
        if ($fromPs !== null && $fromPs !== []) {
            return $fromPs;
        }
        if ($this->defaultComposition !== null && $this->defaultComposition !== []) {
            return $this->defaultComposition;
        }

        return null;
    }

    /**
     * @return list<array{cluster_id:int, components:list<array{material_id:int, fraction:int}>}>|null
     */
    private function extractFromPrestaShop(array $psProduct): ?array
    {
        if ($this->psMaterialFeatureIds === [] || $this->defaultClusterId <= 0 || $this->nameToId === []) {
            return null;
        }

        $allowed = array_fill_keys($this->psMaterialFeatureIds, true);
        $valueIds = [];

        $rows = $this->normalizeProductFeatureRows($psProduct['associations']['product_features'] ?? null);
        foreach ($rows as $row) {
            $fid = isset($row['id_feature']) ? (int) $row['id_feature'] : 0;
            if (!isset($allowed[$fid])) {
                continue;
            }
            $vid = isset($row['id_feature_value']) ? (int) $row['id_feature_value'] : 0;
            if ($vid > 0) {
                $valueIds[] = $vid;
            }
        }

        if ($valueIds === []) {
            return null;
        }

        $texts = [];
        foreach ($valueIds as $vid) {
            $t = $this->getFeatureValueText($vid);
            if ($t !== '') {
                $texts[] = $t;
            }
        }
        if ($texts === []) {
            return null;
        }

        $raw = implode('; ', $texts);
        $parts = self::parseCompositionText($raw);
        if ($parts === []) {
            return null;
        }

        $components = [];
        foreach ($parts as $p) {
            $mid = $this->matchMaterialId($p['label']);
            if ($mid === null) {
                $this->logger->warning('TextileMaterialResolver: no AY material_id for label', [
                    'label' => $p['label'],
                ]);
                continue;
            }
            $components[] = ['material_id' => $mid, 'fraction' => $p['fraction']];
        }

        $components = self::normalizeFractionsTo100($components);
        if ($components === []) {
            return null;
        }

        return [
            [
                'cluster_id' => $this->defaultClusterId,
                'components' => $components,
            ],
        ];
    }

    /**
     * @param mixed $node
     * @return list<array<string, mixed>>
     */
    private function normalizeProductFeatureRows($node): array
    {
        if ($node === null || $node === []) {
            return [];
        }
        if (isset($node['product_feature'])) {
            $node = $node['product_feature'];
        }
        if (isset($node['id'], $node['id_feature_value'])) {
            return [$node];
        }
        if (!is_array($node)) {
            return [];
        }
        $out = [];
        foreach ($node as $row) {
            if (is_array($row) && (isset($row['id_feature_value']) || isset($row['id_feature']))) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function getFeatureValueText(int $valueId): string
    {
        if (isset($this->featureValueTextCache[$valueId])) {
            return $this->featureValueTextCache[$valueId];
        }
        $row = $this->ps->getProductFeatureValue($valueId);
        if ($row === null) {
            $this->featureValueTextCache[$valueId] = '';

            return '';
        }
        $text = $this->readLangValue($row['value'] ?? '');
        $text = html_entity_decode(trim(strip_tags($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->featureValueTextCache[$valueId] = $text;

        return $text;
    }

    /**
     * @param mixed $langArray
     */
    private function readLangValue($langArray): string
    {
        if ($langArray === null || $langArray === '') {
            return '';
        }
        if (is_string($langArray)) {
            return $langArray;
        }
        if (!is_array($langArray)) {
            return '';
        }
        if (isset($langArray[0]['value'])) {
            foreach ($langArray as $entry) {
                if ((int) ($entry['id'] ?? 0) === $this->languageId) {
                    return (string) ($entry['value'] ?? '');
                }
            }

            return (string) ($langArray[0]['value'] ?? '');
        }

        return '';
    }

    private function matchMaterialId(string $label): ?int
    {
        $key = strtolower(preg_replace('/\s+/', ' ', trim($label)));
        if ($key === '') {
            return null;
        }
        if (isset($this->nameToId[$key])) {
            return $this->nameToId[$key];
        }
        foreach ($this->nameToId as $needle => $mid) {
            if ($needle !== '' && str_contains($key, $needle)) {
                return $mid;
            }
        }

        return null;
    }

    /**
     * @return list<array{label:string, fraction:int}>
     */
    public static function parseCompositionText(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $segments = preg_split('/[;,\n|]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($segments === false) {
            return [];
        }
        $out = [];
        foreach ($segments as $seg) {
            $p = trim($seg);
            if ($p === '') {
                continue;
            }
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*%\s*(.+)$/u', $p, $m)) {
                $out[] = ['label' => trim($m[2]), 'fraction' => self::roundPct($m[1])];
            } elseif (preg_match('/^(.+?)\s*[=:]\s*(\d+(?:[.,]\d+)?)\s*%?\s*$/u', $p, $m)) {
                $out[] = ['label' => trim($m[1]), 'fraction' => self::roundPct($m[2])];
            }
        }
        if ($out === [] && $raw !== '') {
            $out[] = ['label' => $raw, 'fraction' => 100];
        }

        return $out;
    }

    private static function roundPct(string $num): int
    {
        $num = str_replace(',', '.', $num);

        return (int) max(0, min(100, round((float) $num)));
    }

    /**
     * @param list<array{material_id:int, fraction:int}> $components
     * @return list<array{material_id:int, fraction:int}>
     */
    public static function normalizeFractionsTo100(array $components): array
    {
        if ($components === []) {
            return [];
        }
        $sum = 0;
        foreach ($components as $c) {
            $sum += $c['fraction'];
        }
        if ($sum <= 0) {
            return [];
        }
        $out = [];
        $acc = 0;
        $n = count($components);
        foreach ($components as $i => $c) {
            if ($i === $n - 1) {
                $f = 100 - $acc;
            } else {
                $f = (int) max(0, min(100, round($c['fraction'] * 100 / $sum)));
            }
            $acc += $f;
            if ($f > 0) {
                $out[] = ['material_id' => $c['material_id'], 'fraction' => $f];
            }
        }

        return $out;
    }
}
