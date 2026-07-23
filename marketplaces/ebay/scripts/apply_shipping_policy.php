<?php

declare(strict_types=1);

/**
 * apply_shipping_policy.php — live-write script that reassigns a listing's
 * shipping policy (business policy), mirroring apply_descriptions.php's proven
 * safety model.
 *
 * Source data: shipping_policy_audit.csv (audit_shipping_policy.php). Only rows
 * with matches_criteria=yes are eligible — that column already encodes "price
 * under threshold AND currently on the --policy-contains policy". Nothing here
 * decides WHICH listings qualify; that judgment call already happened in the
 * audit and (per the human review step) in whoever approves the change.
 *
 * --target-policy-id is REQUIRED (not read from the audit file): the target is
 * the same policy for every flagged row, so the audit doesn't repeat it per
 * row — see shipping_policies.csv's matches_target_pattern column, or the
 * "target-policy candidates" line audit_shipping_policy.php prints, to find
 * the id. Requiring it explicitly here also means this script never guesses a
 * destination for a live write.
 *
 * SellerProfiles (Shipping/Return/Payment) is sent as ONE container on
 * ReviseItem — per the vary-by-safety lesson elsewhere in this codebase (never
 * assume omitting a field means "leave it alone"), this always re-fetches the
 * item's CURRENT SellerReturnProfile/SellerPaymentProfile via GetItem right
 * before revising and resends them unchanged, changing only
 * SellerShippingProfile.ShippingProfileID. Title/Description/Price/etc. are
 * left off the request entirely — ReviseItem only requires the fields you're
 * changing plus ItemID, unlike SellerProfiles' own sub-fields which we resend
 * defensively here specifically because we don't have hard confirmation eBay
 * treats a partial SellerProfiles container as a partial (not full-replace)
 * update.
 *
 * SAFETY MODEL (same as apply_descriptions.php):
 *   - Every call defaults to VerifyOnly=true (server-side validation only).
 *     --live is required to actually write.
 *   - Per-item confirmation: with --item=X and --live, re-type the item id.
 *   - A --live run over MORE than one item requires --confirm=WRITE as well.
 *   - No silent skip-on-error: any non-Success/Warning Ack is logged and the
 *     run continues to the next item.
 *
 * Usage:
 *   php marketplaces/ebay/scripts/apply_shipping_policy.php --account=dows --target-policy-id=272760555019 --item=ID              # dry-run, one item
 *   php marketplaces/ebay/scripts/apply_shipping_policy.php --account=dows --target-policy-id=272760555019 --item=ID --verify     # server validates, no commit
 *   php marketplaces/ebay/scripts/apply_shipping_policy.php --account=dows --target-policy-id=272760555019 --item=ID --live       # writes that one item
 *   php marketplaces/ebay/scripts/apply_shipping_policy.php --account=dows --target-policy-id=272760555019 --limit=20 --verify    # first 20 flagged listings, verify-only
 *   php marketplaces/ebay/scripts/apply_shipping_policy.php --account=dows --target-policy-id=272760555019 --live --confirm=WRITE # every flagged listing in the audit
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Trading\Types\ReviseItemRequestType;
use DTS\eBaySDK\Trading\Types\ItemType;
use DTS\eBaySDK\Trading\Types\SellerProfilesType;
use DTS\eBaySDK\Trading\Types\SellerShippingProfileType;
use DTS\eBaySDK\Trading\Types\SellerReturnProfileType;
use DTS\eBaySDK\Trading\Types\SellerPaymentProfileType;
use DTS\eBaySDK\Trading\Types\GetItemRequestType;

$opts    = getopt('', ['account:', 'item:', 'items:', 'limit:', 'offset:', 'exclude:', 'target-policy-id:', 'input-file:', 'policies-file:', 'verify', 'live', 'confirm:', 'help']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage:\n"
        . "  Audit-driven (single target): php apply_shipping_policy.php --account=dows --target-policy-id=N [--item=ID | --items=... | --offset=N --limit=N] [--exclude=ID,ID] [--verify] [--live [--confirm=WRITE]]\n"
        . "  Reviewed-file (per-row target): php apply_shipping_policy.php --account=dows --input-file=PATH.tsv [--policies-file=PATH.csv] [--items=... | --offset=N --limit=N] [--exclude=ID,ID] [--verify] [--live [--confirm=WRITE]]\n");
    exit(0);
}

function isFlagged(string $v): bool
{
    return strtolower(trim($v)) === 'yes';
}

$inputFile      = isset($opts['input-file']) ? (string) $opts['input-file'] : null;
$rowTargets     = [];    // item_id => target_policy_id (reviewed-file mode only)
$flaggedSet     = [];    // item_id => ['current_policy_name' => ...]
$targetPolicyId = null;  // global target (audit mode only)

if ($inputFile === null) {
    // --- Audit-driven mode (original, unchanged): one --target-policy-id for all,
    //     items gated to matches_criteria=yes rows in shipping_policy_audit.csv ----
    if (!isset($opts['target-policy-id']) || trim((string) $opts['target-policy-id']) === '') {
        fwrite(STDERR, "--target-policy-id=N is required (or use --input-file=PATH for a per-row reviewed list) — see shipping_policies.csv.\n");
        exit(1);
    }
    $targetPolicyId = trim((string) $opts['target-policy-id']);

    $auditPath = $dir . '/shipping_policy_audit.csv';
    if (!is_file($auditPath)) { fwrite(STDERR, "no shipping_policy_audit.csv for {$account} — run audit_shipping_policy.php first\n"); exit(1); }

    $fh = fopen($auditPath, 'r');
    $header = fgetcsv($fh);
    $totalRows = 0;
    while (($r = fgetcsv($fh)) !== false) {
        $row = array_combine($header, $r);
        $totalRows++;
        if (!isFlagged($row['matches_criteria'])) { continue; }
        $flaggedSet[$row['item_id']] = ['current_policy_name' => trim($row['policy_name'])];
    }
    fclose($fh);
} else {
    // --- Reviewed-file mode: "item_id<TAB>target policy NAME", one per row, first
    //     row a header. The file IS the human-reviewed approval, so it replaces the
    //     audit-flag gate; each row carries its OWN target (a file can mix targets),
    //     so there is no global --target-policy-id. Policy NAMES are resolved to ids
    //     via shipping_policies.csv (or --policies-file). Any name that doesn't
    //     resolve aborts the whole run before a single write — never guess a target.
    if (!is_file($inputFile)) { fwrite(STDERR, "--input-file not found: {$inputFile}\n"); exit(1); }

    $policiesFile = isset($opts['policies-file']) ? (string) $opts['policies-file'] : ($dir . '/shipping_policies.csv');
    if (!is_file($policiesFile)) { fwrite(STDERR, "policies map not found: {$policiesFile} (run audit_shipping_policy.php, or pass --policies-file)\n"); exit(1); }

    $nameToId = [];
    $pf = fopen($policiesFile, 'r');
    $ph = fgetcsv($pf);
    $pIdx = array_flip($ph);
    while (($r = fgetcsv($pf)) !== false) {
        $nameToId[trim($r[$pIdx['profile_name']])] = trim($r[$pIdx['profile_id']]);
    }
    fclose($pf);

    $lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    array_shift($lines); // header
    $unresolved = [];
    foreach ($lines as $line) {
        $cols = str_getcsv($line, "\t");
        $id   = trim((string) ($cols[0] ?? ''));
        $name = trim((string) ($cols[1] ?? ''));
        if ($id === '' || $name === '') { continue; }
        if (!isset($nameToId[$name])) { $unresolved[$name] = true; continue; }
        $rowTargets[$id] = $nameToId[$name];
        $flaggedSet[$id] = ['current_policy_name' => '']; // filled live from GetItem
    }
    if ($unresolved !== []) {
        fwrite(STDERR, "these target policy names in {$inputFile} don't match any profile_name in {$policiesFile}:\n  - " . implode("\n  - ", array_keys($unresolved)) . "\n");
        exit(1);
    }
    $totalRows = count($rowTargets);
}

if (!$flaggedSet) {
    fwrite(STDERR, "no flagged rows in {$auditPath} ({$totalRows} total rows scanned) — nothing to write.\n");
    exit(1);
}

$exclude = isset($opts['exclude'])
    ? array_flip(array_filter(array_map('trim', explode(',', (string) $opts['exclude']))))
    : [];

$itemIds = array_values(array_diff(array_keys($flaggedSet), array_keys($exclude)));
if (isset($opts['item'])) {
    $itemId = (string) $opts['item'];
    if (!isset($flaggedSet[$itemId])) { fwrite(STDERR, "item {$itemId} is not a flagged row in shipping_policy_audit.csv\n"); exit(1); }
    if (isset($exclude[$itemId])) { fwrite(STDERR, "item {$itemId} is in --exclude\n"); exit(1); }
    $itemIds = [$itemId];
} elseif (isset($opts['items'])) {
    $wanted = array_filter(array_map('trim', explode(',', (string) $opts['items'])));
    $unknown = array_diff($wanted, array_keys($flaggedSet));
    if ($unknown !== []) { fwrite(STDERR, "not flagged: " . implode(',', $unknown) . "\n"); exit(1); }
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
    $runLog = fopen($dir . '/apply_shipping_policy_run.csv', 'a');
    if (fstat($runLog)['size'] === 0) {
        fputcsv($runLog, ['timestamp', 'item_id', 'verify_only', 'from_policy', 'to_policy_id', 'ack', 'errors']);
    }
}

$counts = ['ok' => 0, 'error' => 0];

echo "=== {$account}: " . count($flaggedSet) . " flagged rows found; processing " . count($itemIds) . " ===\n\n";

foreach ($itemIds as $itemId) {
    $itemId = (string) $itemId;
    $entry = $flaggedSet[$itemId];
    // Per-row target in reviewed-file mode; the single global target otherwise.
    $itemTarget = $rowTargets[$itemId] ?? $targetPolicyId;

    echo "=== item {$itemId} — \"{$entry['current_policy_name']}\" -> policy {$itemTarget} ===\n";

    if (!$isVerify) {
        // pure dry-run: print and move on, no network, no confirmation needed
        continue;
    }

    // Fetch current SellerProfiles fresh so Return/Payment are resent unchanged —
    // never assume omitting a sub-field on SellerProfiles leaves it alone.
    $getReq = new GetItemRequestType();
    $getReq->ItemID = $itemId;
    $getReq->DetailLevel = ['ReturnAll'];

    try {
        $getResp = $client->trading()->getItem($getReq);
    } catch (\Throwable $e) {
        echo "  EXCEPTION (GetItem): {$e->getMessage()}\n";
        fputcsv($runLog, [date('c'), $itemId, $isLive ? 'false' : 'true', $entry['current_policy_name'], $itemTarget, 'EXCEPTION', $e->getMessage()]);
        $counts['error']++;
        continue;
    }
    if ((string) $getResp->Ack === 'Failure') {
        $msgs = [];
        foreach ($getResp->Errors ?? [] as $e) { $msgs[] = "[{$e->ErrorCode}] {$e->LongMessage}"; }
        echo "  GetItem FAILED: " . implode(' | ', $msgs) . "\n";
        fputcsv($runLog, [date('c'), $itemId, $isLive ? 'false' : 'true', $entry['current_policy_name'], $itemTarget, 'GETITEM_FAILURE', implode(' | ', $msgs)]);
        $counts['error']++;
        continue;
    }
    $currentProfiles = $getResp->Item->SellerProfiles ?? null;
    // In reviewed-file mode the current name wasn't known from an audit — capture
    // it live now so the run log's from_policy is accurate (and doubles as rollback).
    if ($entry['current_policy_name'] === '' && isset($currentProfiles->SellerShippingProfile->ShippingProfileName)) {
        $entry['current_policy_name'] = (string) $currentProfiles->SellerShippingProfile->ShippingProfileName;
    }

    $sellerProfiles = new SellerProfilesType();
    $sellerProfiles->SellerShippingProfile = new SellerShippingProfileType(['ShippingProfileID' => (int) $itemTarget]);
    if (isset($currentProfiles->SellerReturnProfile->ReturnProfileID)) {
        $sellerProfiles->SellerReturnProfile = new SellerReturnProfileType([
            'ReturnProfileID' => (int) $currentProfiles->SellerReturnProfile->ReturnProfileID,
        ]);
    }
    if (isset($currentProfiles->SellerPaymentProfile->PaymentProfileID)) {
        $sellerProfiles->SellerPaymentProfile = new SellerPaymentProfileType([
            'PaymentProfileID' => (int) $currentProfiles->SellerPaymentProfile->PaymentProfileID,
        ]);
    }

    $item = new ItemType();
    $item->ItemID = $itemId;
    $item->SellerProfiles = $sellerProfiles;

    $request = new ReviseItemRequestType();
    $request->Item = $item;
    $request->VerifyOnly = !$isLive;

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
        fputcsv($runLog, [date('c'), $itemId, $request->VerifyOnly ? 'true' : 'false', $entry['current_policy_name'], $itemTarget, $ack, implode(' | ', $errs)]);
        $ok ? $counts['ok']++ : $counts['error']++;
    } catch (\Throwable $e) {
        echo "  EXCEPTION: {$e->getMessage()}\n";
        fputcsv($runLog, [date('c'), $itemId, $request->VerifyOnly ? 'true' : 'false', $entry['current_policy_name'], $itemTarget, 'EXCEPTION', $e->getMessage()]);
        $counts['error']++;
    }

    usleep(600000);
}

if ($runLog) { fclose($runLog); }

if ($isVerify) {
    printf("\ndone: %d ok, %d error (out of %d)\n", $counts['ok'], $counts['error'], count($itemIds));
} elseif (!$isLive) {
    echo "\n(dry-run only — no network calls. Pass --verify to validate server-side\n";
    echo " without committing, or --live to actually write.)\n";
}
