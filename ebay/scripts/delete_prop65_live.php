<?php

declare(strict_types=1);

/**
 * delete_prop65_live.php — the live eBay write that actually removes "California
 * Prop 65 Warning" from item specifics (2026-07 policy change; see
 * mark_prop65_delete.php and ebay/docs/review-rules.md §3).
 *
 * DELIBERATELY DOES NOT USE apply_set.json / apply_aspects.php's normal path.
 * Reason (confirmed by direct inspection, 2026-07-13): DOWS has 916 items where the
 * live Prop 65 aspect co-exists with an UNRELATED pending approved_value in
 * review_sheet.csv (mostly UPC / Country / Blade Length corrections from the
 * still-in-progress handoff work, task "Ethan fills UPC worksheet"). Because eBay's
 * ReviseItem replaces an item's ENTIRE ItemSpecifics set in one call, sourcing this
 * write from apply_set.json's merged specifics (which folds in approved_value) would
 * silently push those 916 items' other not-yet-finished approvals live, bundled into
 * what's supposed to be a narrow Prop65-only deletion. IGE has zero such overlap, but
 * this script uses the same safe path for both accounts for consistency.
 *
 * Instead, the outgoing ItemSpecifics for each item is built directly from that
 * item's own cached CURRENT state — ebay/data/<acct>/output/items/{id}.json's
 * `aspects` dict (the exact same representation enrich_listings.php's Browse API
 * getItem snapshot uses, and the same source review_sheet.csv's current_value column
 * is built from) — with ONLY the Prop65 key removed. Every other field is resent
 * byte-identical to what's live right now, regardless of anything sitting in
 * review_sheet.csv's approved_value for that item. VariationSpecifics (unchanged) are
 * still sourced from review_sheet.csv via loadVariationContext(), same as
 * apply_aspects.php — safe to reuse because that function reads current_value, not
 * approved_value, so it's unaffected by the entanglement too. (Confirmed 0 Prop65
 * rows are source=variation in either account — this is purely a parent-level
 * ItemSpecifics change.)
 *
 * SAFETY MODEL: identical to apply_aspects.php (VerifyOnly default, --live +
 * item-id-retype for a single item, --live --confirm=WRITE for bulk, every Ack logged).
 *
 * Usage:
 *   php ebay/scripts/delete_prop65_live.php --account=ige --item=ID                 # dry-run, one item
 *   php ebay/scripts/delete_prop65_live.php --account=ige --item=ID --verify        # server validates, no commit
 *   php ebay/scripts/delete_prop65_live.php --account=ige --item=ID --live          # writes that one item
 *   php ebay/scripts/delete_prop65_live.php --account=ige --limit=5 --verify        # first 5 affected listings, verify-only
 *   php ebay/scripts/delete_prop65_live.php --account=ige --live --confirm=WRITE    # every affected listing in the account
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';
require __DIR__ . '/lib/aspect_writer.php';
require __DIR__ . '/lib/prop65.php';

use DTS\eBaySDK\Trading\Types\ReviseItemRequestType;
use DTS\eBaySDK\Trading\Types\ItemType;
use DTS\eBaySDK\Trading\Types\VariationsType;
use DTS\eBaySDK\Trading\Types\VariationType;

$opts    = getopt('', ['account:', 'item:', 'items:', 'limit:', 'offset:', 'exclude:', 'verify', 'live', 'confirm:', 'help']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php delete_prop65_live.php --account=dows [--item=ID | --items=ID,ID,... | --offset=N --limit=N] [--exclude=ID,ID] [--verify] [--live [--confirm=WRITE]]\n");
    exit(0);
}

[$varBaseline, $varyByOfItem, $multiAspectsOfItem] = loadVariationContext($dir . '/review_sheet.csv');

// find every item with a live Prop65 aspect, from the item's own cached snapshot
// (items/{id}.json), NOT from review_sheet.csv's pending approved_value state.
$itemsDir = $dir . '/items';
$affected = []; // item_id => ['sku'=>, 'category_id'=>, 'specifics'=>(aspects minus prop65), 'removed_value'=>]
foreach (glob($itemsDir . '/*.json') ?: [] as $path) {
    $snap = json_decode((string) file_get_contents($path), true);
    if (!is_array($snap) || empty($snap['aspects']) || !is_array($snap['aspects'])) { continue; }
    $prop65Key = null;
    foreach (array_keys($snap['aspects']) as $name) {
        if (isProp65((string) $name)) { $prop65Key = $name; break; }
    }
    if ($prop65Key === null) { continue; }
    $itemId = (string) ($snap['item_id'] ?? basename($path, '.json'));
    $specifics = $snap['aspects'];
    $removedValue = $specifics[$prop65Key];
    unset($specifics[$prop65Key]);
    // Multi-SKU listings: items/{id}.json's snapshot can carry a vary-by aspect (e.g.
    // Color) at the top level too -- eBay's own flattened/default view, not a
    // deliberate parent-level value. Resending it there duplicates what's correctly
    // set per-SKU in VariationSpecifics below, and eBay rejects the whole call with
    // "Requires Unique Variation Specifics and Item Specifics" (found on
    // 365879908021, a Color-variant listing, 2026-07-14). apply_aspects.php never
    // hit this because its source, apply_set.json, is pre-filtered upstream by
    // build_apply_set.php; this script reads the raw snapshot directly instead (see
    // docblock above for why), so it needs its own exclusion here.
    $varyBy = $varyByOfItem[$itemId] ?? [];
    foreach (array_keys($specifics) as $name) {
        if (isset($varyBy[mb_strtolower($name)])) { unset($specifics[$name]); }
    }
    $affected[$itemId] = [
        'sku'           => '', // parent sku not carried in items/*.json; not needed for ReviseItem by ItemID
        'category_id'   => (string) ($snap['category_id'] ?? ''),
        'specifics'     => $specifics,
        'removed_value' => $removedValue,
    ];
}

if (!$affected) { fwrite(STDERR, "no items with a live Prop65 aspect found in {$itemsDir}\n"); exit(1); }

$exclude = isset($opts['exclude'])
    ? array_flip(array_filter(array_map('trim', explode(',', (string) $opts['exclude']))))
    : [];

$itemIds = array_values(array_diff(array_keys($affected), array_keys($exclude)));
if (isset($opts['item'])) {
    $itemId = (string) $opts['item'];
    if (!isset($affected[$itemId])) { fwrite(STDERR, "item {$itemId} has no live Prop65 aspect (nothing to delete)\n"); exit(1); }
    if (isset($exclude[$itemId])) { fwrite(STDERR, "item {$itemId} is in --exclude\n"); exit(1); }
    $itemIds = [$itemId];
} elseif (isset($opts['items'])) {
    $wanted = array_filter(array_map('trim', explode(',', (string) $opts['items'])));
    $unknown = array_diff($wanted, array_keys($affected));
    if ($unknown !== []) { fwrite(STDERR, "no live Prop65 aspect (nothing to delete): " . implode(',', $unknown) . "\n"); exit(1); }
    $itemIds = array_values(array_diff($wanted, array_keys($exclude)));
} elseif (isset($opts['offset']) || isset($opts['limit'])) {
    $itemIds = array_slice($itemIds, (int) ($opts['offset'] ?? 0), isset($opts['limit']) ? (int) $opts['limit'] : null);
}

$isLive   = isset($opts['live']);
$isVerify = isset($opts['verify']) || $isLive;
$bulkLive = $isLive && count($itemIds) > 1;

if ($bulkLive && (($opts['confirm'] ?? '') !== 'WRITE')) {
    fwrite(STDERR, "--live over " . count($itemIds) . " listings requires --confirm=WRITE as an explicit second gate.\n");
    exit(1);
}

$client = ($isVerify) ? new EbayClient($account) : null;

$runLog = null;
if ($isVerify) {
    $runLog = fopen($dir . '/delete_prop65_run.csv', 'a');
    if (fstat($runLog)['size'] === 0) {
        fputcsv($runLog, ['timestamp', 'item_id', 'verify_only', 'ack', 'errors', 'removed_value']);
    }
}

$counts = ['ok' => 0, 'error' => 0];

echo "=== {$account}: " . count($affected) . " items with a live Prop65 aspect found; processing " . count($itemIds) . " ===\n\n";

foreach ($itemIds as $itemId) {
    $itemId     = (string) $itemId;
    $entry      = $affected[$itemId];
    $categoryId = $entry['category_id'];
    $specifics  = $entry['specifics'];
    $multiAsps  = $multiAspectsOfItem[$itemId] ?? [];
    $children   = $varBaseline[$itemId] ?? [];   // sku => aspect => current_value, unchanged

    echo "=== item {$itemId} — removing: \"{$entry['removed_value']}\"  ("
        . count($specifics) . " other specifics preserved verbatim"
        . (empty($children) ? '' : ', ' . count($children) . ' variation child(ren) unchanged')
        . ") ===\n";

    $item = new ItemType();
    $item->ItemID = $itemId;
    $item->ItemSpecifics = buildSpecifics($specifics, $multiAsps, $categoryId);

    if (!empty($children)) {
        $variations = new VariationsType();
        $variations->Variation = [];
        foreach ($children as $sku => $childSpecifics) {
            $v = new VariationType();
            $v->SKU = $sku;
            $v->VariationSpecifics = [buildSpecifics($childSpecifics, $multiAsps, $categoryId)];
            $variations->Variation[] = $v;
        }
        $item->Variations = $variations;
    }

    $request = new ReviseItemRequestType();
    $request->Item = $item;
    $request->VerifyOnly = !$isLive;

    if (!$isVerify) {
        // pure dry-run: print and move on, no network, no confirmation needed
        continue;
    }

    if ($isLive && count($itemIds) === 1) {
        echo "\nType the item id again to confirm you want to WRITE THIS TO PRODUCTION: ";
        $confirm = trim((string) fgets(STDIN));
        if ($confirm !== $itemId) { echo "confirmation did not match — aborted.\n"; exit(1); }
    }

    try {
        $response = $client->trading()->reviseItem($request);
        $ack = (string) $response->Ack;
        $ok = in_array($ack, ['Success', 'Warning'], true);
        $errs = [];
        foreach ($response->Errors ?? [] as $e) {
            $errs[] = "[{$e->SeverityCode}] {$e->ShortMessage}: {$e->LongMessage}";
        }
        echo "  Ack: {$ack}" . (empty($errs) ? '' : "\n  " . implode("\n  ", $errs)) . "\n";
        fputcsv($runLog, [date('c'), $itemId, $request->VerifyOnly ? 'true' : 'false', $ack, implode(' | ', $errs), $entry['removed_value']]);
        $ok ? $counts['ok']++ : $counts['error']++;
    } catch (\Throwable $e) {
        echo "  EXCEPTION: {$e->getMessage()}\n";
        fputcsv($runLog, [date('c'), $itemId, $request->VerifyOnly ? 'true' : 'false', 'EXCEPTION', $e->getMessage(), $entry['removed_value']]);
        $counts['error']++;
    }
}

if ($runLog) { fclose($runLog); }

if ($isVerify) {
    printf("\ndone: %d ok, %d error (out of %d)\n", $counts['ok'], $counts['error'], count($itemIds));
} elseif (!$isLive) {
    echo "\n(dry-run only — no network calls. Pass --verify to validate server-side\n";
    echo " without committing, or --live to actually write.)\n";
}
