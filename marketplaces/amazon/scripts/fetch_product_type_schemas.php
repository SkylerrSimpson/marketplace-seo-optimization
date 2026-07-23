<?php

declare(strict_types=1);

/**
 * Phase 4 — Product type schema cache (READ ONLY).
 *
 * Walks per-SKU listings files across all accounts, collects distinct
 * productType values from summaries, and fetches the JSON Schema document for
 * each type not already cached in marketplaces/amazon/data/schemas/.
 *
 * Schemas are shared across accounts and committed to git as a stable,
 * reviewable reference for what Amazon requires per product type. Phase 5
 * (audit) reads from this cache rather than hitting the API at runtime.
 *
 * Writes:
 *   marketplaces/amazon/data/schemas/{PRODUCT_TYPE}.json   — raw JSON Schema from Amazon
 *   marketplaces/amazon/data/schemas/_index.json           — {productType: {fetched_at, version, locale, source_url}}
 *
 * Usage:
 *   php marketplaces/amazon/scripts/fetch_product_type_schemas.php [--force]
 *
 * Flags:
 *   --force   Re-fetch and overwrite already-cached schemas.
 *   --help      Show this help message.
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
Usage: php marketplaces/amazon/scripts/fetch_product_type_schemas.php [--force]

Flags:
  --force   Re-fetch and overwrite already-cached schemas.
  --help      Show this help message.
HELP;
    echo PHP_EOL;
    exit(0);
}

$force = in_array('--force', $argv ?? [], true);

// Schemas are account-agnostic; IGE holds the developer app credentials.
$amazon = new AmazonClient('IGE');
$ptApi  = $amazon->connector->productTypeDefinitionsV20200901();

if (!is_dir(AMAZON_SCHEMAS)) {
    mkdir(AMAZON_SCHEMAS, 0755, true);
}

$indexFile = AMAZON_SCHEMAS . '/_index.json';
$index     = file_exists($indexFile)
    ? (json_decode(file_get_contents($indexFile), true) ?? [])
    : [];

// ---------------------------------------------------------------------------
// Step 1: Collect distinct productTypes from all accounts' listings files
// ---------------------------------------------------------------------------

$productTypes = [];
$accountDirs  = glob(AMAZON_DATA . '/*/input/listings', GLOB_ONLYDIR) ?: [];
$accountNames = [];

foreach ($accountDirs as $dir) {
    $account        = basename(dirname(dirname($dir)));
    $accountNames[] = strtoupper($account);

    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $data = json_decode(file_get_contents($file), true) ?? [];
        foreach ($data['summaries'] ?? [] as $summary) {
            $pt = $summary['productType'] ?? '';
            if ($pt !== '') {
                $productTypes[$pt] = true;
            }
        }
    }
}

$productTypes = array_keys($productTypes);
sort($productTypes);

$toFetch = $force
    ? $productTypes
    : array_values(array_filter($productTypes, fn($pt) => !file_exists(AMAZON_SCHEMAS . '/' . $pt . '.json')));

echo 'Accounts:      ' . implode(', ', $accountNames) . PHP_EOL;
echo 'Product types: ' . count($productTypes) . PHP_EOL;
echo 'To fetch:      ' . count($toFetch) . PHP_EOL;

if (!$toFetch) {
    echo PHP_EOL . 'All schemas already cached. Use --force to re-fetch.' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// Step 2: Fetch and cache each schema
// ---------------------------------------------------------------------------

$saved  = 0;
$errors = 0;

foreach ($toFetch as $productType) {
    echo '  ' . $productType . PHP_EOL;

    try {
        $definition = AmazonRateLimits::retryWithBackoff(
            fn() => $ptApi->getDefinitionsProductType(
                productType:          $productType,
                marketplaceIds:       [$amazon->marketplaceId],
                sellerId:             $amazon->sellerId,
                requirements:         'LISTING',
                requirementsEnforced: 'ENFORCED',
            )->json(),
            AmazonOperationIds::GET_DEFINITIONS_PRODUCT_TYPE,
        );
    } catch (\Saloon\Exceptions\Request\ClientException $e) {
        fwrite(STDERR, '  → ' . $e->getStatus() . ' ' . $productType . ' — skipping' . PHP_EOL);
        $errors++;
        AmazonRateLimits::throttle(AmazonOperationIds::GET_DEFINITIONS_PRODUCT_TYPE);
        continue;
    }

    $schemaUrl = $definition['schema']['link']['resource'] ?? null;
    if ($schemaUrl === null) {
        fwrite(STDERR, "  → no schema URL for {$productType} — skipping" . PHP_EOL);
        $errors++;
        continue;
    }

    $schemaJson = file_get_contents($schemaUrl);
    if ($schemaJson === false) {
        fwrite(STDERR, "  → failed to download schema for {$productType} — skipping" . PHP_EOL);
        $errors++;
        continue;
    }

    // Strip Unicode line/paragraph separators (U+2028, U+2029) that appear in
    // Amazon's schema description strings and trigger editor warnings.
    $schemaJson = str_replace(["\u{2028}", "\u{2029}"], '', $schemaJson);

    file_put_contents(AMAZON_SCHEMAS . '/' . $productType . '.json', $schemaJson);

    $index[$productType] = [
        'fetched_at' => date('c'),
        'version'    => $definition['productTypeVersion']['version'] ?? null,
        'locale'     => $definition['locale'] ?? null,
        'source_url' => strtok($schemaUrl, '?'), // strip presigned query string
    ];

    ksort($index);
    file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $saved++;

    AmazonRateLimits::throttle(AmazonOperationIds::GET_DEFINITIONS_PRODUCT_TYPE);
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$skipped = count($productTypes) - count($toFetch);

echo PHP_EOL;
echo 'Saved:   ' . $saved . PHP_EOL;
echo 'Skipped: ' . $skipped . ' (already cached; use --force to overwrite)' . PHP_EOL;
echo 'Errors:  ' . $errors . PHP_EOL;
echo 'Schemas → ' . AMAZON_SCHEMAS . PHP_EOL;
