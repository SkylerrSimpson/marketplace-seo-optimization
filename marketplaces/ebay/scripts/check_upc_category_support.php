<?php

declare(strict_types=1);

/**
 * check_upc_category_support.php — requested 2026-07-09, the missing piece to close
 * out the UPC/GTIN work: per-category, does eBay support/require the REAL identifier
 * (ProductListingDetails.UPC/EAN/ISBN — the "essential" field) at all?
 *
 * The old Trading API signal for this (GetCategoryFeatures' UPCEnabled/EANEnabled/
 * ISBNEnabled) is DEAD — that call was deprecated and decommissioned 2026-05-04 (we're
 * past that date); it now returns Ack=Success with an empty/useless payload instead of
 * an error, which is exactly what tripped us up during today's investigation. The real,
 * current signal is the Sell Metadata API's getCategoryPolicies (REST, app-authorized
 * same as Taxonomy): GET /sell/metadata/v1/marketplace/{marketplaceId}/get_category_policies
 * -> categoryPolicies[].{upcSupport,eanSupport,isbnSupport} each one of
 * DISABLED | ENABLED | REQUIRED.
 *
 * Confirmed against two known-behavior categories before trusting this signal:
 *   - category 46282: upcSupport=ENABLED — and writing ProductListingDetails.UPC there
 *     genuinely persisted (item 124443107633, confirmed earlier today).
 *   - category 15913: upcSupport=DISABLED — and writing ProductListingDetails.UPC there
 *     silently no-op'd (item 127239950660); only the custom ItemSpecific "UPC" route
 *     actually persisted a value.
 *
 * What this means for fixing a listing's missing UPC, per category:
 *   - REQUIRED / ENABLED -> write via ProductListingDetails.UPC (write_gtin.php).
 *     REQUIRED additionally means eBay's own listing form won't let a value be blank —
 *     it must be a real UPC or the literal "Does not apply".
 *   - DISABLED -> ProductListingDetails is a dead end for this category. If a UPC value
 *     belongs on the listing at all, it can only live as a plain custom ItemSpecific
 *     "UPC" — which is NOT a problem by itself, it only becomes a problem if BOTH a
 *     custom ItemSpecific "UPC" AND a real identifier exist on the same listing (see
 *     gtin_report_{acct}.csv's upc_duplicated column).
 *
 * Output: ebay/data/{acct}/output/upc_category_support_{acct}.csv
 *   category_id, category_path, listing_count, upc_support, ean_support, isbn_support
 *
 * Usage: php ebay/scripts/check_upc_category_support.php --account=dows
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

$opts    = getopt('', ['account:']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');

// distinct category ids in use + listing counts, from apply_set.json (authoritative
// per-listing category assignment) and category_coverage.csv (path, for readability)
$applySet = json_decode((string) file_get_contents($dir . '/apply_set.json'), true) ?: [];
$counts = [];
foreach ($applySet as $entry) {
    $cat = (string) ($entry['category_id'] ?? '');
    if ($cat !== '') { $counts[$cat] = ($counts[$cat] ?? 0) + 1; }
}

$paths = [];
$covFile = $dir . '/category_coverage.csv';
if (is_file($covFile)) {
    $fh = fopen($covFile, 'r');
    $header = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        $row = array_combine($header, $row);
        $paths[$row['category_id']] = $row['category_path'];
    }
    fclose($fh);
}

$categoryIds = array_keys($counts);
sort($categoryIds, SORT_NUMERIC);
echo 'checking ' . count($categoryIds) . " categories for account={$account}\n";

$client = new EbayClient($account);
$policies = [];   // category_id => [upc, ean, isbn]
$batchSize = 40;
for ($i = 0; $i < count($categoryIds); $i += $batchSize) {
    $batch = array_slice($categoryIds, $i, $batchSize);
    $filter = 'categoryIds:%7B' . implode(',', $batch) . '%7D';
    $url = "https://api.ebay.com/sell/metadata/v1/marketplace/EBAY_US/get_category_policies?filter={$filter}";
    $res = $client->userSend('GET', $url, null, ['Accept' => 'application/json']);
    if ($res['status'] !== 200 || !is_array($res['json'])) {
        fwrite(STDERR, "  batch starting at {$batch[0]}: HTTP {$res['status']}\n");
        continue;
    }
    foreach ($res['json']['categoryPolicies'] ?? [] as $cp) {
        $policies[(string) $cp['categoryId']] = [
            'upc'  => $cp['upcSupport'] ?? '',
            'ean'  => $cp['eanSupport'] ?? '',
            'isbn' => $cp['isbnSupport'] ?? '',
        ];
    }
    echo '  ' . min($i + $batchSize, count($categoryIds)) . '/' . count($categoryIds) . "\n";
    usleep(300000);
}

$out = $dir . "/upc_category_support_{$account}.csv";
$fh = fopen($out, 'w');
fputcsv($fh, ['category_id', 'category_path', 'listing_count', 'upc_support', 'ean_support', 'isbn_support']);

// sort by listing_count desc so the categories affecting the most listings are on top
uasort($counts, fn ($a, $b) => $b <=> $a);
$stat = ['DISABLED' => 0, 'ENABLED' => 0, 'REQUIRED' => 0, 'unknown' => 0];
foreach ($counts as $cat => $n) {
    $p = $policies[$cat] ?? null;
    $upc = $p['upc'] ?? '';
    fputcsv($fh, [$cat, $paths[$cat] ?? '', $n, $upc, $p['ean'] ?? '', $p['isbn'] ?? '']);
    $stat[$upc] = ($stat[$upc] ?? 0) + 1;
    if ($p === null) { $stat['unknown']++; }
}
fclose($fh);

printf(
    "\ndone: %d categories -> %s\n  upcSupport DISABLED: %d categories\n  upcSupport ENABLED: %d categories\n  upcSupport REQUIRED: %d categories\n  unresolved: %d categories\n",
    count($counts), $out, $stat['DISABLED'], $stat['ENABLED'], $stat['REQUIRED'], $stat['unknown']
);
