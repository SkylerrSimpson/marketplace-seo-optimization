<?php
declare(strict_types=1);
/**
 * Normalize unit measurements in an inventory-reviewed handoff CSV (item_id,sku,aspect,
 * final_value shape — e.g. a "no features no set includes" round), so the batch can
 * go back out for a second review pass.
 *
 * The actual normalization rules (aspect scope, spelling-only unit rewriting) live in
 * lib/unit_normalizer.php, shared with normalize_review_sheet_units.php — see that file
 * for the full rule list. This script is just the I/O layer specific to the handoff-CSV
 * shape: it cross-references review_sheet.csv (a separate file) to find each item's
 * varied_by aspect, since final_value here has no varied_by column of its own.
 *
 * VARY-BY GUARD: an aspect that is the
 * variation-defining attribute for a listing (review_sheet.csv's `varied_by` column,
 * e.g. Size/Color/Style) must NEVER have its value rewritten on a child sku. eBay ties
 * sales history to the exact variation value; changing "4" -> "4 in" on a child's Size
 * effectively creates a different variation and orphans that history. So for every
 * (item_id, aspect) pair where aspect == that item's varied_by aspect, the row is left
 * completely untouched regardless of aspect scope.
 *
 * Usage:
 *   php ebay/scripts/normalize_handoff_units.php --input="ebay/handoff/returned/ebay dows first round updates, no features no set includes.csv" --account=dows
 *   php ebay/scripts/normalize_handoff_units.php --input=... --account=dows --out=path/to/round2.csv
 */

require __DIR__ . '/lib/unit_normalizer.php';

$opts    = getopt('', ['input:', 'out:', 'account:']);
$base    = dirname(__DIR__);
$input   = $opts['input'] ?? '';
$account = $opts['account'] ?? '';
if ($input === '') { fwrite(STDERR, "--input is required\n"); exit(1); }
if (!is_file($input)) { fwrite(STDERR, "input not found: $input\n"); exit(1); }
if ($account === '') { fwrite(STDERR, "--account is required (dows|ige) to load the varied_by guard from review_sheet.csv\n"); exit(1); }
$out = $opts['out'] ?? preg_replace('/\.csv$/i', '', $input) . '.unit_normalized.csv';

/* ---------- vary-by guard: item_id -> set of aspect names (lowercased) that are the
   variation-defining aspect for that listing, per review_sheet.csv ---------- */
$reviewSheetPath = "$base/data/$account/output/review_sheet.csv";
if (!is_file($reviewSheetPath)) { fwrite(STDERR, "review_sheet.csv not found for account $account: $reviewSheetPath\n"); exit(1); }
$VARY_BY = [];
$rsfh = fopen($reviewSheetPath, 'r');
$rsHeader = fgetcsv($rsfh);
$rsIdx = array_flip($rsHeader);
foreach (['item_id', 'varied_by'] as $r) {
    if (!isset($rsIdx[$r])) { fwrite(STDERR, "review_sheet.csv missing column: $r\n"); exit(1); }
}
while (($rsRow = fgetcsv($rsfh)) !== false) {
    $vb = trim($rsRow[$rsIdx['varied_by']] ?? '');
    if ($vb === '') { continue; }
    $iid = $rsRow[$rsIdx['item_id']] ?? '';
    $VARY_BY[$iid][strtolower($vb)] = true;
}
fclose($rsfh);

$scope = unitNormalizerScope();

/* ---------- drive the file ---------- */
$fh = fopen($input, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);
foreach (['item_id', 'sku', 'aspect', 'final_value'] as $r) {
    if (!isset($idx[$r])) { fwrite(STDERR, "missing column: $r\n"); exit(1); }
}

$ofh = fopen($out, 'w');
fputcsv($ofh, array_merge($header, ['unit_normalized']));

$rows = 0; $targetRows = 0; $changed = 0; $touched = []; $samples = []; $varyBySkipped = 0;
while (($row = fgetcsv($fh)) !== false) {
    $rows++;
    $itemId = $row[$idx['item_id']] ?? '';
    $aspect = $row[$idx['aspect']] ?? '';
    $value  = $row[$idx['final_value']] ?? '';
    $flag = '';

    $isVaryBy = isset($VARY_BY[$itemId][strtolower($aspect)]);
    if ($isVaryBy) { $varyBySkipped++; }

    if (!$isVaryBy && isset($scope['allTargets'][strtolower($aspect)])) {
        $targetRows++;
        [$norm, $chg] = unitNormalizerNormalize($aspect, $value, $scope['inFamily'], $scope['lbFamily']);
        if ($chg) {
            $flag = 'Yes'; $changed++;
            $touched[$aspect] = ($touched[$aspect] ?? 0) + 1;
            if (count($samples) < 40) { $samples[] = [$aspect, $value, $norm]; }
            $row[$idx['final_value']] = $norm;
        }
    }

    fputcsv($ofh, array_merge($row, [$flag]));
}
fclose($fh); fclose($ofh);

echo "input:  $input\n";
echo "out:    $out\n";
echo "rows: $rows   in-scope aspect rows: $targetRows   unit_normalized=Yes: $changed   vary-by skipped: $varyBySkipped\n\n";
echo "--- changed by aspect ---\n";
arsort($touched);
foreach ($touched as $a => $c) { printf("  %-24s %d\n", $a, $c); }
echo "\n--- sample changes (aspect | before -> after) ---\n";
foreach ($samples as $s) { printf("  %-20s %-24s -> %s\n", substr($s[0], 0, 20), $s[1], $s[2]); }
