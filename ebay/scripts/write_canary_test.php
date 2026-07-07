<?php

declare(strict_types=1);

/**
 * write_canary_test.php — Stage 2 write-back, canary mode only.
 *
 * Pushes the exact item/sku/aspect/final_value rows in Ethan's test file
 * (data/<acct>/output/canary_test_ids.csv) to eBay via Trading ReviseItem, one
 * listing at a time. Each of these test items was hand-picked by Ethan to probe a
 * specific edge case (solo vs variation, with/without UPC-MPN, a "hidden" per-child
 * MPN dimension) — this script does NOT touch anything outside that file.
 *
 * SAFETY MODEL:
 *   - ItemSpecifics is a FULL REPLACE on eBay's side: whatever we don't include gets
 *     deleted. So the payload for each listing = apply_set.json's already-computed
 *     complete specifics set, with ONLY the aspects named in the test file overridden
 *     to the test's exact final_value. Nothing else moves.
 *   - Any aspect that is a listing's varied_by dimension (Size/Color/MPN/etc. on a
 *     child sku — same signal used all day in the normalization guard) is NEVER put in
 *     top-level ItemSpecifics. It goes into that child's own VariationSpecifics instead,
 *     merged the same way against review_sheet.csv's cached current per-child values —
 *     because rewriting a variation-defining value is exactly how Ethan says we lose
 *     sales history.
 *   - Every call defaults to VerifyOnly=true (eBay validates server-side, commits
 *     nothing). --live is required to actually write, and even then this script
 *     processes ONE item at a time with a y/n prompt — no batch/loop over all 4.
 *   - With no --verify or --live flag at all, this makes ZERO network calls: it just
 *     builds and prints the exact request that WOULD be sent, for review.
 *
 * Usage:
 *   php ebay/scripts/write_canary_test.php --account=dows --item=127389768412
 *   php ebay/scripts/write_canary_test.php --account=dows --item=127389768412 --verify
 *   php ebay/scripts/write_canary_test.php --account=dows --item=127389768412 --live
 *   php ebay/scripts/write_canary_test.php --account=dows --list          # show the 4 test items
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Trading\Types\ReviseItemRequestType;
use DTS\eBaySDK\Trading\Types\ItemType;
use DTS\eBaySDK\Trading\Types\NameValueListType;
use DTS\eBaySDK\Trading\Types\NameValueListArrayType;
use DTS\eBaySDK\Trading\Types\VariationsType;
use DTS\eBaySDK\Trading\Types\VariationType;

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
    $path = dirname(__DIR__) . "/data/aspects/{$categoryId}.json";
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
 * GOTCHA (Ethan, 2026-07-06): some MULTI aspects have individual allowed VALUES that
 * themselves contain a comma — e.g. Theme's picklist includes both "Cartoon" and
 * "Cartoon, TV & Movie Characters" as two DIFFERENT single entries. Blindly splitting on
 * every comma would wrongly turn the second into ["Cartoon", "TV & Movie Characters"].
 * So before splitting, check the category's real schema (loadAspectSchema): if the WHOLE
 * value already exactly matches one allowed entry, it's one value — never split it.
 */
function buildSpecifics(array $assoc, array $multiAspects = [], string $categoryId = ''): NameValueListArrayType
{
    $schema = $categoryId !== '' ? loadAspectSchema($categoryId) : [];
    return new NameValueListArrayType([
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
                return new NameValueListType(['Name' => $aspect, 'Value' => $values]);
            },
            array_keys($assoc), array_values($assoc)
        ),
    ]);
}

$opts    = getopt('', ['account:', 'item:', 'list', 'verify', 'live', 'help']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php write_canary_test.php --account=dows [--item=ITEMID] [--list] [--verify] [--live]\n");
    exit(0);
}

function readCsv(string $path): array
{
    $rows = []; if (!is_file($path)) { return $rows; }
    $fh = fopen($path, 'r'); $h = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) { $rows[] = array_combine($h, $r); }
    fclose($fh);
    return $rows;
}

$testPath = $dir . '/canary_test_ids.csv';
if (!is_file($testPath)) { fwrite(STDERR, "not found: $testPath\n"); exit(1); }
$testRows = readCsv($testPath);

$applySet = json_decode((string) file_get_contents($dir . '/apply_set.json'), true) ?: [];

// current per-(item,sku) variation baseline, from review_sheet.csv's source=variation rows
$varBaseline = []; // [item_id][sku][aspect] = current_value
$varyByOfItem = []; // [item_id][aspect_lower] = true  (this item's variation-defining aspects)
$multiAspectsOfItem = []; // [item_id][aspect_lower] = true  (cardinality=MULTI for this item's category)
foreach (readCsv($dir . '/review_sheet.csv') as $r) {
    if (($r['cardinality'] ?? '') === 'MULTI') {
        $multiAspectsOfItem[$r['item_id']][strtolower($r['aspect'])] = true;
    }
    if ($r['source'] !== 'variation') { continue; }
    $varBaseline[$r['item_id']][$r['sku']][$r['aspect']] = $r['current_value'];
    $vb = trim($r['varied_by']);
    if ($vb !== '') { $varyByOfItem[$r['item_id']][strtolower($vb)] = true; }
}

$byItem = [];
foreach ($testRows as $r) { $byItem[$r['item_id']][] = $r; }

if (isset($opts['list']) || !isset($opts['item'])) {
    echo "Test items in $testPath:\n";
    foreach ($byItem as $id => $rows) {
        echo "  {$id}  ({$rows[0]['test_reason']})  — " . count($rows) . " aspect rows\n";
    }
    if (!isset($opts['item'])) {
        echo "\nPass --item=<id> to build/print (and optionally send) that item's ReviseItem request.\n";
        exit(0);
    }
}

$itemId = (string) $opts['item'];
if (!isset($byItem[$itemId])) { fwrite(STDERR, "item $itemId not found in $testPath\n"); exit(1); }
if (!isset($applySet[$itemId])) { fwrite(STDERR, "item $itemId has no apply_set.json baseline — run build_apply_set.php first\n"); exit(1); }

$rows       = $byItem[$itemId];
$parentSku  = $applySet[$itemId]['sku'];
$categoryId = (string) ($applySet[$itemId]['category_id'] ?? '');
$varyByAsps = $varyByOfItem[$itemId] ?? [];
$multiAsps  = $multiAspectsOfItem[$itemId] ?? [];

// split test rows: parent-sku + non-varying aspect -> ItemSpecifics; else -> per-child VariationSpecifics
$itemSpecificsOverride = [];      // aspect => value
$variationOverride     = [];      // sku => [aspect => value]
foreach ($rows as $r) {
    $isVaryBy = isset($varyByAsps[strtolower($r['aspect'])]);
    if (!$isVaryBy && $r['sku'] === $parentSku) {
        $itemSpecificsOverride[$r['aspect']] = $r['final_value'];
    } else {
        $variationOverride[$r['sku']][$r['aspect']] = $r['final_value'];
    }
}

// final ItemSpecifics = apply_set.json's complete baseline, with test overrides applied
$finalSpecifics = $applySet[$itemId]['specifics'];
foreach ($itemSpecificsOverride as $aspect => $value) { $finalSpecifics[$aspect] = $value; }

// final VariationSpecifics per affected child sku = full cached current set, overridden
$finalVariations = [];
foreach ($variationOverride as $sku => $overrides) {
    $base = $varBaseline[$itemId][$sku] ?? [];
    foreach ($overrides as $aspect => $value) { $base[$aspect] = $value; }
    $finalVariations[$sku] = $base;
}

echo "=== canary test: item {$itemId} ({$rows[0]['test_reason']}) ===\n";
echo "parent sku: {$parentSku}\n";
echo "varied_by aspects for this item: " . (empty($varyByAsps) ? '(none — solo listing)' : implode(', ', array_keys($varyByAsps))) . "\n\n";

$aspectSchema = $categoryId !== '' ? loadAspectSchema($categoryId) : [];
echo "--- ItemSpecifics to send (full replace; " . count($itemSpecificsOverride) . " of " . count($finalSpecifics) . " values overridden by test) ---\n";
foreach ($finalSpecifics as $aspect => $value) {
    $tag = isset($itemSpecificsOverride[$aspect]) ? ' <-- TEST OVERRIDE' : '';
    $multiTag = '';
    if (isset($multiAsps[strtolower($aspect)]) && strpos((string) $value, ',') !== false) {
        $allowed = $aspectSchema[strtolower(trim($aspect))]['values'] ?? null;
        $wholeIsOneEntry = $allowed !== null && in_array(strtolower((string) $value), array_map('strtolower', $allowed), true);
        $multiTag = $wholeIsOneEntry
            ? ' [MULTI, but whole string is one allowed entry — NOT split]'
            : ' [MULTI, sent as separate values]';
    }
    printf("  %-32s %s%s%s\n", $aspect, is_scalar($value) ? $value : json_encode($value), $multiTag, $tag);
}

if (!empty($finalVariations)) {
    echo "\n--- VariationSpecifics to send, per child sku (full replace per child) ---\n";
    foreach ($finalVariations as $sku => $specifics) {
        echo "  [{$sku}]\n";
        foreach ($specifics as $aspect => $value) {
            $tag = isset($variationOverride[$sku][$aspect]) ? ' <-- TEST OVERRIDE' : '';
            printf("      %-28s %s%s\n", $aspect, $value, $tag);
        }
    }
    echo "\n  NOTE: only the child sku(s) named in the test file are included above. If this\n";
    echo "  listing has OTHER children not listed here, confirm with a read-only lookup\n";
    echo "  whether omitting them from Variations in ReviseItem leaves them untouched or\n";
    echo "  not, before ever sending with --live. This script does not assume either way.\n";
}

// ---- build the actual SDK request object (safe regardless of network access) ----
$item = new ItemType();
$item->ItemID = $itemId;
$item->ItemSpecifics = buildSpecifics($finalSpecifics, $multiAsps, $categoryId);

if (!empty($finalVariations)) {
    $variations = new VariationsType();
    $variations->Variation = [];
    foreach ($finalVariations as $sku => $specifics) {
        $v = new VariationType();
        $v->SKU = $sku;
        $v->VariationSpecifics = [buildSpecifics($specifics, $multiAsps, $categoryId)];
        $variations->Variation[] = $v;
    }
    $item->Variations = $variations;
}

$request = new ReviseItemRequestType();
$request->Item = $item;
$request->VerifyOnly = !isset($opts['live']); // default true; only --live turns this off

if (!isset($opts['verify']) && !isset($opts['live'])) {
    echo "\n(dry-run only — no network call. Pass --verify to ask eBay to validate this\n";
    echo " request without committing it, or --live to actually apply it.)\n";
    exit(0);
}

echo "\n>>> Sending to eBay (VerifyOnly=" . ($request->VerifyOnly ? 'true' : 'FALSE — THIS WILL COMMIT') . ") <<<\n";
if (!$request->VerifyOnly) {
    echo "Type the item id again to confirm you want to WRITE THIS TO PRODUCTION: ";
    $confirm = trim((string) fgets(STDIN));
    if ($confirm !== $itemId) { echo "confirmation did not match — aborted.\n"; exit(1); }
}

$client   = new EbayClient($account);
$response = $client->trading()->reviseItem($request);

echo "Ack: {$response->Ack}\n";
if ((string) $response->Ack !== 'Success') {
    foreach ($response->Errors as $e) {
        printf("  [%s] %s: %s\n", $e->SeverityCode, $e->ShortMessage, $e->LongMessage);
    }
}
