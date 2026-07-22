<?php

declare(strict_types=1);

/**
 * aspect_writer.php — shared ReviseItem payload-building logic for the item-aspects
 * write-back, used by both write_canary_test.php (hand-picked test rows) and
 * apply_aspects.php (the full apply_set.json bulk write).
 *
 * Extracted so the two scripts can never drift apart on the two things that make this
 * write-back safe: the MULTI-value comma-splitting gotcha and the ItemSpecifics
 * full-replace semantics. See each function's docblock for the specific bug/incident
 * that shaped it.
 */

/**
 * Load the REAL allowed-values list for a category+aspect from the cached Taxonomy
 * schema (ebay/data/aspects/{categoryId}.json) — NOT review_sheet.csv's `allowed_values`
 * column, which build_apply_set.php's own comments warn is sometimes truncated ("...")
 * and therefore untrustworthy for exact matching. Returns [] if uncached/unknown.
 */
function loadAspectSchema(string $categoryId): array
{
    static $cache = [];
    if (isset($cache[$categoryId])) { return $cache[$categoryId]; }
    $path = dirname(__DIR__, 2) . "/data/aspects/{$categoryId}.json";
    $byAspect = [];
    if (is_file($path)) {
        $d = json_decode((string) file_get_contents($path), true) ?: [];
        foreach ($d['aspects'] ?? [] as $a) {
            $byAspect[strtolower(trim((string) ($a['name'] ?? '')))] = $a;
        }
    }
    return $cache[$categoryId] = $byAspect;
}

/**
 * aspect=>value assoc array -> the NameValueListArrayType wrapper eBay's schema expects.
 * $multiAspects is a set of aspect names (lowercased) whose cardinality is MULTI per
 * review_sheet.csv — for those, a comma-joined value ("Backpacking, Camping, Hiking")
 * must be sent as separate Value[] entries, not one glued string (bug found 2026-07:
 * item 126419572927's Suitable For went out as a single string instead of 3 values).
 * Single-cardinality aspects are never split, even if their value happens to contain a
 * comma (e.g. the California Prop 65 Warning text).
 *
 * GOTCHA: some MULTI aspects have individual allowed VALUES that
 * themselves contain a comma — e.g. Theme's picklist includes both "Cartoon" and
 * "Cartoon, TV & Movie Characters" as two DIFFERENT single entries. Blindly splitting on
 * every comma would wrongly turn the second into ["Cartoon", "TV & Movie Characters"].
 * So before splitting, check the category's real schema (loadAspectSchema): if the WHOLE
 * value already exactly matches one allowed entry, it's one value — never split it.
 */
function buildSpecifics(array $assoc, array $multiAspects = [], string $categoryId = ''): \DTS\eBaySDK\Trading\Types\NameValueListArrayType
{
    $schema = $categoryId !== '' ? loadAspectSchema($categoryId) : [];
    return new \DTS\eBaySDK\Trading\Types\NameValueListArrayType([
        'NameValueList' => array_map(
            function ($aspect, $value) use ($multiAspects, $schema) {
                $value = (string) $value;
                $isMulti = isset($multiAspects[strtolower($aspect)]);
                $values = [$value];
                if ($isMulti && strpos($value, ',') !== false) {
                    $allowed = $schema[strtolower(trim($aspect))]['values'] ?? null;
                    $wholeIsOneEntry = $allowed !== null && in_array(strtolower($value), array_map('strtolower', $allowed), true);
                    if (!$wholeIsOneEntry) {
                        $values = array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
                    }
                }
                return new \DTS\eBaySDK\Trading\Types\NameValueListType(['Name' => $aspect, 'Value' => $values]);
            },
            array_keys($assoc), array_values($assoc)
        ),
    ]);
}

/**
 * Reads review_sheet.csv once and returns, per item_id: the variation baseline
 * (child sku => aspect => current_value, from source=variation rows), the set of
 * varied_by aspect names (lowercased), and the set of MULTI-cardinality aspect names
 * (lowercased) — the three things every write-back script needs to route aspects
 * correctly between parent ItemSpecifics and per-child VariationSpecifics.
 */
function loadVariationContext(string $reviewSheetPath): array
{
    $varBaseline = [];       // [item_id][sku][aspect] = current_value
    $varyByOfItem = [];      // [item_id][aspect_lower] = true
    $multiAspectsOfItem = []; // [item_id][aspect_lower] = true
    if (!is_file($reviewSheetPath)) {
        return [$varBaseline, $varyByOfItem, $multiAspectsOfItem];
    }
    $fh = fopen($reviewSheetPath, 'r');
    $h = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        $r = array_combine($h, $row);
        if (($r['cardinality'] ?? '') === 'MULTI') {
            $multiAspectsOfItem[$r['item_id']][strtolower($r['aspect'])] = true;
        }
        if ($r['source'] !== 'variation') { continue; }
        $varBaseline[$r['item_id']][$r['sku']][$r['aspect']] = $r['current_value'];
        $vb = trim($r['varied_by']);
        if ($vb !== '') { $varyByOfItem[$r['item_id']][strtolower($vb)] = true; }
    }
    fclose($fh);
    return [$varBaseline, $varyByOfItem, $multiAspectsOfItem];
}
