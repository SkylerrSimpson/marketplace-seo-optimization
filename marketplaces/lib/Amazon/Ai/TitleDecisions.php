<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * The human review decision for the two modular-title attributes.
 *
 * generate_titles.php seeds an editable output/title_decisions.csv from the
 * compare/ files; a reviewer sets a `pick` per attribute (anthropic | openai |
 * custom | skip) and, for custom, types a `_final` value. Both patch_listings.php
 * and project_to_usurper.php resolve the chosen final text through this one class
 * so the two never drift.
 *
 * rebuildSheet() refreshes the candidate columns from the latest compare files
 * while PRESERVING any pick/final the reviewer already entered, so re-running
 * generate_titles.php never clobbers a decision.
 *
 * CSV columns (per approved layout):
 *   sku,
 *   item_name_anthropic, item_name_openai, item_name_pick, item_name_final,
 *   td_anthropic, td_openai, td_pick, td_final
 */
final class TitleDecisions
{
    /** attribute => [role => csv column]. */
    public const COLUMNS = [
        'item_name' => [
            'anthropic' => 'item_name_anthropic',
            'openai'    => 'item_name_openai',
            'pick'      => 'item_name_pick',
            'final'     => 'item_name_final',
        ],
        'title_differentiation' => [
            'anthropic' => 'td_anthropic',
            'openai'    => 'td_openai',
            'pick'      => 'td_pick',
            'final'     => 'td_final',
        ],
    ];

    /** Amazon modular caps used by the patch-time coupling check. */
    public const ITEM_NAME_MAX = 75;
    public const COMBINED_MAX  = 200;

    /**
     * Resolve every SKU's chosen final text.
     *
     * @return array<string, array{item_name: ?string, title_differentiation: ?string}>
     *         keyed by sku; a value is null when pick is skip/blank or the chosen
     *         candidate is empty.
     */
    public static function load(string $csvPath): array
    {
        if (!is_file($csvPath)) {
            return [];
        }
        $fh = fopen($csvPath, 'r');
        if ($fh === false) {
            return [];
        }
        $header = fgetcsv($fh, 0, ',', '"', '');
        $out    = [];
        if ($header !== false && $header !== null) {
            while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                if (count($row) !== count($header)) {
                    continue;
                }
                $r   = array_combine($header, $row);
                $sku = trim((string) ($r['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }
                $out[$sku] = [
                    'item_name'             => self::resolve($r, 'item_name'),
                    'title_differentiation' => self::resolve($r, 'title_differentiation'),
                ];
            }
        }
        fclose($fh);
        return $out;
    }

    /**
     * Chosen final text for one attribute of one CSV row, or null.
     *
     * @param array<string,string> $row
     */
    public static function resolve(array $row, string $attr): ?string
    {
        $cols = self::COLUMNS[$attr] ?? null;
        if ($cols === null) {
            return null;
        }
        $pick = strtolower(trim((string) ($row[$cols['pick']] ?? '')));
        $value = match ($pick) {
            'anthropic' => $row[$cols['anthropic']] ?? '',
            'openai'    => $row[$cols['openai']] ?? '',
            'custom'    => $row[$cols['final']] ?? '',
            default     => '', // '' or 'skip'
        };
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /**
     * Amazon modular-title coupling error for a decided pair, or null when valid.
     * title_differentiation is only valid when the effective item_name (the
     * decided one, else the live listing's) is <= 75 chars and the pair <= 200.
     */
    public static function couplingError(?string $itemName, ?string $titleDiff, ?string $liveItemName): ?string
    {
        if ($itemName !== null && mb_strlen($itemName) > self::ITEM_NAME_MAX) {
            return 'item_name exceeds ' . self::ITEM_NAME_MAX . ' chars (' . mb_strlen($itemName) . ')';
        }
        if ($titleDiff === null) {
            return null;
        }
        $effective = $itemName ?? $liveItemName ?? '';
        if ($effective !== '' && mb_strlen($effective) > self::ITEM_NAME_MAX) {
            return 'title_differentiation requires item_name <= ' . self::ITEM_NAME_MAX . ' chars';
        }
        if ($effective !== '' && mb_strlen($effective) + mb_strlen($titleDiff) > self::COMBINED_MAX) {
            return 'item_name + title_differentiation exceeds ' . self::COMBINED_MAX . ' chars';
        }
        return null;
    }

    /**
     * Seed / refresh output/title_decisions.csv from the compare/ files, keeping
     * any pick/final the reviewer already set. Rows are sorted by sku.
     */
    public static function rebuildSheet(string $comparePath, string $csvPath): void
    {
        $existing = self::rawRows($csvPath); // sku => raw row (preserve pick/final)

        $rows = [];
        foreach (glob($comparePath . '/*.json') ?: [] as $file) {
            $c = json_decode((string) file_get_contents($file), true);
            if (!is_array($c) || !isset($c['sku'])) {
                continue;
            }
            $sku  = (string) $c['sku'];
            $prev = $existing[$sku] ?? [];
            $rows[$sku] = [
                'sku'                 => $sku,
                'item_name_anthropic' => self::candidate($c, 'item_name', 'anthropic'),
                'item_name_openai'    => self::candidate($c, 'item_name', 'openai'),
                'item_name_pick'      => $prev['item_name_pick'] ?? '',
                'item_name_final'     => $prev['item_name_final'] ?? '',
                'td_anthropic'        => self::candidate($c, 'title_differentiation', 'anthropic'),
                'td_openai'           => self::candidate($c, 'title_differentiation', 'openai'),
                'td_pick'             => $prev['td_pick'] ?? '',
                'td_final'            => $prev['td_final'] ?? '',
            ];
        }

        ksort($rows);

        $header = [
            'sku',
            'item_name_anthropic', 'item_name_openai', 'item_name_pick', 'item_name_final',
            'td_anthropic', 'td_openai', 'td_pick', 'td_final',
        ];
        $fh = fopen($csvPath, 'w');
        fputcsv($fh, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn($k) => $row[$k] ?? '', $header), ',', '"', '');
        }
        fclose($fh);
    }

    /** A provider's candidate text for an attribute from a compare record. */
    private static function candidate(array $compare, string $attr, string $provider): string
    {
        $v = $compare[$attr][$provider][$attr] ?? '';
        return is_string($v) ? $v : '';
    }

    /**
     * Raw CSV rows keyed by sku (header-mapped), for edit preservation.
     *
     * @return array<string, array<string,string>>
     */
    private static function rawRows(string $csvPath): array
    {
        if (!is_file($csvPath)) {
            return [];
        }
        $fh = fopen($csvPath, 'r');
        if ($fh === false) {
            return [];
        }
        $header = fgetcsv($fh, 0, ',', '"', '');
        $out    = [];
        if ($header !== false && $header !== null) {
            while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                if (count($row) !== count($header)) {
                    continue;
                }
                $r   = array_combine($header, $row);
                $sku = trim((string) ($r['sku'] ?? ''));
                if ($sku !== '') {
                    $out[$sku] = $r;
                }
            }
        }
        fclose($fh);
        return $out;
    }
}
