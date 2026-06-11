<?php

declare(strict_types=1);

/**
 * Phase 5 — Listings audit (READ ONLY, no API calls).
 *
 * For each SKU, cross-references three on-disk sources:
 *   1. input/listings/{sku}.json       — what we submitted to Amazon
 *   2. input/catalog/{asin}.json       — what Amazon's catalog shows
 *   3. data/schemas/{productType}.json — what Amazon requires/allows
 *
 * Compares submitted attributes against the schema's required and optional
 * property sets, combines with Amazon's own validation issues, and emits a
 * priority-sorted CSV for review.
 *
 * Priority score: (missing_required × 10) + (amazon_issue_count × 5) + (missing_recommended × 1)
 *
 * Partial audits:
 *   - Catalog missing/error: still audits from listing + schema; flags the row.
 *   - Schema missing: still reports Amazon issues; flags the row, zeroes schema columns.
 *
 * Usage:
 *   php amazon/scripts/audit_listings.php [--account=IGE|DOWS]
 *
 * Flags:
 *   --account=IGE|DOWS   Seller account to audit. Default: IGE.
 *   --help               Show this help message.
 *
 * Output:
 *   amazon/data/{account}/output/listings_audit.csv
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/AmazonClient.php';

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/audit_listings.php [--account=IGE|DOWS]

Flags:
  --account=IGE|DOWS   Seller account to audit. Default: IGE.
  --help               Show this help message.
HELP;
    echo PHP_EOL;
    exit(0);
}

$account = 'IGE';
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    }
}

$paths = amazon_paths($account);

echo 'Account: ' . $account . PHP_EOL;

// ---------------------------------------------------------------------------
// Step 1: Walk listing files
// ---------------------------------------------------------------------------

$listingFiles = glob($paths['listings'] . '/*.json') ?: [];

if (!$listingFiles) {
    fwrite(STDERR, "No listing files found in {$paths['listings']}.\n");
    fwrite(STDERR, "Run export_listings_items.php --account={$account} first.\n");
    exit(1);
}

echo 'Listings: ' . count($listingFiles) . PHP_EOL;

// ---------------------------------------------------------------------------
// Step 2: Audit each SKU
// ---------------------------------------------------------------------------

$rows = [];

foreach ($listingFiles as $listingFile) {
    $listing     = json_decode(file_get_contents($listingFile), true) ?? [];
    $sku         = $listing['sku'] ?? basename($listingFile, '.json');
    $summaries   = $listing['summaries'] ?? [];
    $summary     = reset($summaries) ?: [];
    $asin        = $summary['asin'] ?? '';
    $productType = $summary['productType'] ?? '';
    $status      = implode(',', $summary['status'] ?? []);

    // --- Catalog ---
    $catalogFile = $paths['catalog']        . '/' . $asin . '.json';
    $errorFile   = $paths['catalog_errors'] . '/' . $asin . '.json';

    if ($asin === '') {
        $catalogStatus = 'error';
        $catalogError  = 'No ASIN in listing summaries';
    } elseif (file_exists($catalogFile)) {
        $catalogStatus = 'ok';
        $catalogError  = '';
    } elseif (file_exists($errorFile)) {
        $errorData     = json_decode(file_get_contents($errorFile), true) ?? [];
        $catalogStatus = 'error';
        $catalogError  = $errorData['response']['errors'][0]['message']
                      ?? 'HTTP ' . ($errorData['http_status'] ?? '?');
    } else {
        $catalogStatus = 'error';
        $catalogError  = 'Catalog file not found — run export_catalog_items.php';
    }

    // --- Schema ---
    $schemaFile = AMAZON_SCHEMAS . '/' . $productType . '.json';

    if ($productType === '') {
        $schemaStatus    = 'error';
        $schemaError     = 'No productType in listing summaries';
        $missingRequired = [];
        $missingRecommended = [];
    } elseif (!file_exists($schemaFile)) {
        $schemaStatus    = 'missing';
        $schemaError     = "Schema not cached for {$productType} — run fetch_product_type_schemas.php";
        $missingRequired = [];
        $missingRecommended = [];
    } else {
        $schema      = json_decode(file_get_contents($schemaFile), true) ?? [];
        $schemaStatus = 'ok';
        $schemaError  = '';

        $listingAttrs   = array_keys($listing['attributes'] ?? []);
        $required       = $schema['required'] ?? [];
        $allProperties  = array_keys($schema['properties'] ?? []);
        $recommended    = array_values(array_diff($allProperties, $required));

        $missingRequired    = array_values(array_diff($required, $listingAttrs));
        $missingRecommended = array_values(array_diff($recommended, $listingAttrs));
    }

    // --- Amazon issues ---
    $issues      = $listing['issues'] ?? [];
    $issueCodes  = array_map(
        fn($i) => ($i['severity'] ?? '') . ':' . ($i['code'] ?? ''),
        $issues,
    );

    // --- Priority ---
    $priority = (count($missingRequired) * 10)
              + (count($issues) * 5)
              + (count($missingRecommended) * 1);

    $rows[] = [
        'sku'                  => $sku,
        'asin'                 => $asin,
        'product_type'         => $productType,
        'status'               => $status,
        'catalog_status'       => $catalogStatus,
        'catalog_error'        => $catalogError,
        'schema_status'        => $schemaStatus,
        'schema_error'         => $schemaError,
        'missing_required'     => count($missingRequired),
        'missing_recommended'  => count($missingRecommended),
        'top_missing'          => implode(', ', array_slice($missingRequired, 0, 5)),
        'amazon_issue_count'   => count($issues),
        'amazon_issues'        => implode(', ', $issueCodes),
        'priority'             => $priority,
    ];
}

// ---------------------------------------------------------------------------
// Step 3: Sort by priority descending
// ---------------------------------------------------------------------------

usort($rows, fn($a, $b) => $b['priority'] <=> $a['priority']);

// ---------------------------------------------------------------------------
// Step 4: Write CSV
// ---------------------------------------------------------------------------

$outFile = $paths['output'] . '/listings_audit.csv';
$fh      = fopen($outFile, 'w');

fputcsv($fh, array_keys($rows[0]));
foreach ($rows as $row) {
    fputcsv($fh, $row);
}

fclose($fh);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$errorRows   = count(array_filter($rows, fn($r) => $r['catalog_status'] === 'error' || $r['schema_status'] !== 'ok'));
$cleanRows   = count($rows) - $errorRows;
$topPriority = array_slice($rows, 0, 3);

echo PHP_EOL;
echo 'Total SKUs:    ' . count($rows) . PHP_EOL;
echo 'Full audits:   ' . $cleanRows . PHP_EOL;
echo 'Partial/error: ' . $errorRows . PHP_EOL;
echo PHP_EOL;
echo 'Top 3 by priority:' . PHP_EOL;
foreach ($topPriority as $r) {
    echo '  [' . $r['priority'] . '] ' . $r['sku'] . ' (' . $r['product_type'] . ')'
       . ' — req:' . $r['missing_required'] . ' rec:' . $r['missing_recommended']
       . ' issues:' . $r['amazon_issue_count'] . PHP_EOL;
}
echo PHP_EOL;
echo 'CSV → ' . $outFile . PHP_EOL;
