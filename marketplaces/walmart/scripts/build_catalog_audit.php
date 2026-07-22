<?php

declare(strict_types=1);

/**
 * build_catalog_audit.php — step 2 of the audit (title/description/images; aspects
 * come pre-attached from listings.json — see fetch_all_items.php). Reads
 * listings.json (sku/upc/gtin/title/status/aspects per item, no description/images)
 * and, for each item with a upc or gtin, calls Item Search (getSearchResult) to pull
 * the live description/images/brand — the read surface that actually carries that
 * content (see walmart/README.md "Two different Items read surfaces").
 *
 * Item Search is a catalog/keyword-style search, not a direct by-ID lookup — a query
 * can return multiple matches (other sellers' offers on the same UPC, etc). We prefer
 * the result flagged isMarketPlaceItem=true (our own listing); if none is flagged,
 * we take the first result and note match_note=unconfirmed so a human can spot-check.
 *
 * Items with neither upc nor gtin are skipped (logged separately) — Item Search only
 * accepts query/upc/gtin, not sku.
 *
 * "SEO title"/"SEO description" aren't distinct Walmart API fields (same split as
 * eBay) — title/description here ARE what gets SEO-optimized in the generate step.
 *
 * Two output files, matching the eBay split (one-row-per-listing for single-valued
 * fields, one-row-per-listing-x-aspect for multi-valued ones — NOT one wide row with
 * aspects crammed into a pipe-joined string, which was the original 2026-07-10
 * version of this script and inconsistent with every eBay review sheet):
 *
 *   catalog_audit_{country}.csv  — one row per SKU:
 *     sku, wpid, upc, gtin, old_title, search_title, description, brand,
 *     productType, image_urls, match_note
 *   aspects_{country}.csv        — one row per SKU x aspect (currently only the
 *     variant-grouping dimension is populated — see the "no read-back endpoint"
 *     note in walmart/README.md; this file is where a full Seller Center bulk
 *     export would get merged in once we have one):
 *     sku, wpid, aspect_name, aspect_value, is_variant
 *
 * Usage: php walmart/scripts/build_catalog_audit.php --country=us [--limit=N]
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/WalmartClient.php';

$opts    = getopt('', ['country:', 'limit:']);
$country = strtolower((string) ($opts['country'] ?? 'us'));
$limit   = isset($opts['limit']) ? (int) $opts['limit'] : null;

$inputDir = walmart_dir($country, 'input');
$listingsPath = $inputDir . '/listings.json';
if (!is_file($listingsPath)) {
    fwrite(STDERR, "no listings.json for '{$country}' — run fetch_all_items.php first\n");
    exit(1);
}
$listings = json_decode((string) file_get_contents($listingsPath), true) ?: [];
if ($limit !== null) { $listings = array_slice($listings, 0, $limit); }

$client = new WalmartClient($country);
$items  = $client->marketplace()->items();

$outDir = walmart_dir($country, 'output');
$out = $outDir . "/catalog_audit_{$country}.csv";
$fh = fopen($out, 'w');
fputcsv($fh, [
    'sku', 'wpid', 'upc', 'gtin', 'old_title', 'search_title', 'description',
    'brand', 'productType', 'image_urls', 'match_note',
]);

$aspectsOut = $outDir . "/aspects_{$country}.csv";
$aspectsFh = fopen($aspectsOut, 'w');
fputcsv($aspectsFh, ['sku', 'wpid', 'aspect_name', 'aspect_value', 'is_variant']);

function writeAspectRows($aspectsFh, array $listing): void
{
    foreach ($listing['aspects'] ?? [] as $a) {
        fputcsv($aspectsFh, [
            $listing['sku'] ?? '', $listing['wpid'] ?? '',
            $a['name'], $a['value'], !empty($a['isVariant']) ? 'yes' : 'no',
        ]);
    }
}

$done = 0; $errors = 0; $skipped = 0; $rows = 0;
$total = count($listings);
foreach ($listings as $listing) {
    $done++;
    $upc = (string) ($listing['upc'] ?? '');
    $gtin = (string) ($listing['gtin'] ?? '');

    writeAspectRows($aspectsFh, $listing);

    if ($upc === '' && $gtin === '') {
        $skipped++;
        fputcsv($fh, [
            $listing['sku'] ?? '', $listing['wpid'] ?? '', '', '',
            $listing['title'] ?? '', '', '', '', '', '', 'skipped-no-upc-or-gtin',
        ]);
        $rows++;
        continue;
    }

    try {
        $result = $upc !== ''
            ? $items->getSearchResult(upc: $upc)
            : $items->getSearchResult(gtin: $gtin);
    } catch (\Throwable $e) {
        $errors++;
        fwrite(STDERR, "  {$listing['sku']} (upc={$upc} gtin={$gtin}): EXCEPTION {$e->getMessage()}\n");
        if ($done % 50 === 0) { fwrite(STDOUT, "  {$done}/{$total} (errors {$errors}, skipped {$skipped}, rows {$rows})\n"); }
        usleep(200000);
        continue;
    }

    $matches = $result->getItems() ?? [];
    $chosen = null;
    $matchNote = 'no-match';
    foreach ($matches as $m) {
        if ($m->getIsMarketPlaceItem() === true) {
            $chosen = $m;
            $matchNote = 'matched-marketplace-item';
            break;
        }
    }
    if ($chosen === null && count($matches) > 0) {
        $chosen = $matches[0];
        $matchNote = 'unconfirmed-first-result';
    }

    if ($chosen === null) {
        fputcsv($fh, [
            $listing['sku'] ?? '', $listing['wpid'] ?? '', $upc, $gtin,
            $listing['title'] ?? '', '', '', '', '', '', $matchNote,
        ]);
    } else {
        $imageUrls = [];
        foreach ($chosen->getImages() ?? [] as $img) { $imageUrls[] = (string) $img->getUrl(); }

        fputcsv($fh, [
            $listing['sku'] ?? '', $listing['wpid'] ?? '', $upc, $gtin,
            $listing['title'] ?? '',
            (string) $chosen->getTitle(),
            (string) $chosen->getDescription(),
            (string) $chosen->getBrand(),
            (string) $chosen->getProductType(),
            implode(' | ', $imageUrls),
            $matchNote,
        ]);
    }
    $rows++;

    if ($done % 50 === 0) { fwrite(STDOUT, "  {$done}/{$total} (errors {$errors}, skipped {$skipped}, rows {$rows})\n"); }
    usleep(200000); // ~5/sec
}
fclose($fh);
fclose($aspectsFh);

printf("\ndone: %d listings, %d errors, %d skipped (no upc/gtin), %d rows -> %s\n  and -> %s\n", $total, $errors, $skipped, $rows, $out, $aspectsOut);
