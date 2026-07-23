<?php
declare(strict_types=1);
/**
 * Normalize unit measurements in review_sheet.csv's `proposed_value` column — for
 * accounts (e.g. ige) where a review round-trip hasn't happened yet, so there is no
 * separate "returned" handoff CSV. This lets our own AI-proposed values go out already
 * unit-clean, instead of waiting on a round-trip like normalize_handoff_units.php does.
 *
 * The actual normalization rules (aspect scope, spelling-only unit rewriting) live in
 * lib/unit_normalizer.php, shared with normalize_handoff_units.php — see that file for
 * the full rule list.
 *
 * VARY-BY GUARD: review_sheet.csv carries `varied_by` inline on every row (no separate
 * lookup file needed, unlike the handoff-CSV case). Any row where `aspect` matches that
 * row's own `varied_by` (case-insensitive) is left untouched — that aspect is the
 * variation-defining attribute for the listing, and rewriting it would change which
 * variation a child sku maps to on eBay and orphan its sales history.
 *
 * Usage:
 *   php marketplaces/ebay/scripts/normalize_review_sheet_units.php --account=ige [--dry-run]
 *   php marketplaces/ebay/scripts/normalize_review_sheet_units.php --input=path/to/review_sheet.csv --out=path/to/out.csv
 */

require __DIR__ . '/lib/unit_normalizer.php';

$opts    = getopt('', ['account:', 'input:', 'out:', 'dry-run']);
$base    = dirname(__DIR__);
$account = $opts['account'] ?? '';
$dryRun  = isset($opts['dry-run']);

$input = $opts['input'] ?? ($account !== '' ? "$base/data/$account/output/review_sheet.csv" : '');
if ($input === '') { fwrite(STDERR, "--account=dows|ige or --input=path is required\n"); exit(1); }
if (!is_file($input)) { fwrite(STDERR, "input not found: $input\n"); exit(1); }

$inPlace = !isset($opts['out']);
$out = $opts['out'] ?? $input;
$backup = $input . '.preunitnorm.bak';

$scope = unitNormalizerScope();

/* ---------- drive the file ---------- */
$fh = fopen($input, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);
foreach (['item_id', 'sku', 'varied_by', 'aspect', 'proposed_value'] as $r) {
    if (!isset($idx[$r])) { fwrite(STDERR, "missing column: $r\n"); exit(1); }
}

$rowsOut = [$header];
$rows = 0; $targetRows = 0; $changed = 0; $touched = []; $samples = []; $varyBySkipped = 0;
while (($row = fgetcsv($fh)) !== false) {
    $rows++;
    $varyBy = strtolower(trim($row[$idx['varied_by']] ?? ''));
    $aspect = $row[$idx['aspect']] ?? '';
    $value  = $row[$idx['proposed_value']] ?? '';
    $al = strtolower($aspect);

    $isVaryBy = $varyBy !== '' && $varyBy === $al;
    if ($isVaryBy) { $varyBySkipped++; }

    if (!$isVaryBy && isset($scope['allTargets'][$al])) {
        $targetRows++;
        [$norm, $chg] = unitNormalizerNormalize($aspect, $value, $scope['inFamily'], $scope['lbFamily']);
        if ($chg) {
            $changed++;
            $touched[$aspect] = ($touched[$aspect] ?? 0) + 1;
            if (count($samples) < 40) { $samples[] = [$aspect, $value, $norm]; }
            $row[$idx['proposed_value']] = $norm;
            if (isset($idx['reviewer_notes'])) {
                $note = 'Unit-normalized (formatting only: "' . $value . '" -> "' . $norm . '"; no value change).';
                $existing = trim($row[$idx['reviewer_notes']] ?? '');
                $row[$idx['reviewer_notes']] = $existing === '' ? $note : $existing . ' ' . $note;
            }
        }
    }

    $rowsOut[] = $row;
}
fclose($fh);

if (!$dryRun) {
    if ($inPlace && !is_file($backup)) {
        copy($input, $backup);
    }
    $ofh = fopen($out, 'w');
    foreach ($rowsOut as $row) { fputcsv($ofh, $row); }
    fclose($ofh);
}

echo "input:  $input\n";
echo "out:    " . ($dryRun ? '(dry-run, not written)' : $out) . "\n";
if ($inPlace && !$dryRun) { echo "backup: $backup\n"; }
echo "rows: $rows   in-scope aspect rows: $targetRows   proposed_value changed: $changed   vary-by skipped: $varyBySkipped\n\n";
echo "--- changed by aspect ---\n";
arsort($touched);
foreach ($touched as $a => $c) { printf("  %-24s %d\n", $a, $c); }
echo "\n--- sample changes (aspect | before -> after) ---\n";
foreach ($samples as $s) { printf("  %-20s %-24s -> %s\n", substr($s[0], 0, 20), $s[1], $s[2]); }
