<?php

declare(strict_types=1);

/**
 * create_two_pack_listings.php — creates a NEW fixed-price listing (a "2-pack")
 * for each candidate, built from that candidate's existing single-item listing
 * via GetItem. This does not touch the source listing at all — it only ever
 * calls verifyAddFixedPriceItem/addFixedPriceItem, never ReviseItem.
 *
 * ============================================================================
 * Business rules are captured in ebay/docs/two_pack_rules.txt. Pricing
 * (2x - 20%, rounded to match/nearest the source's cents ending), quantity
 * (source/2), title prefix ("2 Pack "), description (unchanged), item specifics
 * (copied as-is + one new "Bundle Description" = "2 Pack" flag), photos (reused
 * as-is; a stamped main-image badge is a later enhancement), GTIN/UPC (reused
 * from the source), the source listing (stays live untouched — the 2-pack is a
 * new, additional listing), and variation source items
 * (buildTwoPackVariations() keeps the full variation structure, each child
 * halved/repriced the same way) are all implemented. Title overflow past eBay's
 * 80-char cap gets an AI-assisted shrink attempt in
 * find_two_pack_candidates.php (falls back to truncate+flag) — see
 * lib/two_pack_title_shrink.php; this script trusts the candidates file's
 * new_title rather than recomputing it. verifyAddFixedPriceItem is
 * architecturally a separate, read-only endpoint from addFixedPriceItem, so
 * --verify mode proves the whole path end to end against real eBay without
 * creating anything (stronger than ReviseItem's VerifyOnly flag elsewhere in
 * this codebase).
 * ============================================================================
 *
 * Input: a candidates file (default two_pack_candidates.csv, from
 * find_two_pack_candidates.php) with a header row and at least an item_id
 * column. Rows are gated by an `approved` column (defaults to yes); a row
 * explicitly marked `no` is never touched, even via --item/--items.
 *
 * SAFETY MODEL (same posture as every other write script here):
 *   - No --verify/--live: pure dry-run, prints the payload that WOULD be
 *     built, no network calls at all.
 *   - --verify: calls verifyAddFixedPriceItem — round-trips through eBay
 *     (validates category/aspects/policies, returns fee estimate), creates
 *     NOTHING. Safe to run freely.
 *   - --live: calls addFixedPriceItem for real. A single-item live run
 *     requires retyping the SOURCE item id to confirm. A live run over more
 *     than one item requires --confirm=WRITE as an explicit second gate.
 *   - No silent skip-on-error: every non-Success/Warning Ack is logged and
 *     the run continues to the next candidate.
 *
 * Usage:
 *   php ebay/scripts/create_two_pack_listings.php --account=dows --item=ID                       # dry-run, one item
 *   php ebay/scripts/create_two_pack_listings.php --account=dows --item=ID --verify               # real validation, creates nothing
 *   php ebay/scripts/create_two_pack_listings.php --account=dows --item=ID --live                 # creates that one new listing
 *   php ebay/scripts/create_two_pack_listings.php --account=dows --limit=10 --verify               # first 10 candidates, verify-only
 *   php ebay/scripts/create_two_pack_listings.php --account=dows --live --confirm=WRITE            # every candidate in the input file
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';
require __DIR__ . '/lib/two_pack_pricing.php';

use DTS\eBaySDK\Trading\Types\GetItemRequestType;
use DTS\eBaySDK\Trading\Types\ItemType;
use DTS\eBaySDK\Trading\Types\AmountType;
use DTS\eBaySDK\Trading\Types\MeasureType;
use DTS\eBaySDK\Trading\Types\ShipPackageDetailsType;
use DTS\eBaySDK\Trading\Types\AddFixedPriceItemRequestType;
use DTS\eBaySDK\Trading\Types\VerifyAddFixedPriceItemRequestType;
use DTS\eBaySDK\Trading\Types\NameValueListType;
use DTS\eBaySDK\Trading\Types\NameValueListArrayType;
use DTS\eBaySDK\Trading\Types\VariationsType;
use DTS\eBaySDK\Trading\Types\VariationType;
use DTS\eBaySDK\Trading\Types\VariationProductListingDetailsType;

$opts = getopt('', ['account:', 'input-file:', 'item:', 'items:', 'limit:', 'offset:', 'exclude:', 'verify', 'live', 'confirm:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php create_two_pack_listings.php --account=dows|ige [--input-file=PATH] [--item=ID | --items=... | --offset=N --limit=N] [--exclude=ID,ID] [--verify] [--live [--confirm=WRITE]]\n");
    exit(0);
}

$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir = ebay_dir($account, 'output');

/**
 * Builds the new listing's per-variation-child payload for a variation source
 * item — a 2-pack of the whole listing: every child is kept with the same
 * VariationSpecifics (e.g. same colors), each with quantity halved and price
 * recomputed the same way as the non-variation case. A child whose quantity
 * floors to 0 is kept (eBay allows 0-qty variations — reads as "out of
 * stock" for that option, not invalid); the caller skips the WHOLE candidate
 * only if every child floors to 0.
 *
 * Returns [VariationsType $variations, int $totalQty].
 */
function buildTwoPackVariations(VariationsType $source, string $currency): array
{
    $variations = new VariationsType();
    $variations->Variation = [];
    // same isset-guard reasoning as ConditionID etc. in buildTwoPackItem() —
    // an explicit null assignment throws on this typed property.
    if (isset($source->VariationSpecificsSet)) {
        $variations->VariationSpecificsSet = $source->VariationSpecificsSet;
    }

    $totalQty = 0;
    foreach ($source->Variation ?? [] as $child) {
        $newChild = new VariationType();
        $newChild->SKU = ((string) ($child->SKU ?? '')) !== '' ? $child->SKU . '-2PK' : null;

        // $child->VariationSpecifics comes back as the SDK's internal
        // RepeatableType wrapper, not a plain array — reassigning it directly
        // fails the setter's type check, so rebuild a plain array by iterating
        // (same access pattern already used elsewhere, e.g. build_gtin_report.php).
        $specificsBlocks = [];
        foreach ($child->VariationSpecifics ?? [] as $block) {
            $specificsBlocks[] = $block;
        }
        $newChild->VariationSpecifics = $specificsBlocks;

        $childPrice = isset($child->StartPrice->value) ? (float) $child->StartPrice->value : 0.0;
        $newChild->StartPrice = new AmountType(['value' => computeTwoPackPrice($childPrice), 'currencyID' => $currency]);

        $newQty = twoPackQuantity((int) ($child->Quantity ?? 0));
        $newChild->Quantity = $newQty;
        $totalQty += $newQty;

        // STILL OPEN: VariationProductListingDetailsType has no
        // IncludeeBayProductDetails equivalent (that flag only exists on the
        // item-level ProductListingDetailsType) — the catalog-matching
        // workaround write_gtin.php needed for a plain item's UPC hasn't been
        // verified for per-variation UPC. Reusing as-is; watch closely on the
        // first --verify run against a real variation candidate.
        $childUpc = (string) ($child->VariationProductListingDetails->UPC ?? '');
        if ($childUpc !== '') {
            $newChild->VariationProductListingDetails = new VariationProductListingDetailsType(['UPC' => $childUpc]);
        }

        $variations->Variation[] = $newChild;
    }

    return [$variations, $totalQty];
}

/**
 * Builds the new listing's payload from the SOURCE listing's own GetItem
 * response. Everything else (business policies, category, condition,
 * site/country/currency, listing duration) is copied straight from the
 * source because it SHOULD match the source, not because it's undecided.
 *
 * $approvedTitle, when given, is the candidates file's own new_title value —
 * trusted verbatim (it's what the reviewer actually approved, and may be an
 * AI-shortened or hand-edited title, not a fresh recompute — see
 * lib/two_pack_title_shrink.php and the "Load candidates" section below).
 * Re-validated for length here regardless, since a hand-edit could
 * legitimately be wrong; falls back to a fresh twoPackTitle() if missing or
 * too long rather than ever submitting an over-80-char title.
 */
function buildTwoPackItem(\DTS\eBaySDK\Trading\Types\ItemType $source, ?string $approvedTitle = null): ItemType
{
    $item = new ItemType();

    $item->Title = ($approvedTitle !== null && strlen($approvedTitle) <= 80)
        ? $approvedTitle
        : twoPackTitle((string) $source->Title);

    // Description is unchanged from the source listing.
    $item->Description = (string) $source->Description;

    $currency = (string) ($source->Currency ?? 'USD');
    $hasVariations = isset($source->Variations->Variation) && count($source->Variations->Variation) > 0;

    if ($hasVariations) {
        // Per-child SKU/price/quantity/UPC live on the Variation nodes, not
        // the Item itself — see buildTwoPackVariations().
        [$variations, ] = buildTwoPackVariations($source->Variations, $currency);
        $item->Variations = $variations;
    } else {
        $item->SKU = ((string) ($source->SKU ?? '')) !== '' ? $source->SKU . '-2PK' : null;

        // 2x price minus 20%, rounded to match the source's cents convention.
        // See computeTwoPackPrice().
        $sourcePrice = isset($source->StartPrice->value) ? (float) $source->StartPrice->value : 0.0;
        $item->StartPrice = new AmountType(['value' => computeTwoPackPrice($sourcePrice), 'currencyID' => $currency]);

        // Quantity is the source quantity divided by 2. A source qty of 1 floors
        // to 0, which can't be listed — skipped by the caller instead of guessed
        // at (see the quantity check at the call site below).
        $item->Quantity = twoPackQuantity((int) ($source->Quantity ?? 0));

        // Reuse the source listing's UPC — it's the same item.
        // IncludeeBayProductDetails=false is NOT optional: leaving it true makes
        // eBay try to catalog-match the UPC and silently drop the whole
        // ProductListingDetails submission on a no-match (see write_gtin.php) —
        // same fix applied here.
        $sourceUpc = (string) ($source->ProductListingDetails->UPC ?? '');
        if ($sourceUpc !== '') {
            $item->ProductListingDetails = new \DTS\eBaySDK\Trading\Types\ProductListingDetailsType([
                'UPC' => $sourceUpc,
                'IncludeeBayProductDetails' => false,
            ]);
        }
    }

    // Reuse the source's exact photos as-is — a 2-pack is two of the same item,
    // not a combo of different items, so there's nothing to merge. A stamped
    // "2 Pack" badge on the main image is a later, non-blocking enhancement.
    if (isset($source->PictureDetails->PictureURL)) {
        $item->PictureDetails = new \DTS\eBaySDK\Trading\Types\PictureDetailsType([
            'PictureURL' => $source->PictureDetails->PictureURL,
        ]);
    }

    // Source specifics copied as-is, plus one custom (non-category) specific
    // flagging the 2-pack. The name/value is "Bundle Description" / "2 Pack" —
    // deliberately NOT "Number of Items" or "Pack Quantity", which are real
    // schema-defined aspects in some eBay categories (and may already be set on
    // the source for an unrelated reason, e.g. a multi-piece set), so reusing
    // one risks colliding with existing meaning/validation. "Bundle Description"
    // reads naturally in the Item Specifics table and can't collide with
    // anything category-specific.
    $specifics = isset($source->ItemSpecifics->NameValueList) ? $source->ItemSpecifics->NameValueList : [];
    $specifics[] = new NameValueListType(['Name' => 'Bundle Description', 'Value' => ['2 Pack']]);
    $item->ItemSpecifics = new NameValueListArrayType(['NameValueList' => $specifics]);

    // NOT a placeholder — physically correct default: two units weigh 2x one.
    // Dimensions are left off; add PackageLength/Width/Depth here if eBay's
    // verify call comes back asking for them (calculated-shipping categories
    // sometimes require dims in addition to weight).
    if (isset($source->ShippingPackageDetails->WeightMajor->value) || isset($source->ShippingPackageDetails->WeightMinor->value)) {
        $major = (float) ($source->ShippingPackageDetails->WeightMajor->value ?? 0.0);
        $minor = (float) ($source->ShippingPackageDetails->WeightMinor->value ?? 0.0);
        $item->ShippingPackageDetails = new ShipPackageDetailsType([
            'WeightMajor' => new MeasureType(['value' => $major * 2, 'unit' => (string) ($source->ShippingPackageDetails->WeightMajor->unit ?? 'lbs')]),
            'WeightMinor' => new MeasureType(['value' => $minor * 2, 'unit' => (string) ($source->ShippingPackageDetails->WeightMinor->unit ?? 'oz')]),
        ]);
    }

    // Everything below should just MATCH the source listing — not a
    // placeholder, just copied straight across. Only assigned when actually
    // present: the SDK's setters reject an explicit null for a typed
    // property (e.g. ConditionID is a non-nullable integer) even though the
    // property was simply never set on the source — assigning `?? null`
    // unconditionally would throw instead of leaving the field unset,
    // caught via testing (would otherwise crash the whole batch run, since
    // this runs outside the per-candidate try/catch below).
    $item->PrimaryCategory = $source->PrimaryCategory;
    if (isset($source->ConditionID)) { $item->ConditionID = $source->ConditionID; }
    if (isset($source->Country)) { $item->Country = $source->Country; }
    if (isset($source->Currency)) { $item->Currency = $source->Currency; }
    if (isset($source->Site)) { $item->Site = $source->Site; }
    if (isset($source->PostalCode)) { $item->PostalCode = $source->PostalCode; }
    if (isset($source->DispatchTimeMax)) { $item->DispatchTimeMax = $source->DispatchTimeMax; }
    $item->ListingDuration = $source->ListingDuration ?? 'GTC';
    $item->ListingType = 'FixedPriceItem';
    if (isset($source->SellerProfiles)) { $item->SellerProfiles = $source->SellerProfiles; }

    return $item;
}

// --- Load candidates ---------------------------------------------------------
$inputFile = isset($opts['input-file']) ? (string) $opts['input-file'] : ($dir . '/two_pack_candidates.csv');
if (!is_file($inputFile)) {
    fwrite(STDERR, "candidates file not found: {$inputFile} — run find_two_pack_candidates.php first, or pass --input-file.\n");
    exit(1);
}

// The candidates file is the human review step: find_two_pack_candidates.php
// fills `approved=yes` for every row by default, and a reviewer flips specific
// rows to `no` before it comes back. This script only ever acts on the
// approved set; a row explicitly marked `no` is excluded even from an explicit
// --item/--items override, so the gate can't be bypassed accidentally.
$fh = fopen($inputFile, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);
if (!isset($idx['item_id'])) {
    fwrite(STDERR, "{$inputFile} has no item_id column.\n");
    exit(1);
}
// item_id is the only field this script fully trusts (re-fetched live via
// GetItem below). new_title IS trusted too, though — it's the exact title
// the reviewer approved (possibly AI-shortened, possibly hand-edited by a
// human after the fact), so it's used verbatim instead of recomputed. See
// buildTwoPackItem()'s $approvedTitle param. Kept as a SEPARATE array from
// $candidates (rather than storing it as the map's value) so an
// isset($candidates[$itemId]) existence check below can't be tripped up by
// a legitimately-null/missing title for that row.
$candidates = []; // item_id => true (informational only)
$approvedTitles = []; // item_id => approved new_title, only when non-blank
$skippedNotApproved = 0;
while (($r = fgetcsv($fh)) !== false) {
    $approvedValue = isset($idx['approved']) ? strtolower(trim((string) ($r[$idx['approved']] ?? 'yes'))) : 'yes';
    if ($approvedValue === 'no') {
        $skippedNotApproved++;
        continue;
    }
    $itemId = (string) $r[$idx['item_id']];
    $candidates[$itemId] = true;
    $approvedTitle = isset($idx['new_title']) ? trim((string) ($r[$idx['new_title']] ?? '')) : '';
    if ($approvedTitle !== '') {
        $approvedTitles[$itemId] = $approvedTitle;
    }
}
fclose($fh);

if ($skippedNotApproved > 0) {
    echo "{$skippedNotApproved} row(s) in {$inputFile} marked approved=no — excluded entirely.\n";
}

if (!$candidates) {
    fwrite(STDERR, "no approved candidates in {$inputFile} — nothing to do.\n");
    exit(1);
}

// --- Resolve which item ids to run against (same idiom as apply_shipping_policy.php) --
$exclude = isset($opts['exclude'])
    ? array_flip(array_filter(array_map('trim', explode(',', (string) $opts['exclude']))))
    : [];

$itemIds = array_values(array_diff(array_keys($candidates), array_keys($exclude)));
if (isset($opts['item'])) {
    $itemId = (string) $opts['item'];
    if (!isset($candidates[$itemId])) { fwrite(STDERR, "item {$itemId} is not an approved candidate in {$inputFile} (missing, or approved=no)\n"); exit(1); }
    $itemIds = [$itemId];
} elseif (isset($opts['items'])) {
    $wanted = array_filter(array_map('trim', explode(',', (string) $opts['items'])));
    $unknown = array_diff($wanted, array_keys($candidates));
    if ($unknown !== []) { fwrite(STDERR, "not approved candidates (missing, or approved=no): " . implode(',', $unknown) . "\n"); exit(1); }
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

$client = $isVerify ? new EbayClient($account) : null;

$runLog = null;
if ($isVerify) {
    $runLog = fopen($dir . '/create_two_pack_listings_run.csv', 'a');
    if (fstat($runLog)['size'] === 0) {
        fputcsv($runLog, ['timestamp', 'source_item_id', 'verify_only', 'new_sku', 'new_start_price', 'ack', 'new_item_id', 'errors']);
    }
}

$counts = ['ok' => 0, 'error' => 0];

echo "=== {$account}: " . count($candidates) . " candidates in {$inputFile}; processing " . count($itemIds) . " ===\n\n";

foreach ($itemIds as $sourceId) {
    $sourceId = (string) $sourceId;
    echo "=== source item {$sourceId} ===\n";

    if (!$isVerify) {
        // Pure dry-run: pull the source anyway so we can print exactly what
        // WOULD be built, but make no verify/add call.
        echo "  (dry-run only — pass --verify to validate against eBay, or --live to create)\n";
        continue;
    }

    $getReq = new GetItemRequestType();
    $getReq->ItemID = $sourceId;
    $getReq->DetailLevel = ['ReturnAll'];

    try {
        $getResp = $client->trading()->getItem($getReq);
    } catch (\Throwable $e) {
        echo "  EXCEPTION (GetItem): {$e->getMessage()}\n";
        fputcsv($runLog, [date('c'), $sourceId, $isLive ? 'false' : 'true', '', '', 'EXCEPTION', '', $e->getMessage()]);
        $counts['error']++;
        continue;
    }
    if ((string) $getResp->Ack === 'Failure') {
        $msgs = [];
        foreach ($getResp->Errors ?? [] as $e) { $msgs[] = "[{$e->ErrorCode}] {$e->LongMessage}"; }
        echo "  GetItem FAILED: " . implode(' | ', $msgs) . "\n";
        fputcsv($runLog, [date('c'), $sourceId, $isLive ? 'false' : 'true', '', '', 'GETITEM_FAILURE', '', implode(' | ', $msgs)]);
        $counts['error']++;
        continue;
    }

    $newItem = buildTwoPackItem($getResp->Item, $approvedTitles[$sourceId] ?? null);
    $isVariationItem = isset($newItem->Variations);

    // for the run log: a single SKU/price for a plain item, or a summary for
    // a variation item (whose real values live per-child on $newItem->Variations).
    $logSku = $isVariationItem ? '' : ($newItem->SKU ?? '');
    $logPrice = $isVariationItem ? '' : ($newItem->StartPrice->value ?? '');

    if ($isVariationItem) {
        $childCount = count($newItem->Variations->Variation);
        // ->Variation comes back as the SDK's RepeatableType (Iterator +
        // Countable, but not a plain array) — array_map/array_sum need a
        // real array, hence iterator_to_array() first. Caught via testing.
        $totalQty = array_sum(array_map(fn ($v) => (int) $v->Quantity, iterator_to_array($newItem->Variations->Variation)));
        $logSku = "({$childCount} variant SKUs)";
        echo "  new title: {$newItem->Title} | {$childCount} variant(s), total qty {$totalQty}\n";
        foreach ($newItem->Variations->Variation as $v) {
            echo "    SKU {$v->SKU} | price {$v->StartPrice->value} | qty {$v->Quantity}\n";
        }
    } else {
        $totalQty = $newItem->Quantity;
        echo "  new SKU: {$newItem->SKU} | title: {$newItem->Title} | price: " . ($newItem->StartPrice->value ?? '?') . "\n";
    }

    if ($totalQty < 1) {
        // Non-variation: source qty of 0 or 1 floors to 0 under the /2 rule.
        // Variation: every child floored to 0 — nothing purchasable at all.
        $reason = $isVariationItem ? 'every variation child floors to 0 under /2 rule' : 'source quantity floors to 0 under /2 rule';
        echo "  SKIPPED: quantity too low to make a 2-pack ({$reason})\n";
        fputcsv($runLog, [date('c'), $sourceId, $isLive ? 'false' : 'true', $logSku, $logPrice, 'SKIPPED_QTY', '', $reason]);
        continue;
    }

    if ($isLive && count($itemIds) === 1) {
        echo "\nType the SOURCE item id again to confirm you want to CREATE A NEW LIVE LISTING: ";
        $confirm = trim((string) fgets(STDIN));
        if ($confirm !== $sourceId) { echo "confirmation did not match — aborted.\n"; exit(1); }
    }

    try {
        if ($isLive) {
            $request = new AddFixedPriceItemRequestType();
            $request->Item = $newItem;
            $response = $client->trading()->addFixedPriceItem($request);
        } else {
            $request = new VerifyAddFixedPriceItemRequestType();
            $request->Item = $newItem;
            $response = $client->trading()->verifyAddFixedPriceItem($request);
        }

        $ack = (string) $response->Ack;
        $ok = in_array($ack, ['Success', 'Warning'], true);
        $errs = [];
        foreach ($response->Errors ?? [] as $e) {
            $errs[] = "[{$e->SeverityCode}] {$e->ShortMessage}: {$e->LongMessage}";
        }
        $newItemId = $isLive ? (string) ($response->ItemID ?? '') : '(verify-only, not created)';
        echo "  Ack: {$ack}" . ($isLive ? " | new item id: {$newItemId}" : '') . (empty($errs) ? '' : "\n  " . implode("\n  ", $errs)) . "\n";
        fputcsv($runLog, [date('c'), $sourceId, $isLive ? 'false' : 'true', $logSku, $logPrice, $ack, $newItemId, implode(' | ', $errs)]);
        $ok ? $counts['ok']++ : $counts['error']++;
    } catch (\Throwable $e) {
        echo "  EXCEPTION: {$e->getMessage()}\n";
        fputcsv($runLog, [date('c'), $sourceId, $isLive ? 'false' : 'true', $logSku, $logPrice, 'EXCEPTION', '', $e->getMessage()]);
        $counts['error']++;
    }

    usleep(600000);
}

if ($runLog) { fclose($runLog); }

if ($isVerify) {
    printf("\ndone: %d ok, %d error (out of %d)\n", $counts['ok'], $counts['error'], count($itemIds));
} elseif (!$isLive) {
    echo "\n(dry-run only — no network calls. Pass --verify to validate server-side\n";
    echo " without creating anything, or --live to actually create the new listing.)\n";
}
