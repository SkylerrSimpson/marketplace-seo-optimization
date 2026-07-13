<?php

declare(strict_types=1);

/**
 * build_review_sheets.php — builds catalog_review_{country}.csv (title/description/
 * images, one row per SKU, + approved/reviewer_notes columns) from
 * catalog_audit_{country}.csv.
 *
 * Aspects review is a SEPARATE script now: build_aspects_review.py. It used to be
 * built here too (reading aspects_{country}.csv), but that only had rows for
 * attributes that happened to be non-blank in a Seller Center export — no way to see
 * or fill in what's missing from the same sheet, which is the whole point (matches
 * eBay's item-specifics-aspects_REVIEW.csv: every applicable aspect, filled or
 * blank, in one sheet a reviewer works down row by row). build_aspects_review.py
 * covers every attribute applicable to each SKU's product type (via the Get Spec API
 * cache, product_type_specs_{country}.json), not just what one mixed-batch export
 * happened to include.
 *
 * NOTE: this is a CURRENT-STATE audit for review, not a content-generation output —
 * there's no new_title/proposed_value column yet because nothing has been authored
 * (that's the next step, "Walmart generate new content"). This matches eBay's
 * current-live-attributes_REVIEW.csv pattern (stripped audit for review/correction),
 * not the fuller old-vs-new pattern used once an authoring pass exists.
 *
 * Output: walmart/data/{country}/output/catalog_review_{country}.csv
 *
 * Usage: php walmart/scripts/build_review_sheets.php --country=us
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['country:']);
$country = strtolower((string) ($opts['country'] ?? 'us'));
$dir     = walmart_dir($country, 'output');

function readCsv(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if ($fh === false) { return $rows; }
    // escape="" disables fgetcsv's legacy backslash-escape handling (PHP 7.4+), which
    // otherwise mis-parses RFC4180 CSV (e.g. from Python's csv module) whenever a field
    // contains a literal backslash immediately before a quote character -- confirmed:
    // aspect values pulled from the Walmart export contain raw `14\" pans`-style text.
    $header = fgetcsv($fh, 0, ',', '"', '');
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { $rows[] = array_combine($header, $r); }
    fclose($fh);
    return $rows;
}

// --- catalog review -------------------------------------------------------------
$catalogPath = $dir . "/catalog_audit_{$country}.csv";
if (!is_file($catalogPath)) {
    fwrite(STDERR, "no catalog_audit_{$country}.csv — run build_catalog_audit.php first\n");
    exit(1);
}
$catalogRows = readCsv($catalogPath);

$out = $dir . "/catalog_review_{$country}.csv";
$fh = fopen($out, 'w');
fputcsv($fh, [
    'sku', 'wpid', 'upc', 'gtin', 'title', 'confirmed_live_title', 'description',
    'brand', 'productType', 'image_urls', 'match_note', 'approved', 'reviewer_notes',
]);
foreach ($catalogRows as $r) {
    fputcsv($fh, [
        $r['sku'], $r['wpid'], $r['upc'], $r['gtin'], $r['old_title'], $r['search_title'],
        $r['description'], $r['brand'], $r['productType'], $r['image_urls'], $r['match_note'],
        '', '',
    ]);
}
fclose($fh);
printf("catalog_review: %d rows -> %s\n", count($catalogRows), $out);
echo "For the aspects review sheet, run: python3 walmart/scripts/build_aspects_review.py --country={$country}\n";
