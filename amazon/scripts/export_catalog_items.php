<?php

declare(strict_types=1);

/**
 * Phase 3 — Per-ASIN catalog snapshot (READ ONLY).
 *
 * Fetches the Amazon catalog view for every unique ASIN in the seller's
 * catalog using getCatalogItem. Catalog Items returns what Amazon's catalog
 * actually shows — the merged view with browse-tree classifications, sales
 * ranks, and the public attribute set. This complements the per-SKU listings
 * snapshot (Phase 2), which returns what we submitted.
 *
 * Requires Phase 1 (export_listings_report.php) to have run first so that
 * the listings_{ts}.json sidecar (SKU→ASIN map) is available.
 *
 * Writes one file per ASIN to amazon/data/{account}/input/catalog/{asin}.json.
 * Each file is the raw JSON item from the API — lossless.
 *
 * Usage:
 *   php amazon/scripts/export_catalog_items.php [--account=IGE|DOWS] [--force] [--limit=N]
 *
 * Flags:
 *   --account=  Seller account to export. Default: IGE.
 *   --force     Re-fetch and overwrite existing per-ASIN files.
 *   --limit=N   Stop after writing N ASIN files (canary mode).
 *   --help      Show this help message.
 *
 * .env keys: same Amazon block as other scripts.
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/AmazonClient.php';
require __DIR__ . '/../../lib/AmazonOperationIds.php';
require __DIR__ . '/../../lib/AmazonRateLimits.php';

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/export_catalog_items.php [--account=IGE|DOWS] [--force] [--limit=N]

Flags:
  --account=IGE|DOWS   Seller account to export. Default: IGE.
  --force              Re-fetch and overwrite existing per-ASIN files.
  --limit=N            Stop after writing N ASIN files (canary mode).
  --help               Show this help message.
HELP;
    echo PHP_EOL;
    exit(0);
}

$force   = in_array('--force', $argv ?? [], true);
$account = 'IGE';
$limit   = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$amazon     = new AmazonClient($account);
$paths      = amazon_paths($account);
$catalogApi = $amazon->connector->catalogItemsV20220401();

echo 'Account: ' . $amazon->account . PHP_EOL;

const INCLUDED_DATA = [
    'attributes',
    'classifications',
    'identifiers',
    'productTypes',
    'relationships',
    'salesRanks',
    'summaries',
    'dimensions',
    'images',
];

// ---------------------------------------------------------------------------
// Step 1: Load unique ASINs from the latest report sidecar
// ---------------------------------------------------------------------------

$reportFiles = glob($paths['reports'] . '/listings_*.json') ?: [];
if (!$reportFiles) {
    fwrite(STDERR, "No listings report found in {$paths['reports']}.\n");
    fwrite(STDERR, "Run export_listings_report.php --account={$account} first.\n");
    exit(1);
}
rsort($reportFiles);
$report = json_decode(file_get_contents($reportFiles[0]), true) ?? [];

$asins = array_values(array_unique(array_filter(
    array_column($report, 'asin1'),
    fn($a) => !empty($a),
)));

echo 'Report: ' . basename($reportFiles[0]) . PHP_EOL;
echo 'ASINs:  ' . count($asins) . PHP_EOL;

// ---------------------------------------------------------------------------
// Step 2: Fetch each ASIN
// ---------------------------------------------------------------------------

$saved   = 0;
$skipped = 0;
$errors  = 0;

foreach ($asins as $asin) {
    $successPath = $paths['catalog']        . '/' . $asin . '.json';
    $errorPath   = $paths['catalog_errors'] . '/' . $asin . '.json';

    if ((file_exists($successPath) || file_exists($errorPath)) && !$force) {
        $skipped++;
        continue;
    }

    echo '  getCatalogItem: ' . $asin . PHP_EOL;

    try {
        $item = AmazonRateLimits::retryWithBackoff(
            fn() => $catalogApi->getCatalogItem(
                asin:           $asin,
                marketplaceIds: [$amazon->marketplaceId],
                includedData:   INCLUDED_DATA,
            )->json(),
            AmazonOperationIds::GET_CATALOG_ITEM,
        );
    } catch (\Saloon\Exceptions\Request\ClientException $e) {
        $status    = $e->getStatus();
        $errorBody = json_decode($e->getResponse()->body(), true)
                  ?? ['status' => $status, 'message' => $e->getMessage()];
        $code      = $errorBody['errors'][0]['code'] ?? (string) $status;

        file_put_contents($errorPath, json_encode([
            'error'       => true,
            'http_status' => $status,
            'asin'        => $asin,
            'fetched_at'  => date('c'),
            'response'    => $errorBody,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (file_exists($successPath)) {
            unlink($successPath);
        }

        echo '  → ' . $status . ' ' . $code . PHP_EOL;
        $errors++;
        continue;
    }

    file_put_contents($successPath, json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    if (file_exists($errorPath)) {
        unlink($errorPath);
    }

    $saved++;

    AmazonRateLimits::throttle(AmazonOperationIds::GET_CATALOG_ITEM);

    if ($limit !== null && $saved >= $limit) {
        echo PHP_EOL . 'Limit reached.' . PHP_EOL;
        break;
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo PHP_EOL;
echo 'Saved:   ' . $saved . PHP_EOL;
echo 'Skipped: ' . $skipped . ' (already exist; use --force to overwrite)' . PHP_EOL;
echo 'Errors:  ' . $errors . PHP_EOL;
echo 'Files  → ' . $paths['catalog'] . PHP_EOL;
if ($errors > 0) {
    echo 'Errors → ' . $paths['catalog_errors'] . PHP_EOL;
}
