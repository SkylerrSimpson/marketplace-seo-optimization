<?php

declare(strict_types=1);

/**
 * find_two_pack_candidates.php — READ-ONLY. Scans the account's roster for
 * listings that are candidates for being turned into a new "2-pack" listing:
 * cheap, small, light single items where per-item shipping (under ~0.75lb) now
 * costs more relative to the item's own price than it should.
 *
 * ============================================================================
 * Candidate-selection pass. Nothing here writes to eBay.
 *
 * The selection filter is --max-price=20.00 (any active listing under $20);
 * weight is NOT a selection criterion. Weight is still fetched and written to
 * the output CSV for reference, and --max-weight-lbs stays available as an
 * optional gate (default: unset = no weight filtering) in case that changes.
 * See marketplaces/ebay/docs/two_pack_rules.txt for the canonical rule sheet.
 * ============================================================================
 *
 * Source data: marketplaces/ebay/data/{account}/output/skus.csv, produced by
 * export_listings.php (item_id, sku, is_variation, price, quantity). That
 * file is the cheap, no-API-call filter pass (by price); this script then
 * calls GetItem per surviving row to pull the one thing the summary doesn't
 * have — weight — plus a fresh title/category for the output sheet.
 *
 * Variation listings ARE included, but only ones that don't vary on price
 * (typically color variations) — listings where price varies per child (e.g.
 * by size) are skipped, since 2-pack pricing per variant isn't defined.
 * Detected by grouping skus.csv rows by item_id and checking whether every
 * child SKU shares the same price.
 *
 * This file is the human review artifact — every row gets
 * new_title/new_price/new_quantity PREVIEW columns (computed with the exact
 * same shared functions create_two_pack_listings.php uses to build the real
 * thing, via lib/two_pack_pricing.php, so the preview can never drift from
 * reality) plus an `approved` column defaulting to `yes`. A reviewer flips
 * specific rows to `no`; create_two_pack_listings.php reads that column back
 * and refuses to touch a `no` row under any circumstance, including an
 * explicit --item override.
 *
 * Output: marketplaces/ebay/data/{account}/output/two_pack_candidates.csv
 *   item_id, sku, title, price, weight_lbs, quantity, category_id,
 *   category_name, is_variation, new_title, new_price, new_quantity, approved,
 *   issues
 * This file is exactly what create_two_pack_listings.php --input-file expects.
 *
 * Usage:
 *   php marketplaces/ebay/scripts/find_two_pack_candidates.php --account=dows
 *   php marketplaces/ebay/scripts/find_two_pack_candidates.php --account=dows --max-price=12 --max-weight-lbs=0.75
 *   php marketplaces/ebay/scripts/find_two_pack_candidates.php --account=dows --limit=25   # smoke test, fewer GetItem calls
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';
require __DIR__ . '/lib/two_pack_pricing.php';
require __DIR__ . '/lib/two_pack_title_shrink.php';

use DTS\eBaySDK\Trading\Types\GetItemRequestType;

$opts = getopt('', ['account:', 'max-price:', 'max-weight-lbs:', 'limit:', 'no-ai-shrink', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php find_two_pack_candidates.php --account=dows|ige [--max-price=15.00] [--max-weight-lbs=0.75] [--limit=N] [--no-ai-shrink]\n");
    exit(0);
}

$account = strtolower((string) ($opts['account'] ?? 'dows'));

// Titles that overflow 80 chars after the "2 Pack " prefix get an AI-assisted
// shrink (preserves meaning) rather than a blind chop, if ANTHROPIC_API_KEY is
// configured. Falls back to truncate+flag when it's not (or --no-ai-shrink
// forces that path for a fast/free run) — see lib/two_pack_title_shrink.php.
$anthropicClient = isset($opts['no-ai-shrink']) ? null : two_pack_anthropic_client_or_null();
if ($anthropicClient === null && !isset($opts['no-ai-shrink'])) {
    echo "note: ANTHROPIC_API_KEY not set — overflowing titles will be truncated + flagged, not AI-shortened.\n\n";
}

// max-price defaults to 20.00. max-weight-lbs is not gated by default — pass
// it explicitly if a weight cutoff is needed.
$maxPrice     = isset($opts['max-price']) ? (float) $opts['max-price'] : 20.00;
$maxWeightLbs = isset($opts['max-weight-lbs']) ? (float) $opts['max-weight-lbs'] : null;
$limit        = isset($opts['limit']) ? (int) $opts['limit'] : null;

$dir = ebay_dir($account, 'output');
$summaryPath = $dir . '/skus.csv';
if (!is_file($summaryPath)) {
    fwrite(STDERR, "no skus.csv for {$account} — run export_listings.php first.\n");
    exit(1);
}

// --- Pass 1: cheap, no-API-call filter by price from the existing export ----
// Group by item_id first: a non-variation listing is a group of 1, a
// variation listing is a group of N child-SKU rows all sharing the same
// item_id. This is what lets us test "does this listing vary on price?"
// without any extra API calls.
$fh = fopen($summaryPath, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);
$byItem = [];
while (($r = fgetcsv($fh)) !== false) {
    $itemId = (string) $r[$idx['item_id']];
    $byItem[$itemId][] = [
        'sku' => (string) $r[$idx['sku']],
        'price' => (float) $r[$idx['price']],
        'is_variation' => (string) $r[$idx['is_variation']] === '1',
    ];
}
fclose($fh);

$roster = [];
$skippedPriceVaries = 0;
foreach ($byItem as $itemId => $rows) {
    $prices = array_unique(array_map(fn ($r) => round($r['price'], 2), $rows));
    if (count($prices) > 1) {
        // varies by price (e.g. sizes) — not a 2-pack candidate, skip.
        $skippedPriceVaries++;
        continue;
    }
    $price = array_values($prices)[0];
    if ($price <= 0 || $price > $maxPrice) {
        continue;
    }
    $isVariation = count($rows) > 1 || $rows[0]['is_variation'];
    $roster[] = [
        // PHP silently coerces a numeric-string array key like "365879950574"
        // back into an int on read (foreach ($byItem as $itemId => ...) above)
        // even though it was explicitly (string)-cast when written — re-cast
        // here or the SDK later rejects it (Expected string but got integer).
        // Caught 2026-07-22 on a real run against production DOWS data.
        'item_id' => (string) $itemId,
        'sku' => $isVariation ? $rows[0]['sku'] . ' (+' . (count($rows) - 1) . ' more variants)' : $rows[0]['sku'],
        'price' => $price,
        'is_variation' => $isVariation,
    ];
}

if ($limit !== null) {
    $roster = array_slice($roster, 0, $limit);
}

echo "=== {$account}: " . count($roster) . " candidates under \${$maxPrice} (of which {$skippedPriceVaries} variation listings skipped for varying by price) — checking weight via GetItem ===\n\n";

// --- Pass 2: GetItem per surviving row to pull real weight + title/category -
$client = new EbayClient($account);

$out = fopen($dir . '/two_pack_candidates.csv', 'w');
fputcsv($out, [
    'item_id', 'sku', 'title', 'price', 'weight_lbs', 'quantity', 'category_id', 'category_name', 'is_variation',
    'new_title', 'new_price', 'new_quantity', 'approved', 'issues',
]);

// Matches "2 Pack", "2-Pack", "3pk", etc. in the SOURCE title — a listing
// that already describes itself as a multi-pack of something. Blindly
// prefixing "2 Pack " onto one of these produces a nonsensical duplicated
// title (e.g. "2 Pack ASR Outdoor 2 Pack Gold Panning Pan") — found on a
// real run against DOWS data 2026-07-22 (11 of 720 candidates, 5 of which
// wouldn't otherwise have been flagged for anything). Flagged via `issues`
// rather than silently fixed — the correct new title/quantity multiplier
// for an already-multi-pack source is a judgment call, not a mechanical one
// (e.g. a 4-piece source becomes an 8-piece as a 2-pack).
const ALREADY_PACK_PATTERN = '/\b\d+\s*-?\s*p(?:a)?ck\b/i';

$counts = ['kept' => 0, 'too_heavy' => 0, 'no_weight' => 0, 'error' => 0, 'title_issues' => 0, 'already_pack' => 0];

foreach ($roster as $row) {
    $req = new GetItemRequestType();
    $req->ItemID = $row['item_id'];
    $req->DetailLevel = ['ReturnAll'];

    try {
        $resp = $client->trading()->getItem($req);
    } catch (\Throwable $e) {
        echo "  {$row['item_id']}: EXCEPTION {$e->getMessage()}\n";
        $counts['error']++;
        usleep(450000);
        continue;
    }
    if ((string) $resp->Ack === 'Failure') {
        $counts['error']++;
        usleep(450000);
        continue;
    }

    $item = $resp->Item;
    $pkg = $item->ShippingPackageDetails ?? null;
    $major = isset($pkg->WeightMajor->value) ? (float) $pkg->WeightMajor->value : null;
    $minor = isset($pkg->WeightMinor->value) ? (float) $pkg->WeightMinor->value : null;

    if ($major === null && $minor === null) {
        $counts['no_weight']++;
        usleep(450000);
        continue; // can't judge a weight threshold without a weight
    }
    $weightLbs = ($major ?? 0.0) + (($minor ?? 0.0) / 16.0);

    if ($maxWeightLbs !== null && $weightLbs > $maxWeightLbs) {
        $counts['too_heavy']++;
        usleep(450000);
        continue;
    }

    // Item-level Quantity isn't meaningful for a variation listing — the real
    // per-child quantities live under Item->Variations.
    $quantity = (int) ($item->Quantity ?? 0);
    $newQuantity = twoPackQuantity($quantity);
    if ($row['is_variation'] && isset($item->Variations->Variation)) {
        // ->Variation comes back as the SDK's RepeatableType (Iterator +
        // Countable, but not a plain array) — foreach works, array_map doesn't.
        $children = iterator_to_array($item->Variations->Variation);
        $quantity = array_sum(array_map(fn ($v) => (int) ($v->Quantity ?? 0), $children));
        // Must halve PER CHILD and sum, not sum-then-halve — floor(5/2) +
        // floor(1/2) = 2, not floor(6/2) = 3. Matches exactly what
        // buildTwoPackVariations() will actually do, so this preview never
        // overstates what create_two_pack_listings.php produces.
        $newQuantity = array_sum(array_map(fn ($v) => twoPackQuantity((int) ($v->Quantity ?? 0)), $children));
    }

    $sourceTitle = (string) ($item->Title ?? '');
    $titleShrink = twoPackTitleWithShrink($sourceTitle, $anthropicClient);

    $issues = [];
    if ($titleShrink['issue'] !== null) {
        $issues[] = $titleShrink['issue'];
        $counts['title_issues']++;
    }
    if (preg_match(ALREADY_PACK_PATTERN, $sourceTitle, $m)) {
        $issues[] = "source title already mentions \"{$m[0]}\" — verify the 2x price/quantity multiplier is still right before approving";
        $counts['already_pack']++;
    }

    fputcsv($out, [
        $row['item_id'],
        $row['sku'],
        $sourceTitle,
        $row['price'],
        round($weightLbs, 3),
        (string) $quantity,
        (string) ($item->PrimaryCategory->CategoryID ?? ''),
        (string) ($item->PrimaryCategory->CategoryName ?? ''),
        $row['is_variation'] ? '1' : '0',
        $titleShrink['title'],
        number_format(computeTwoPackPrice($row['price']), 2, '.', ''),
        (string) $newQuantity,
        'yes',
        implode('; ', $issues),
    ]);
    $counts['kept']++;
    echo "  {$row['item_id']}: KEPT ({$weightLbs}lbs, \${$row['price']})\n";

    usleep(450000);
}
fclose($out);

printf(
    "\ndone: %d kept, %d too heavy, %d no weight set, %d errors (out of %d checked)\n",
    $counts['kept'], $counts['too_heavy'], $counts['no_weight'], $counts['error'], count($roster)
);
if ($counts['title_issues'] > 0 || $counts['already_pack'] > 0) {
    printf(
        "issues column: %d titles truncated (couldn't fit/shorten), %d source titles already mention a pack — see the issues column\n",
        $counts['title_issues'], $counts['already_pack']
    );
}
echo "report: ebay/data/{$account}/output/two_pack_candidates.csv\n";
