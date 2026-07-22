<?php

declare(strict_types=1);

/**
 * Phase 6 — Gap-fill analysis (READ ONLY, no API calls).
 *
 * Joins Phase 5 listing/schema data against the Usurper catalog dump to
 * classify each missing attribute as:
 *   fillable        — Usurper has the source data; ready to push
 *   needs_authoring — data is absent from both systems; requires human copy
 *
 * Re-derives the full missing-attribute list per SKU (not just the top-5
 * summary from Phase 5) so every gap is captured.
 *
 * Join key: listing filename stem (SKU) == Usurper 'sku' column.
 *
 * Priority score mirrors audit_listings.php:
 *   (missing_required × 10) + (amazon_issue_count × 5) + (missing_recommended × 1)
 *
 * Usage:
 *   php amazon/scripts/analyze_gap_fill.php [--account=IGE|DOWS]
 *
 * Flags:
 *   --account=IGE|DOWS   Seller account to analyze. Default: IGE.
 *   --help               Show this help message.
 *
 * Inputs (from disk):
 *   amazon/data/{account}/input/listings/{sku}.json
 *   amazon/data/{account}/input/usurper/usurper_catalog_*.csv  (latest by mtime)
 *   amazon/data/schemas/{PRODUCT_TYPE}.json
 *
 * Output:
 *   amazon/data/{account}/output/listings_gap_fill.csv
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/UsurperExport.php';

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/analyze_gap_fill.php [--account=IGE|DOWS]

Flags:
  --account=IGE|DOWS   Seller account to analyze. Default: IGE.
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
// Step 1: Load Usurper catalog CSV
// ---------------------------------------------------------------------------

$usurperDir = $paths['input'] . '/usurper';

if (!is_dir($usurperDir)) {
    mkdir($usurperDir, 0755, true);
}

$usurperFile = usurper_latest_export($usurperDir);

if ($usurperFile === null) {
    fwrite(STDERR, "No CSV found in {$usurperDir}.\n");
    fwrite(STDERR, "Drop the Usurper InventoryExport CSV there and re-run.\n");
    exit(1);
}

echo 'Usurper file: ' . basename($usurperFile) . PHP_EOL;

// Load via the shared reader (strict RFC-4180: escape disabled). Hand-rolling
// fgetcsv here with the default backslash escape silently desynced quote state
// on rows whose values contain backslashes (e.g. mangled inch marks `3\"`),
// dropped them on the width check, and then mislabeled every SKU in them as
// 'sku_not_in_usurper'. usurper_load_export reports such rows instead of
// hiding them.
$loaded    = usurper_load_export($usurperFile);
$usurper   = $loaded['records']; // [sku => [col => val]]
$malformed = $loaded['malformed'];

echo 'Usurper records loaded: ' . count($usurper) . PHP_EOL;

if ($malformed) {
    fwrite(STDERR, sprintf(
        "WARNING: %d Usurper row(s) could not be parsed (field count != header) and were skipped.\n",
        count($malformed),
    ));
    foreach ($malformed as $badSku => $count) {
        fwrite(STDERR, "  - {$badSku} ({$count} fields)\n");
    }
}

// ---------------------------------------------------------------------------
// Step 2: Load attribute map
// ---------------------------------------------------------------------------

$attrMap = require __DIR__ . '/../../lib/UsurperAttributeMap.php';

// ---------------------------------------------------------------------------
// Step 3: Classify helper
// ---------------------------------------------------------------------------

/**
 * Given an Amazon attribute name, look for its value in a Usurper row.
 * Returns ['fillable', 'usurper_column', 'usurper_value'].
 *
 * When the Usurper row is null (SKU not in Usurper dump at all), all gaps
 * for that SKU are 'no' with usurper_column = 'sku_not_in_usurper'.
 */
$classify = function (string $attr, ?array $usurperRow) use ($attrMap): array {
    if ($usurperRow === null) {
        return [
            'fillable'       => 'no',
            'usurper_column' => 'sku_not_in_usurper',
            'usurper_value'  => '',
        ];
    }

    // Fall back to attr.amazon_{attr} so every Amazon attribute has a
    // round-trip home in Usurper after the first project_to_usurper run.
    $candidates = $attrMap[$attr] ?? ['attr.amazon_' . $attr];

    foreach ($candidates as $col) {
        $val = trim($usurperRow[$col] ?? '');
        if ($val !== '') {
            $truncated = strlen($val) > 120 ? substr($val, 0, 117) . '...' : $val;
            return [
                'fillable'       => 'yes',
                'usurper_column' => $col,
                'usurper_value'  => $truncated,
            ];
        }
    }

    return ['fillable' => 'no', 'usurper_column' => '', 'usurper_value' => ''];
};

// ---------------------------------------------------------------------------
// Step 4: Walk listing files and emit one row per missing attribute
// ---------------------------------------------------------------------------

$listingFiles = glob($paths['listings'] . '/*.json') ?: [];

if (!$listingFiles) {
    fwrite(STDERR, "No listing files in {$paths['listings']}.\n");
    fwrite(STDERR, "Run export_listings_items.php --account={$account} first.\n");
    exit(1);
}

echo 'Listings: ' . count($listingFiles) . PHP_EOL;

$rows         = [];
$skusSkipped  = 0;

foreach ($listingFiles as $listingFile) {
    $listing     = json_decode(file_get_contents($listingFile), true) ?? [];
    $sku         = $listing['sku'] ?? basename($listingFile, '.json');
    $summaries   = $listing['summaries'] ?? [];
    $summary     = reset($summaries) ?: [];
    $asin        = $summary['asin'] ?? '';
    $productType = $summary['productType'] ?? '';

    if ($productType === '') {
        $skusSkipped++;
        continue;
    }

    $schemaFile = AMAZON_SCHEMAS . '/' . $productType . '.json';
    if (!file_exists($schemaFile)) {
        $skusSkipped++;
        continue;
    }

    $schema       = json_decode(file_get_contents($schemaFile), true) ?? [];
    $listingAttrs = array_keys($listing['attributes'] ?? []);
    $required     = $schema['required'] ?? [];
    $allProps     = array_keys($schema['properties'] ?? []);
    $recommended  = array_values(array_diff($allProps, $required));

    $missingRequired    = array_values(array_diff($required, $listingAttrs));
    $missingRecommended = array_values(array_diff($recommended, $listingAttrs));

    if (!$missingRequired && !$missingRecommended) {
        continue;
    }

    $issueCount  = count($listing['issues'] ?? []);
    $priority    = (count($missingRequired) * 10)
                 + ($issueCount * 5)
                 + (count($missingRecommended) * 1);

    $usurperRow = $usurper[$sku] ?? null;

    foreach ($missingRequired as $attr) {
        $c      = $classify($attr, $usurperRow);
        $rows[] = [
            'sku'            => $sku,
            'asin'           => $asin,
            'product_type'   => $productType,
            'priority'       => $priority,
            'is_required'    => 'yes',
            'attribute'      => $attr,
            'fillable'       => $c['fillable'],
            'usurper_column' => $c['usurper_column'],
            'usurper_value'  => $c['usurper_value'],
        ];
    }

    foreach ($missingRecommended as $attr) {
        $c      = $classify($attr, $usurperRow);
        $rows[] = [
            'sku'            => $sku,
            'asin'           => $asin,
            'product_type'   => $productType,
            'priority'       => $priority,
            'is_required'    => 'no',
            'attribute'      => $attr,
            'fillable'       => $c['fillable'],
            'usurper_column' => $c['usurper_column'],
            'usurper_value'  => $c['usurper_value'],
        ];
    }
}

// ---------------------------------------------------------------------------
// Step 5: Sort — priority desc, required before recommended, then attr name
// ---------------------------------------------------------------------------

usort($rows, function ($a, $b) {
    if ($b['priority'] !== $a['priority']) {
        return $b['priority'] <=> $a['priority'];
    }
    if ($a['is_required'] !== $b['is_required']) {
        return $a['is_required'] === 'yes' ? -1 : 1;
    }
    return strcmp($a['attribute'], $b['attribute']);
});

// ---------------------------------------------------------------------------
// Step 6: Write CSV
// ---------------------------------------------------------------------------

if (!$rows) {
    echo PHP_EOL . 'No attribute gaps found.' . PHP_EOL;
    exit(0);
}

$outFile = $paths['output'] . '/listings_gap_fill.csv';
$fhOut   = fopen($outFile, 'w');

// Escape disabled ('') so output is strict RFC-4180 and round-trips through any
// standards reader — matching how UsurperExport.php reads.
fputcsv($fhOut, array_keys($rows[0]), ',', '"', '');
foreach ($rows as $row) {
    fputcsv($fhOut, $row, ',', '"', '');
}

fclose($fhOut);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$totalGaps      = count($rows);
$fillable       = count(array_filter($rows, fn($r) => $r['fillable'] === 'yes'));
$needsAuthoring = $totalGaps - $fillable;
$requiredGaps   = count(array_filter($rows, fn($r) => $r['is_required'] === 'yes'));
$skusAffected   = count(array_unique(array_column($rows, 'sku')));
$skusNotInUsurper = count(array_unique(array_column(
    array_filter($rows, fn($r) => $r['usurper_column'] === 'sku_not_in_usurper'),
    'sku',
)));

echo PHP_EOL;
echo 'SKUs with gaps:         ' . $skusAffected . PHP_EOL;
if ($skusSkipped > 0) {
    echo 'SKUs skipped (no schema):' . $skusSkipped . PHP_EOL;
}
if ($skusNotInUsurper > 0) {
    echo 'SKUs not in Usurper:    ' . $skusNotInUsurper . PHP_EOL;
}
echo PHP_EOL;
echo 'Total gaps:             ' . $totalGaps . PHP_EOL;
echo '  Required missing:     ' . $requiredGaps . PHP_EOL;
echo '  Recommended missing:  ' . ($totalGaps - $requiredGaps) . PHP_EOL;
echo PHP_EOL;
echo 'Fillable (Usurper):     ' . $fillable . PHP_EOL;
echo 'Needs authoring:        ' . $needsAuthoring . PHP_EOL;
echo PHP_EOL;
echo 'CSV → ' . $outFile . PHP_EOL;
