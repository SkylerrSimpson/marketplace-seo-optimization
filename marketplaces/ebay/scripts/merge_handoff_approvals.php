<?php

declare(strict_types=1);

/**
 * merge_handoff_approvals.php — fold a human reviewer's returned handoff CSV
 * (item_id,sku,aspect,final_value[,unit_normalized]) back into review_sheet.csv's
 * approved_value column, and back it up first.
 *
 * Join key is (item_id, sku, nrm(aspect)) — NOT (item_id, aspect) alone, since the
 * same aspect repeats once per child sku on variation rows in review_sheet.csv.
 *
 * blank_value semantics (confirmed with the inventory reviewer, 2026-07-03): it means
 * the attribute genuinely doesn't apply to this product and eBay should show nothing
 * for it — not "unreviewed". For a `source=current` row (the aspect is LIVE on eBay
 * today) that means the value must be deleted, so we write approved_value=DELETE,
 * which build_apply_set.php already treats as an explicit removal. For any other
 * source (a gap we were deciding whether to fill), the literal string 'blank_value' is
 * passed through unchanged — build_apply_set.php already clears it to a no-op fill
 * there; converting it to DELETE would be wrong (there's nothing live to delete).
 *
 * Never touches any column but approved_value. Rows whose key collides with more than
 * one review_sheet.csv row (a handful of pre-existing duplicate-key rows) are
 * logged-and-skipped rather than guessed on. A row whose approved_value is already
 * non-blank and differs from the incoming value is logged as a conflict and left alone
 * unless --force is passed.
 *
 * Usage:
 *   php ebay/scripts/merge_handoff_approvals.php --account=dows --input=path/to/handoff.csv [--dry-run] [--force]
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['account:', 'input:', 'dry-run', 'force']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$input   = $opts['input'] ?? '';
$dryRun  = array_key_exists('dry-run', $opts);
$force   = array_key_exists('force', $opts);
if ($input === '') { fwrite(STDERR, "--input is required\n"); exit(1); }
if (!is_file($input)) { fwrite(STDERR, "input not found: $input\n"); exit(1); }

$dir = ebay_dir($account, 'output');
$rsPath = $dir . '/review_sheet.csv';
if (!is_file($rsPath)) { fwrite(STDERR, "not found: $rsPath\n"); exit(1); }

function nrm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }

// ---- load review_sheet.csv, index by (item_id, sku, nrm(aspect)) ----
$fh = fopen($rsPath, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);
foreach (['item_id', 'sku', 'aspect', 'source', 'approved_value'] as $c) {
    if (!isset($idx[$c])) { fwrite(STDERR, "review_sheet.csv missing column: $c\n"); exit(1); }
}
$rows = [];
while (($r = fgetcsv($fh)) !== false) { $rows[] = $r; }
fclose($fh);

$keyRows = []; // key -> [row indices]
foreach ($rows as $i => $r) {
    $key = $r[$idx['item_id']] . '|' . $r[$idx['sku']] . '|' . nrm($r[$idx['aspect']]);
    $keyRows[$key][] = $i;
}

// ---- load the handoff CSV ----
$hfh = fopen($input, 'r');
$hHeader = fgetcsv($hfh);
$hIdx = array_flip($hHeader);
foreach (['item_id', 'sku', 'aspect', 'final_value'] as $c) {
    if (!isset($hIdx[$c])) { fwrite(STDERR, "input missing column: $c\n"); exit(1); }
}

$audit = [];
$stat = ['applied' => 0, 'delete' => 0, 'empty-skipped' => 0, 'conflict-skipped' => 0,
    'unmatched-skipped' => 0, 'ambiguous-skipped' => 0];

while (($hr = fgetcsv($hfh)) !== false) {
    $itemId = $hr[$hIdx['item_id']];
    $sku    = $hr[$hIdx['sku']];
    $aspect = $hr[$hIdx['aspect']];
    $final  = trim($hr[$hIdx['final_value']]);
    $key    = $itemId . '|' . $sku . '|' . nrm($aspect);

    $row = ['item_id' => $itemId, 'sku' => $sku, 'aspect' => $aspect, 'source' => '',
        'prior_approved_value' => '', 'final_value_in' => $final, 'applied_approved_value' => '',
        'action' => ''];

    if ($final === '') {
        $row['action'] = 'empty-skipped';
        $stat['empty-skipped']++;
        $audit[] = $row;
        continue;
    }

    $matches = $keyRows[$key] ?? [];
    if (count($matches) === 0) {
        $row['action'] = 'unmatched-skipped';
        $stat['unmatched-skipped']++;
        $audit[] = $row;
        continue;
    }
    if (count($matches) > 1) {
        $row['action'] = 'ambiguous-skipped';
        $stat['ambiguous-skipped']++;
        $audit[] = $row;
        continue;
    }

    $i = $matches[0];
    $source = $rows[$i][$idx['source']];
    $prior  = trim($rows[$i][$idx['approved_value']]);
    $row['source'] = $source;
    $row['prior_approved_value'] = $prior;

    $newApproved = $final;
    $action = 'applied';
    if ($source === 'current' && strtolower($final) === 'blank_value') {
        $newApproved = 'DELETE';
        $action = 'delete';
    }

    if ($prior !== '' && strtolower($prior) !== strtolower($newApproved) && !$force) {
        $row['action'] = 'conflict-skipped';
        $stat['conflict-skipped']++;
        $audit[] = $row;
        continue;
    }

    $rows[$i][$idx['approved_value']] = $newApproved;
    $row['applied_approved_value'] = $newApproved;
    $row['action'] = $action;
    $stat[$action]++;
    $audit[] = $row;
}
fclose($hfh);

// ---- write review_sheet.csv (backup first) + audit CSV ----
if (!$dryRun) {
    $backup = $rsPath . '.preround1.bak';
    if (!is_file($backup)) { copy($rsPath, $backup); }

    $ofh = fopen($rsPath, 'w');
    fputcsv($ofh, $header);
    foreach ($rows as $r) { fputcsv($ofh, $r); }
    fclose($ofh);
}

$auditPath = $dir . '/handoff_round1_applied.csv';
$afh = fopen($auditPath, 'w');
fputcsv($afh, ['item_id', 'sku', 'aspect', 'source', 'prior_approved_value', 'final_value_in',
    'applied_approved_value', 'action']);
foreach ($audit as $a) {
    fputcsv($afh, [$a['item_id'], $a['sku'], $a['aspect'], $a['source'], $a['prior_approved_value'],
        $a['final_value_in'], $a['applied_approved_value'], $a['action']]);
}
fclose($afh);

echo "account: $account   input: $input" . ($dryRun ? '  [DRY RUN — review_sheet.csv NOT written]' : '') . "\n";
printf("applied: %d   delete: %d   empty-skipped: %d   conflict-skipped: %d   unmatched-skipped: %d   ambiguous-skipped: %d\n",
    $stat['applied'], $stat['delete'], $stat['empty-skipped'], $stat['conflict-skipped'],
    $stat['unmatched-skipped'], $stat['ambiguous-skipped']);
echo "audit: $auditPath\n";
if (!$dryRun) { echo "wrote: $rsPath  (backup: {$rsPath}.preround1.bak)\n"; }
