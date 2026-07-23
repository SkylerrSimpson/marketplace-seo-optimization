<?php

declare(strict_types=1);

/**
 * PHASE 6 (fill — DRY RUN). Propose values for the Phase-5 aspect gaps from the
 * Usurper export + constant defaults, constrained to each aspect's allowed-value
 * list. Writes a reviewable proposed_fills.csv. Does NOT touch eBay.
 *
 * Sources (see marketplaces/ebay/data/aspect_field_map.json):
 *   - usurper  : value from a mapped column in the Usurper export, joined to the
 *                listing by its representative SKU (listings.json item_id->sku).
 *   - default  : a fixed constant (Unit Type=Unit, Unit Quantity=1, Vintage=No...).
 * For SELECTION_ONLY aspects the proposed value must match the category schema's
 * allowed-value list (case/space-insensitive); non-matches are flagged
 * 'value_not_in_list' and NOT proposed, so we never feed eBay an invalid value.
 *
 * Output (marketplaces/ebay/data/{account}/output/proposed_fills.csv):
 *   item_id, sku, name, category_id, aspect, importance, mode, proposed_value, source, status, title
 * status: ok | value_not_in_list | placeholder | no_source_value | no_usurper_row | unmapped
 *
 * Usage:
 *   php marketplaces/ebay/scripts/fill_aspects.php --account=dows --export=marketplaces/ebay/data/dows/input/InventoryExport_....csv
 *   (omit --export to auto-pick the newest InventoryExport_*.csv in that account's input)
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts = getopt('', ['account:', 'export:', 'help']);
if (isset($opts['help'])) { fwrite(STDOUT, "Usage: php fill_aspects.php --account=dows [--export=path.csv]\n"); exit(0); }
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$outDir  = ebay_dir($account, 'output');

// Load every Usurper export in input/ (or just --export). Merge by SKU at the
// COLUMN level: Usurper caps a selection at 22 columns, so coverage is built from
// multiple passes over the same SKUs with different column sets. We UNION all
// columns across files for a SKU; for an overlapping column a newer NON-EMPTY
// value wins (so a corrected re-export supersedes a stale value, but a pass that
// simply doesn't include a column never erases one a prior pass filled).
$exportFiles = isset($opts['export'])
    ? [(string) $opts['export']]
    : glob(ebay_dir($account, 'input') . '/*.csv');   // every export pass (any name)
if ($exportFiles === []) { fwrite(STDERR, "No Usurper export found in " . ebay_dir($account, 'input') . "\n"); exit(1); }
usort($exportFiles, fn($a, $b) => filemtime($a) <=> filemtime($b));   // oldest -> newest
echo "=== Phase 6 fill (DRY RUN): {$account} ===\nexports: " . count($exportFiles) . " file(s)\n";

/** normalize an aspect name / value for matching */
function norm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }

// --- field map ----------------------------------------------------------------
$map = json_decode((string) file_get_contents(EBAY_DATA . '/aspect_field_map.json'), true);
$usurperMap = $map['usurper'] ?? [];
$defaults   = $map['defaults'] ?? [];

// --- Usurper exports, keyed by SKU (assoc colname=>value, newest file wins) ---
$bySku = [];
foreach ($exportFiles as $path) {
    $fh = fopen($path, 'r'); $uh = array_map('trim', fgetcsv($fh));
    while (($r = fgetcsv($fh)) !== false) {
        $row = [];
        foreach ($uh as $i => $col) { $row[$col] = $r[$i] ?? ''; }
        $sku = trim((string) ($row['sku'] ?? ''));
        if ($sku === '') { continue; }
        $existing = $bySku[$sku] ?? [];
        foreach ($row as $col => $val) {
            // fill a column we haven't seen, or overwrite only when this pass has a value
            if (!array_key_exists($col, $existing) || trim((string) $val) !== '') {
                $existing[$col] = $val;
            }
        }
        $bySku[$sku] = $existing;   // column-level union across passes
    }
    fclose($fh);
}
echo "usurper rows (merged, unique SKUs): " . count($bySku) . "\n";

// --- item_id -> representative SKU (listings.json) ----------------------------
$repSku = [];
foreach (json_decode((string) file_get_contents($outDir . '/listings.json'), true) ?: [] as $l) {
    $repSku[(string) $l['item_id']] = trim((string) $l['sku']);
}

// --- schema allowed-value lookup (lazy): catId|normAspect -> [normValue=>canonical]
$allowedCache = [];
function allowedValues(string $catId, string $aspectName, array &$cache): ?array
{
    $key = $catId;
    if (!array_key_exists($key, $cache)) {
        $path = EBAY_ASPECTS . "/{$catId}.json";
        $byAspect = [];
        if (is_file($path)) {
            $j = json_decode((string) file_get_contents($path), true) ?: [];
            foreach ($j['aspects'] ?? [] as $a) {
                $m = [];
                foreach ($a['values'] ?? [] as $v) { $m[norm((string) $v)] = (string) $v; }
                $byAspect[norm((string) $a['name'])] = $m;
            }
        }
        $cache[$key] = $byAspect;
    }
    return $cache[$key][norm($aspectName)] ?? null;
}

// --- AI inference (HIGH-PRECISION ONLY) ---------------------------------------
// We only propose an AI value when we are essentially certain it is correct:
//   - SELECTION_ONLY: exactly ONE of the aspect's allowed values appears verbatim
//     (whole-phrase) in the product name/title, and no more-specific allowed value
//     also matches → unambiguous, so we fill the canonical allowed value.
//   - a couple of safe structured extractions (piece/pack count from "8pc"/"8 Piece").
// Anything ambiguous (0 or >1 candidate) is left as a gap. FREE_TEXT is otherwise
// not inferred (no allowed list to anchor 100% certainty).
$AI_SKIP_VALUES = array_flip([
    'does not apply', 'unbranded', 'other', 'n/a', 'na', 'none', 'see description',
    'not specified', 'unspecified', 'multi-color', 'multicolor', 'assorted', 'various', 'mixed',
]);
// SELECTION_ONLY name-matching is ONLY trusted for aspects where "the word appears in
// the product name => the value is true" holds reliably. Aspects like Material,
// Insulation Type, Indoor/Outdoor, Occasion are EXCLUDED: a "Down cleaner" isn't made
// of down, an "ASR Outdoor" brand isn't an Indoor/Outdoor classification, etc.
$AI_NAME_ASPECTS = array_flip([
    'department', 'character', 'character family', 'franchise',
    'color', 'base color', 'manufacturer color',
]);

function aiInfer(string $catId, string $aspect, string $mode, string $name, string $title, string $brand, array &$allowedCache, array $skipVals, array $nameAspects): array
{
    $na  = norm($aspect);
    // strip the brand from the text so brand words (e.g. "ASR Outdoor", "Black Diamond")
    // never masquerade as an aspect value
    $text = $name . ' ' . $title;
    if ($brand !== '' && mb_strlen($brand) >= 2) { $text = str_ireplace($brand, ' ', $text); }
    $hay = ' ' . norm($text) . ' ';

    // structured: piece/pack count -> "Number of Pieces/Items/Items in Set/Tools", "Number in Pack"
    if (preg_match('/^number (of (items in set|pieces|items|tools)|in pack)$/', $na)) {
        // reject "N Pack Sizes" / "N Size(s)" — that's a count of size OPTIONS on a
        // variation parent, not a piece count.
        preg_match_all('/(?<![0-9])(\d{1,3})\s*-?\s*(?:pc|pcs|piece|pieces|pack|pk|count|ct|set)(?![a-z0-9])(?!\s*sizes?\b)/u', $hay, $m);
        $nums = array_values(array_unique($m[1] ?? []));
        if (count($nums) === 1) { return [$nums[0], 'ai:count']; }   // all detected counts agree
        return ['', ''];                                             // none, or conflicting -> not certain
    }

    if ($mode !== 'SELECTION_ONLY' || !isset($nameAspects[$na])) { return ['', '']; }
    $allowed = allowedValues($catId, $aspect, $allowedCache);   // [normValue => canonical]
    if (!$allowed) { return ['', '']; }

    $hits = [];
    foreach ($allowed as $nv => $canon) {
        $nv = (string) $nv;   // numeric-string keys come back as ints
        if ($nv === '' || mb_strlen($nv) < 2 || isset($skipVals[$nv])) { continue; }
        if (preg_match('/(?<![a-z0-9])' . preg_quote($nv, '/') . '(?![a-z0-9])/u', $hay)) {
            $hits[$nv] = $canon;
        }
    }
    if (!$hits) { return ['', '']; }
    // keep only the most specific: drop any hit that is a substring of another hit
    foreach (array_keys($hits) as $a) {
        foreach (array_keys($hits) as $b) {
            if ($a !== $b && str_contains($b, $a)) { unset($hits[$a]); break; }
        }
    }
    if (count($hits) === 1) { return [reset($hits), 'ai:name']; }
    return ['', ''];   // still ambiguous (>1 distinct value) -> not confident
}

// --- walk the gap worklist ----------------------------------------------------
$wf = fopen($outDir . '/aspect_gaps_worklist.csv', 'r'); $wh = fgetcsv($wf); $wcix = array_flip($wh);
$out = fopen($outDir . '/proposed_fills.csv', 'w');
fputcsv($out, ['item_id', 'sku', 'name', 'category_id', 'aspect', 'importance', 'mode', 'proposed_value', 'source', 'ai_generated', 'status', 'title']);

$stat = ['ok' => 0, 'value_not_in_list' => 0, 'placeholder' => 0, 'too_long' => 0, 'no_source_value' => 0, 'no_usurper_row' => 0, 'unmapped' => 0, 'out_of_batch' => 0];
$bySource = [];
$aiFilled = 0;
while (($r = fgetcsv($wf)) !== false) {
    $itemId = (string) $r[$wcix['item_id']];
    $catId  = (string) $r[$wcix['category_id']];
    $aspect = (string) $r[$wcix['aspect']];
    $imp    = (string) $r[$wcix['importance']];
    $mode   = (string) $r[$wcix['mode']];
    $title  = (string) $r[$wcix['title']];
    $na = norm($aspect);

    $sku = $repSku[$itemId] ?? '';
    $name = trim((string) ($bySku[$sku]['name'] ?? ''));   // product name from Usurper export
    $brand = '';
    foreach (['brand_ebay', 'brand', 'brand_amazon'] as $bc) { $bv = trim((string) ($bySku[$sku][$bc] ?? '')); if ($bv !== '') { $brand = $bv; break; } }
    // Only score listings included in THIS Usurper batch (by representative SKU).
    if ($sku === '' || !isset($bySku[$sku])) {
        // still allow pure-default fills even without a usurper row
        if (!isset($defaults[$na])) { $stat['out_of_batch']++; continue; }
    }

    // 1) deterministic source first: constant default, else mapped Usurper column(s)
    $value = ''; $source = ''; $mapped = false;
    if (isset($defaults[$na])) {
        $value = $defaults[$na]; $source = 'default'; $mapped = true;
    } elseif (isset($usurperMap[$na]) && isset($bySku[$sku])) {
        $mapped = true;
        $cols = (array) $usurperMap[$na]; $usedCol = $cols[0];
        foreach ($cols as $col) { $v = trim((string) ($bySku[$sku][$col] ?? '')); if ($v !== '') { $value = $v; $usedCol = $col; break; } }
        $source = "usurper:{$usedCol}";
    } elseif (isset($usurperMap[$na])) {
        $mapped = true; $source = 'usurper';   // mapped but listing not in any batch
    }

    // 2) no deterministic value -> try HIGH-PRECISION AI inference from name/title
    if ($value === '') {
        [$av, $asrc] = aiInfer($catId, $aspect, $mode, $name, $title, $brand, $allowedCache, $AI_SKIP_VALUES, $AI_NAME_ASPECTS);
        if ($av !== '') {
            writeRow($out, $itemId, $sku, $name, $catId, $aspect, $imp, $mode, $av, $asrc, 'yes', 'ok', $title);
            $stat['ok']++; $aiFilled++; $bySource[$asrc] = ($bySource[$asrc] ?? 0) + 1; continue;
        }
        if (!$mapped) { $stat['unmapped']++; continue; }                 // no mapping + no AI -> leave as gap
        $st = isset($bySku[$sku]) ? 'no_source_value' : 'no_usurper_row';
        writeRow($out, $itemId, $sku, $name, $catId, $aspect, $imp, $mode, '', $source, 'no', $st, $title);
        $stat[$st]++; continue;
    }

    // 3) have a deterministic value -> validate (placeholder / 65-char cap / allowed list)
    if (str_contains($value, '{') && str_contains($value, '}')) { writeRow($out, $itemId, $sku, $name, $catId, $aspect, $imp, $mode, $value, $source, 'no', 'placeholder', $title); $stat['placeholder']++; continue; }
    // eBay aspect values are capped at 65 chars — never propose something longer
    // (e.g. the full-text Prop 65 warnings) or the write will be rejected.
    if (mb_strlen($value) > 65) { writeRow($out, $itemId, $sku, $name, $catId, $aspect, $imp, $mode, mb_substr($value, 0, 60) . '...', $source, 'no', 'too_long', $title); $stat['too_long']++; continue; }

    $status = 'ok';
    if ($mode === 'SELECTION_ONLY') {
        $allowed = allowedValues($catId, $aspect, $allowedCache);
        if ($allowed !== null && $allowed !== []) {
            $canon = $allowed[norm($value)] ?? null;
            if ($canon === null) { $status = 'value_not_in_list'; }
            else { $value = $canon; }
        }
    }

    writeRow($out, $itemId, $sku, $name, $catId, $aspect, $imp, $mode, $value, $source, 'no', $status, $title);
    $stat[$status]++;
    if ($status === 'ok') { $bySource[$source] = ($bySource[$source] ?? 0) + 1; }
}
fclose($wf); fclose($out);

// --- report -------------------------------------------------------------------
echo "\n--- proposed fills by status ---\n";
foreach ($stat as $k => $v) { printf("  %-18s %d\n", $k, $v); }
printf("\n  of the %d OK, AI-inferred (high-precision): %d   deterministic: %d\n", $stat['ok'], $aiFilled, $stat['ok'] - $aiFilled);
echo "\n--- OK fills by source (top) ---\n";
arsort($bySource);
foreach (array_slice($bySource, 0, 24, true) as $s => $n) { printf("  %-30s %d\n", $s, $n); }
echo "\nwrote {$outDir}/proposed_fills.csv\n";

function writeRow($out, $itemId, $sku, $name, $catId, $aspect, $imp, $mode, $value, $source, $ai, $status, $title): void
{
    fputcsv($out, [$itemId, $sku, $name, $catId, $aspect, $imp, $mode, $value, $source, $ai, $status, $title]);
}
