<?php

declare(strict_types=1);

/**
 * build_upc_worksheet.php — the per-listing UPC worksheet requested for Ethan,
 * 2026-07-09. Joins data already collected by build_gtin_report.php (per-listing)
 * and check_upc_category_support.php (per-category) — no fresh API calls needed.
 *
 * Columns: item_id, sku, category_id, title, varied_by, upc, gtin, Essential
 *   - upc    : the custom ItemSpecific "UPC" value (the "optional" field), filled
 *              in whether or not it's actually populated — i.e. every row shows it.
 *   - gtin   : the real identifier (ProductListingDetails UPC/EAN/ISBN) — the
 *              "essential" field's actual value, blank if not set.
 *   - Essential : yes if this listing's category supports the real identifier at
 *              all (upc_support ENABLED or REQUIRED), no if upc_support=DISABLED
 *              (custom ItemSpecific is the only place UPC can live for that
 *              category — not a problem by itself). Same for every row of a given
 *              item_id (category is listing-level, not per-variation).
 *
 * No EAN/ISBN columns per request — UPC only.
 *
 * Output: ebay/data/{acct}/output/eBay_{ACCT}_upc-worksheet_REVIEW.csv
 * Usage: php ebay/scripts/build_upc_worksheet.php --account=dows
 */

require __DIR__ . '/../../lib/bootstrap.php';

function readCsv(string $path): array
{
    $rows = [];
    if (!is_file($path)) { return $rows; }
    $fh = fopen($path, 'r');
    $h = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) { $rows[] = array_combine($h, $r); }
    fclose($fh);
    return $rows;
}

$opts    = getopt('', ['account:']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');
$acctUp  = strtoupper($account);

$gtinRows = readCsv($dir . "/gtin_report_{$account}.csv");
if (!$gtinRows) { fwrite(STDERR, "no gtin_report_{$account}.csv — run build_gtin_report.php first\n"); exit(1); }

$support = [];
foreach (readCsv($dir . "/upc_category_support_{$account}.csv") as $r) {
    $support[$r['category_id']] = $r['upc_support'];
}
if (!$support) { fwrite(STDERR, "no upc_category_support_{$account}.csv — run check_upc_category_support.php first\n"); exit(1); }

$applySet = json_decode((string) file_get_contents($dir . '/apply_set.json'), true) ?: [];
$catOf = [];
foreach ($applySet as $iid => $e) { $catOf[(string) $iid] = (string) ($e['category_id'] ?? ''); }

$out = $dir . "/eBay_{$acctUp}_upc-worksheet_REVIEW.csv";
$fh = fopen($out, 'w');
fputcsv($fh, ['item_id', 'sku', 'category_id', 'title', 'varied_by', 'upc', 'gtin', 'Essential']);

$stat = ['yes' => 0, 'no' => 0, 'unknown' => 0];
foreach ($gtinRows as $r) {
    $cat = $catOf[$r['item_id']] ?? '';
    $sup = $support[$cat] ?? null;
    $essential = $sup === null ? 'unknown' : (in_array($sup, ['ENABLED', 'REQUIRED'], true) ? 'yes' : 'no');
    $stat[$essential]++;
    fputcsv($fh, [
        $r['item_id'], $r['sku'], $cat, $r['title'], $r['varied_by'],
        $r['custom_upc_specific'] ?? '', $r['gtin'], $essential,
    ]);
}
fclose($fh);

printf("done: %d rows -> %s\n  Essential=yes: %d\n  Essential=no: %d\n  unknown category: %d\n", count($gtinRows), $out, $stat['yes'], $stat['no'], $stat['unknown']);
