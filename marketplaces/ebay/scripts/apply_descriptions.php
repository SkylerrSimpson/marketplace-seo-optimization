<?php

declare(strict_types=1);

/**
 * apply_descriptions.php — the live-write script for the description pipeline
 * (task "Build eBay descriptions live-write script"), mirroring apply_aspects.php's
 * proven safety model. NO re-authoring, no LLM calls — this only pushes what
 * build_description_review.php already computed into `description_review.csv`'s
 * `new_html`/`new_title` columns (which already includes the 2026-07 Prop65 badge,
 * see build_description_review.php's PROP65_BADGE_URL).
 *
 * Unlike aspects (ItemSpecifics is a full-replace), Description and Title are simple
 * scalar fields on ReviseItem — no need to reconstruct/preserve anything else on the
 * item, and no per-variation complexity (a listing has exactly one description/title
 * regardless of how many variation children it has).
 *
 * APPROVAL GATE: only writes rows where the `approved` column is a truthy marker —
 * one of yes/y/true/1/approved/approve (case-insensitive, trimmed). This is the reviewer's
 * decision column in description_review.csv, same shape as every other review sheet
 * in this pipeline (build_apply_set.php never writes an aspect without a human
 * approval either). As of 2026-07-13 every row's `approved` is blank — nothing will
 * write until that sheet comes back reviewed. See marketplaces/ebay/docs/review-rules.md and the
 * Prop65 removal plan for why the *aspect* deletion is gated on THIS script actually
 * landing descriptions live first (no window where neither surface carries the
 * Prop65 warning).
 *
 * SAFETY MODEL (same as apply_aspects.php / write_canary_test.php):
 *   - Every call defaults to VerifyOnly=true. --live is required to actually write.
 *   - Per-item confirmation: with --item=X and --live, re-type the item id.
 *   - A --live run over MORE than one item requires --confirm=WRITE as well.
 *   - No silent skip-on-error: any non-Success Ack is logged and the run continues.
 *
 * Usage:
 *   php marketplaces/ebay/scripts/apply_descriptions.php --account=dows --item=ID              # dry-run, one item
 *   php marketplaces/ebay/scripts/apply_descriptions.php --account=dows --item=ID --verify     # server validates, no commit
 *   php marketplaces/ebay/scripts/apply_descriptions.php --account=dows --item=ID --live       # writes that one item
 *   php marketplaces/ebay/scripts/apply_descriptions.php --account=dows --limit=20 --verify    # first 20 approved listings, verify-only
 *   php marketplaces/ebay/scripts/apply_descriptions.php --account=dows --live --confirm=WRITE # every approved listing in the account
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Trading\Types\ReviseItemRequestType;
use DTS\eBaySDK\Trading\Types\ItemType;

$opts    = getopt('', ['account:', 'item:', 'items:', 'limit:', 'offset:', 'exclude:', 'verify', 'live', 'confirm:', 'help']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php apply_descriptions.php --account=dows [--item=ID | --items=ID,ID,... | --offset=N --limit=N] [--exclude=ID,ID] [--verify] [--live [--confirm=WRITE]]\n");
    exit(0);
}

$reviewPath = $dir . '/description_review.csv';
if (!is_file($reviewPath)) { fwrite(STDERR, "no description_review.csv for {$account} — run build_description_review.php first\n"); exit(1); }

function isApproved(string $v): bool
{
    return in_array(strtolower(trim($v)), ['yes', 'y', 'true', '1', 'approved', 'approve'], true);
}

$fh = fopen($reviewPath, 'r');
$header = fgetcsv($fh);
$approvedSet = []; // item_id => ['new_html'=>, 'new_title'=>, 'old_title'=>]
$totalRows = 0;
while (($r = fgetcsv($fh)) !== false) {
    $row = array_combine($header, $r);
    $totalRows++;
    if (!isApproved($row['approved'])) { continue; }
    if (trim($row['new_html']) === '') { continue; } // nothing to write
    $approvedSet[$row['item_id']] = [
        'new_html'  => $row['new_html'],
        'new_title' => trim($row['new_title']),
        'old_title' => trim($row['old_title']),
    ];
}
fclose($fh);

if (!$approvedSet) {
    fwrite(STDERR, "no approved rows in {$reviewPath} ({$totalRows} total rows scanned) — nothing to write. "
        . "Fill the 'approved' column (yes/y/true/1/approved/approve) once the review comes back.\n");
    exit(1);
}

$exclude = isset($opts['exclude'])
    ? array_flip(array_filter(array_map('trim', explode(',', (string) $opts['exclude']))))
    : [];

$itemIds = array_values(array_diff(array_keys($approvedSet), array_keys($exclude)));
if (isset($opts['item'])) {
    $itemId = (string) $opts['item'];
    if (!isset($approvedSet[$itemId])) { fwrite(STDERR, "item {$itemId} is not an approved row in description_review.csv\n"); exit(1); }
    if (isset($exclude[$itemId])) { fwrite(STDERR, "item {$itemId} is in --exclude\n"); exit(1); }
    $itemIds = [$itemId];
} elseif (isset($opts['items'])) {
    $wanted = array_filter(array_map('trim', explode(',', (string) $opts['items'])));
    $unknown = array_diff($wanted, array_keys($approvedSet));
    if ($unknown !== []) { fwrite(STDERR, "not approved: " . implode(',', $unknown) . "\n"); exit(1); }
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
    $runLog = fopen($dir . '/apply_descriptions_run.csv', 'a');
    if (fstat($runLog)['size'] === 0) {
        fputcsv($runLog, ['timestamp', 'item_id', 'verify_only', 'ack', 'errors', 'title_changed']);
    }
}

$counts = ['ok' => 0, 'error' => 0];

echo "=== {$account}: " . count($approvedSet) . " approved rows found; processing " . count($itemIds) . " ===\n\n";

foreach ($itemIds as $itemId) {
    $itemId = (string) $itemId;
    $entry  = $approvedSet[$itemId];
    $titleChanged = $entry['new_title'] !== '';

    echo "=== item {$itemId} — new description (" . strlen($entry['new_html']) . " bytes)"
        . ($titleChanged ? ", title: \"{$entry['old_title']}\" -> \"{$entry['new_title']}\"" : '')
        . " ===\n";

    $item = new ItemType();
    $item->ItemID = $itemId;
    $item->Description = $entry['new_html'];
    if ($titleChanged) { $item->Title = $entry['new_title']; }

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
        fputcsv($runLog, [date('c'), $itemId, $request->VerifyOnly ? 'true' : 'false', $ack, implode(' | ', $errs), $titleChanged ? 'true' : 'false']);
        $ok ? $counts['ok']++ : $counts['error']++;
    } catch (\Throwable $e) {
        echo "  EXCEPTION: {$e->getMessage()}\n";
        fputcsv($runLog, [date('c'), $itemId, $request->VerifyOnly ? 'true' : 'false', 'EXCEPTION', $e->getMessage(), $titleChanged ? 'true' : 'false']);
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
