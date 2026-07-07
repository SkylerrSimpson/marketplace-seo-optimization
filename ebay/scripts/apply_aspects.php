<?php

declare(strict_types=1);

/**
 * apply_aspects.php — Stage 2 of write-back, the FULL account write (fills the "no
 * bulk write-back script yet" gap noted in ebay/README.md). Pushes apply_set.json's
 * already-computed, complete specifics set to eBay via Trading ReviseItem, one
 * listing at a time, across the whole account (or a single item / a limited slice).
 *
 * SAFETY MODEL (same as write_canary_test.php, which this shares aspect_writer.php
 * with — do not let the two drift apart):
 *   - ItemSpecifics is a FULL REPLACE on eBay's side, so the payload per listing is
 *     apply_set.json's already-merged complete specifics set (kept + added + changed,
 *     minus deleted) — never a partial diff.
 *   - Every listing that has variations gets its FULL current VariationSpecifics sent
 *     too, for every child sku, with values taken verbatim (unchanged) from
 *     review_sheet.csv's cached source=variation current_value. This never rewrites a
 *     vary-by value (the one thing that orphans sales history) and sidesteps an open
 *     question about whether eBay leaves Variations alone when the field is omitted
 *     entirely on a ReviseItem call to a variation listing — we don't rely on that
 *     undocumented-here behavior, we just always resend the unchanged current values.
 *   - Every call defaults to VerifyOnly=true. --live is required to actually write.
 *   - Per-item confirmation: with --item=X and --live, re-type the item id (same as
 *     the canary script). For a --live run over MORE than one item, you must also pass
 *     --confirm=WRITE (a second, explicit gate — there is no prompt-per-item at scale,
 *     so the blast radius has to be agreed to up front instead).
 *   - No silent skip-on-error: any non-Success Ack is logged to
 *     data/<acct>/output/apply_aspects_run.csv and the run continues to the next item.
 *
 * Usage:
 *   php ebay/scripts/apply_aspects.php --account=dows --item=126454417969            # dry-run, one item
 *   php ebay/scripts/apply_aspects.php --account=dows --item=126454417969 --verify    # server validates, no commit
 *   php ebay/scripts/apply_aspects.php --account=dows --item=126454417969 --live      # writes that one item
 *   php ebay/scripts/apply_aspects.php --account=dows --limit=20 --verify             # first 20 listings, verify-only
 *   php ebay/scripts/apply_aspects.php --account=dows --live --confirm=WRITE          # full account, all listings
 *   php ebay/scripts/apply_aspects.php --account=dows --offset=100 --limit=100 --live --confirm=WRITE --exclude=ID,ID
 *                                                                                     # batch 2 of a sequential run, skipping specific items
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';
require __DIR__ . '/lib/aspect_writer.php';

use DTS\eBaySDK\Trading\Types\ReviseItemRequestType;
use DTS\eBaySDK\Trading\Types\ItemType;
use DTS\eBaySDK\Trading\Types\VariationsType;
use DTS\eBaySDK\Trading\Types\VariationType;

$opts    = getopt('', ['account:', 'item:', 'limit:', 'offset:', 'exclude:', 'verify', 'live', 'confirm:', 'help']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php apply_aspects.php --account=dows [--item=ID | --offset=N --limit=N] [--exclude=ID,ID] [--verify] [--live [--confirm=WRITE]]\n");
    exit(0);
}

$applySet = json_decode((string) file_get_contents($dir . '/apply_set.json'), true) ?: [];
if (!$applySet) { fwrite(STDERR, "no apply_set.json for account={$account} — run build_apply_set.php first\n"); exit(1); }

[$varBaseline, $varyByOfItem, $multiAspectsOfItem] = loadVariationContext($dir . '/review_sheet.csv');

$exclude = isset($opts['exclude'])
    ? array_flip(array_filter(array_map('trim', explode(',', (string) $opts['exclude']))))
    : [];

$itemIds = array_values(array_diff(array_keys($applySet), array_keys($exclude)));
if (isset($opts['item'])) {
    $itemId = (string) $opts['item'];
    if (!isset($applySet[$itemId])) { fwrite(STDERR, "item {$itemId} not in apply_set.json\n"); exit(1); }
    if (isset($exclude[$itemId])) { fwrite(STDERR, "item {$itemId} is in --exclude\n"); exit(1); }
    $itemIds = [$itemId];
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
    $runLog = fopen($dir . '/apply_aspects_run.csv', 'a');
    if (fstat($runLog)['size'] === 0) {
        fputcsv($runLog, ['timestamp', 'item_id', 'verify_only', 'ack', 'errors']);
    }
}

$counts = ['ok' => 0, 'error' => 0];

foreach ($itemIds as $itemId) {
    $itemId     = (string) $itemId;   // numeric-string keys come back as ints
    $entry      = $applySet[$itemId];
    $parentSku  = $entry['sku'];
    $categoryId = (string) ($entry['category_id'] ?? '');
    $specifics  = $entry['specifics'];
    $multiAsps  = $multiAspectsOfItem[$itemId] ?? [];
    $children   = $varBaseline[$itemId] ?? [];   // sku => aspect => current_value, unchanged

    echo "=== item {$itemId}  (sku {$parentSku})  —  " . count($specifics) . " specifics"
        . (empty($children) ? '' : ', ' . count($children) . ' variation child(ren) unchanged')
        . " ===\n";

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
        foreach ($specifics as $aspect => $value) {
            printf("  %-32s %s\n", $aspect, is_scalar($value) ? $value : json_encode($value));
        }
        foreach ($children as $sku => $childSpecifics) {
            echo "  [{$sku}] (unchanged)\n";
            foreach ($childSpecifics as $aspect => $value) {
                printf("      %-28s %s\n", $aspect, $value);
            }
        }
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
        // eBay's Ack is Success|Warning|Failure|PartialFailure. Warning still means the
        // call succeeded (these are almost always unrelated boilerplate — e.g. "seller
        // has opted into business policies" — not a rejection of our ItemSpecifics
        // change); only Failure/PartialFailure means it didn't go through.
        $ok = in_array($ack, ['Success', 'Warning'], true);
        $errs = [];
        foreach ($response->Errors ?? [] as $e) {
            $errs[] = "[{$e->SeverityCode}] {$e->ShortMessage}: {$e->LongMessage}";
        }
        echo "  Ack: {$ack}" . (empty($errs) ? '' : "\n  " . implode("\n  ", $errs)) . "\n";
        fputcsv($runLog, [date('c'), $itemId, $request->VerifyOnly ? 'true' : 'false', $ack, implode(' | ', $errs)]);
        $ok ? $counts['ok']++ : $counts['error']++;
    } catch (\Throwable $e) {
        echo "  EXCEPTION: {$e->getMessage()}\n";
        fputcsv($runLog, [date('c'), $itemId, $request->VerifyOnly ? 'true' : 'false', 'EXCEPTION', $e->getMessage()]);
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
