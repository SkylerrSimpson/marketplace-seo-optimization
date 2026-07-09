<?php

declare(strict_types=1);

/**
 * write_gtin.php — write a real product identifier (UPC/EAN) to a listing's
 * ProductListingDetails via Trading ReviseItem. This is a DIFFERENT field than
 * apply_aspects.php writes (ItemSpecifics) — ProductListingDetails.UPC is the real
 * GTIN eBay uses for catalog matching, not a custom Item Specific. Requested by
 * Ethan 2026-07-09 after the GTIN report found 507 parent listings (+53 variation
 * children) with no real UPC/EAN on file at all.
 *
 * SAFETY MODEL (same shape as apply_aspects.php / write_canary_test.php):
 *   - Every call defaults to a pure dry-run: prints what would be sent, no network.
 *   - --verify does a server-side VerifyOnly round trip (no commit).
 *   - --live actually writes; a single-item live write requires retyping the item
 *     id to confirm (this script only supports single-item so far — no bulk mode,
 *     no --confirm=WRITE gate needed yet).
 *   - Refuses listings with Variations for now: GTIN may need to be set per child
 *     SKU (VariationProductListingDetails) rather than at the parent level, and
 *     that path hasn't been tested yet. Fails loudly rather than guessing.
 *   - Does NOT touch ItemSpecifics, Variations, or anything else on the listing —
 *     only Item->ItemID and Item->ProductListingDetails->UPC go in the request.
 *
 * Usage:
 *   php ebay/scripts/write_gtin.php --account=dows --item=127239950660 --upc=810058485588            # dry-run
 *   php ebay/scripts/write_gtin.php --account=dows --item=127239950660 --upc=810058485588 --verify    # server validates, no commit
 *   php ebay/scripts/write_gtin.php --account=dows --item=127239950660 --upc=810058485588 --live      # writes it
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Trading\Types\GetItemRequestType;
use DTS\eBaySDK\Trading\Types\ReviseItemRequestType;
use DTS\eBaySDK\Trading\Types\ItemType;
use DTS\eBaySDK\Trading\Types\ProductListingDetailsType;

$opts    = getopt('', ['account:', 'item:', 'upc:', 'verify', 'live', 'help']);
if (isset($opts['help']) || !isset($opts['item']) || !isset($opts['upc'])) {
    fwrite(STDOUT, "Usage: php write_gtin.php --account=dows --item=ID --upc=VALUE [--verify] [--live]\n");
    exit(isset($opts['help']) ? 0 : 1);
}
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$itemId  = (string) $opts['item'];
$upc     = trim((string) $opts['upc']);
if ($upc === '') { fwrite(STDERR, "--upc cannot be blank\n"); exit(1); }

$isLive   = isset($opts['live']);
$isVerify = isset($opts['verify']) || $isLive;

$client = new EbayClient($account);

// --- confirm current state before doing anything -----------------------------
$getReq = new GetItemRequestType();
$getReq->ItemID = $itemId;
$getReq->DetailLevel = ['ReturnAll'];
$current = $client->trading()->getItem($getReq);
if ((string) $current->Ack !== 'Success') {
    fwrite(STDERR, "could not fetch current item state — aborting, will not write blind\n");
    exit(1);
}
$item = $current->Item;
if (isset($item->Variations)) {
    fwrite(STDERR, "item {$itemId} has Variations — this script only supports non-variation listings so far. Aborting.\n");
    exit(1);
}
$currentUpc = (string) ($item->ProductListingDetails->UPC ?? '');

echo "=== item {$itemId}  (sku {$item->SKU})  —  {$item->Title} ===\n";
echo "  current UPC: " . ($currentUpc !== '' ? $currentUpc : '(none)') . "\n";
echo "  new UPC:     {$upc}\n";

if (!$isVerify) {
    echo "\n(dry-run only — no network call made to write. Pass --verify to validate\n";
    echo " server-side without committing, or --live to actually write.)\n";
    exit(0);
}

$request = new ReviseItemRequestType();
$reviseItem = new ItemType();
$reviseItem->ItemID = $itemId;
$pld = new ProductListingDetailsType();
$pld->UPC = $upc;
// IncludeeBayProductDetails defaults to true, which makes eBay try to match the UPC
// against its own Product Catalog and auto-overwrite title/description/specifics/photo
// from the catalog product if found. For a generic/private-label item with no catalog
// match, this silently drops the whole ProductListingDetails submission instead of just
// storing the raw UPC (confirmed 2026-07-09 on item 127239950660 — Success/Warning Ack,
// UPC never actually persisted). Setting this false stores the UPC as plain data with no
// catalog linking/matching attempt.
$pld->IncludeeBayProductDetails = false;
$reviseItem->ProductListingDetails = $pld;
$request->Item = $reviseItem;
$request->VerifyOnly = !$isLive;

if ($isLive) {
    echo "\nType the item id again to confirm you want to WRITE THIS TO PRODUCTION: ";
    $confirm = trim((string) fgets(STDIN));
    if ($confirm !== $itemId) { echo "confirmation did not match — aborted.\n"; exit(1); }
}

$response = $client->trading()->reviseItem($request);
$ack = (string) $response->Ack;
$ok = in_array($ack, ['Success', 'Warning'], true);
echo "\nAck: {$ack}\n";
foreach ($response->Errors ?? [] as $e) {
    echo "  [{$e->SeverityCode}] {$e->ShortMessage}: {$e->LongMessage}\n";
}
exit($ok ? 0 : 1);
