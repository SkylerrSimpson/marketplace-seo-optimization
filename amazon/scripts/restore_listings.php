<?php

declare(strict_types=1);

/**
 * Phase 10 — Restore a listing from a pre-change backup.
 *
 * patch_listings.php writes data/{account}/backups/{sku}/{timestamp}.json
 * (the full live getListingsItem response) BEFORE every --apply patch. This
 * script replays a chosen backup's attributes back onto the SKU, reverting the
 * changes a patch made.
 *
 * Safety model (same guarded-write pattern as patch_listings.php):
 *   --apply is required to write. Dry-run (default) shows exactly what would be
 *   restored, per attribute, with no API calls.
 *   Identifying attributes are held back unless --include-identifying — a
 *   restore that rewrites variation/identity data is as dangerous as a patch.
 *   On --apply the CURRENT live listing is fetched and written as a fresh
 *   backup FIRST, so a restore is itself reversible.
 *
 * Never restored (SKU-specific commercial data — see NON_RESTORABLE): the
 * offer, price, fulfillment, shipping-group, tax, and condition attributes.
 * Restoring stale commercial data could mis-price or mis-stock a live SKU.
 *
 * Usage:
 *   php amazon/scripts/restore_listings.php --account=IGE --sku=SKU [OPTIONS]
 *
 * Flags:
 *   --account=IGE|DOWS          Seller account. Default: IGE.
 *   --sku=SKU                   Restore a single SKU. Omit to restore the
 *                               latest backup of every SKU under backups/.
 *   --timestamp=YYYY-MM-DD-HH-ii-ss
 *                               Restore this specific backup (requires --sku).
 *                               Default: the SKU's most recent backup.
 *   --apply                     Submit to Amazon. Required to write.
 *   --include-identifying[=a,b] Allow identifying attributes through the guard.
 *                               Bare allows ALL; =list allows only those named.
 *   --help                      Show this help message.
 *
 * Inputs:
 *   amazon/data/{account}/backups/{sku}/{timestamp}.json
 *
 * Output:
 *   amazon/data/{account}/output/restore_results_{timestamp}.csv
 *   amazon/data/{account}/backups/{sku}/{timestamp}.json  (pre-restore, on --apply)
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/AmazonClient.php';
require __DIR__ . '/../../lib/AmazonOperationIds.php';
require __DIR__ . '/../../lib/AmazonRateLimits.php';
require __DIR__ . '/../../lib/AmazonPatch.php';
require __DIR__ . '/../../lib/IdentifyingAttributes.php';

// Data to pull for the pre-restore backup fetch (mirror Phase 2).
const RESTORE_INCLUDED_DATA = [
    'attributes', 'issues', 'summaries', 'offers',
    'fulfillmentAvailability', 'procurement', 'relationships', 'productTypes',
];

// SKU-specific commercial attributes never replayed by a restore. Editable.
const NON_RESTORABLE = [
    'purchasable_offer',
    'skip_offer',
    'list_price',
    'fulfillment_availability',
    'merchant_shipping_group',
    'product_tax_code',
    'condition_type',
    'condition_note',
    'supplemental_condition_information',
    'ships_globally',
    'website_shipping_weight',
    'deprecated_offering_start_date',
];

// ---------------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/restore_listings.php --account=IGE --sku=SKU [OPTIONS]

Flags:
  --account=IGE|DOWS          Seller account. Default: IGE.
  --sku=SKU                   Restore a single SKU. Omit to restore the latest
                              backup of every SKU under backups/.
  --timestamp=TS              Restore a specific backup (requires --sku).
                              Default: the SKU's most recent backup.
  --apply                     Submit to Amazon. Without this flag, dry-run mode
                              shows what would be restored but makes no API calls.
  --include-identifying[=a,b] Allow identifying attributes through the guard.
                              Bare allows ALL; =list allows only those named.
  --help                      Show this help message.

Safety:
  --apply is required to write. Review the dry-run output first.
  Identifying data is held back unless --include-identifying is passed.
  Commercial data (offer, price, fulfillment, shipping, tax, condition) is
  never restored. On --apply a fresh pre-restore backup is written first.
HELP;
    echo PHP_EOL;
    exit(0);
}

$account            = 'IGE';
$singleSku          = null;
$timestamp          = null;
$apply              = false;
$includeIdentifying = false; // false | 'all' | array of allowed attr names

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    } elseif (preg_match('/^--sku=(.+)$/', $arg, $m)) {
        $singleSku = $m[1];
    } elseif (preg_match('/^--timestamp=(.+)$/', $arg, $m)) {
        $timestamp = $m[1];
    } elseif ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--include-identifying') {
        $includeIdentifying = 'all';
    } elseif (preg_match('/^--include-identifying=(.+)$/', $arg, $m)) {
        $includeIdentifying = array_values(array_filter(array_map(
            fn($s) => strtolower(trim($s)),
            explode(',', $m[1]),
        )));
    }
}

if ($timestamp !== null && $singleSku === null) {
    fwrite(STDERR, "--timestamp requires --sku.\n");
    exit(1);
}

$paths      = amazon_paths($account);
$schemasDir = $paths['schemas'];
$backupsDir = $paths['data'] . '/backups';

echo 'Account  : ' . $account . PHP_EOL;
if (!$apply) {
    echo '[DRY RUN — no API calls will be made. Pass --apply to write to Amazon.]' . PHP_EOL;
}
echo 'Identifying: ' . (
    $includeIdentifying === 'all'
        ? 'INCLUDED (all)'
        : (is_array($includeIdentifying)
            ? 'INCLUDED (' . implode(', ', $includeIdentifying) . ')'
            : 'held back (default)')
) . PHP_EOL;
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Resolve which backups to restore
// ---------------------------------------------------------------------------

/** Latest backup file path for a SKU, or null if none. */
function latestBackup(string $backupsDir, string $sku): ?string
{
    $files = glob($backupsDir . '/' . $sku . '/*.json') ?: [];
    if (!$files) {
        return null;
    }
    sort($files); // timestamp filenames sort chronologically
    return end($files);
}

$jobs = []; // [sku => backupFilePath]

if ($singleSku !== null) {
    if ($timestamp !== null) {
        $file = $backupsDir . '/' . $singleSku . '/' . $timestamp . '.json';
        if (!is_file($file)) {
            fwrite(STDERR, "No backup at {$file}\n");
            exit(1);
        }
        $jobs[$singleSku] = $file;
    } else {
        $file = latestBackup($backupsDir, $singleSku);
        if ($file === null) {
            fwrite(STDERR, "No backups found for {$singleSku} in {$backupsDir}\n");
            exit(1);
        }
        $jobs[$singleSku] = $file;
    }
} else {
    $skuDirs = glob($backupsDir . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($skuDirs as $dir) {
        $sku  = basename($dir);
        $file = latestBackup($backupsDir, $sku);
        if ($file !== null) {
            $jobs[$sku] = $file;
        }
    }
    if (!$jobs) {
        echo 'No backups found in ' . $backupsDir . PHP_EOL;
        exit(0);
    }
}

echo 'Backups to restore : ' . count($jobs) . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
// Amazon connector (apply only)
// ---------------------------------------------------------------------------

$amazon      = null;
$listingsApi = null;

if ($apply) {
    $amazon      = new AmazonClient($account);
    $listingsApi = $amazon->connector->listingsItemsV20210801();
}

$marketplaceId = $apply ? $amazon->marketplaceId : ($_ENV['AMAZON_SPAPI_MARKETPLACE_ID'] ?? 'ATVPDKIKX0DER');

/** Fetch the current live listing and write it as a fresh pre-restore backup. */
function writePreRestoreBackup(string $sku, $listingsApi, AmazonClient $amazon, string $backupsDir): ?string
{
    $item = AmazonRateLimits::retryWithBackoff(
        fn() => $listingsApi->getListingsItem(
            sellerId:       $amazon->sellerId,
            sku:            $sku,
            marketplaceIds: [$amazon->marketplaceId],
            includedData:   RESTORE_INCLUDED_DATA,
        )->json(),
        AmazonOperationIds::GET_LISTINGS_ITEM,
    );
    AmazonRateLimits::throttle(AmazonOperationIds::GET_LISTINGS_ITEM);

    $dir = $backupsDir . '/' . $sku;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . date('Y-m-d-H-i-s') . '.json';
    file_put_contents($file, json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $file;
}

// ---------------------------------------------------------------------------
// Restore
// ---------------------------------------------------------------------------

$stats = [
    'accepted'      => 0,
    'with_warnings' => 0,
    'invalid'       => 0,
    'failed'        => 0,
    'skipped'       => 0,
];

$results = [];

foreach ($jobs as $sku => $backupFile) {
    $backup      = json_decode((string) file_get_contents($backupFile), true) ?? [];
    $attributes  = $backup['attributes'] ?? [];
    $productType = $backup['productTypes'][0]['productType'] ?? '';
    $asin        = $backup['summaries'][0]['asin'] ?? '';

    echo '[' . $sku . ']' . ($productType ? ' ' . $productType : '') . PHP_EOL;
    echo '  from backup: ' . basename($backupFile) . PHP_EOL;

    if (!$attributes) {
        echo '  [SKIP] backup has no attributes' . PHP_EOL;
        $stats['skipped']++;
        continue;
    }

    // Build restore ops, applying the commercial + identifying guards.
    $patches            = [];
    $skippedCommercial  = [];
    $skippedIdentifying = [];

    foreach ($attributes as $attr => $value) {
        if (in_array($attr, NON_RESTORABLE, true)) {
            $skippedCommercial[] = $attr;
            continue;
        }
        if (IdentifyingAttributes::isIdentifying($attr, $productType, $schemasDir)) {
            $allow = $includeIdentifying === 'all'
                || (is_array($includeIdentifying) && in_array(strtolower($attr), $includeIdentifying, true));
            if (!$allow) {
                $skippedIdentifying[] = $attr;
                continue;
            }
        }
        // Backup values are already SP-API slot-shaped; formatPatchValue passes
        // them through unchanged.
        $patches[] = AmazonPatch::replaceOp($attr, $value, $marketplaceId);
    }

    $extra = [
        'skipped_identifying' => implode('; ', $skippedIdentifying),
        'skipped_commercial'  => implode('; ', $skippedCommercial),
    ];

    if (!$patches) {
        echo '  [SKIP] nothing to restore after guards';
        if ($skippedIdentifying) {
            echo ' — held back identifying: ' . implode(', ', $skippedIdentifying);
        }
        echo PHP_EOL;
        $stats['skipped']++;
        $results[] = AmazonPatch::resultRow($sku, $asin, $productType, 0, $apply ? 'skipped' : 'dry_run') + $extra;
        continue;
    }

    $attrNames = array_map([AmazonPatch::class, 'opAttr'], $patches);
    echo '  restoring ' . count($patches) . ' attribute(s): ' . implode(', ', $attrNames) . PHP_EOL;
    if ($skippedIdentifying) {
        echo '  held back (identifying): ' . implode(', ', $skippedIdentifying) . PHP_EOL;
    }
    if ($skippedCommercial) {
        echo '  not restored (commercial): ' . implode(', ', $skippedCommercial) . PHP_EOL;
    }

    if (!$apply) {
        $results[] = AmazonPatch::resultRow($sku, $asin, $productType, count($patches), 'dry_run') + $extra;
        continue;
    }

    // --- Live restore: fresh pre-restore backup first, then replay ---
    try {
        $preBackup = writePreRestoreBackup($sku, $listingsApi, $amazon, $backupsDir);
        echo '  pre-restore backup → ' . $preBackup . PHP_EOL;

        $req    = AmazonPatch::buildPatchRequest($productType, $patches);
        $result = AmazonPatch::submitPatch($listingsApi, $amazon->sellerId, $sku, $req, $marketplaceId);

        $status        = $result['status'];
        $submissionId  = $result['submission_id'];
        $issuesSummary = $result['issues_summary'];

        echo '  → ' . $status . ($submissionId ? ' (' . $submissionId . ')' : '') . PHP_EOL;
        if ($issuesSummary !== '') {
            echo '  issues: ' . $issuesSummary . PHP_EOL;
        }

        match (strtoupper($status)) {
            'ACCEPTED'      => $stats['accepted']++,
            'WITH_WARNINGS' => $stats['with_warnings']++,
            'INVALID'       => $stats['invalid']++,
            default         => $stats['failed']++,
        };

        $results[] = AmazonPatch::resultRow(
            $sku, $asin, $productType, count($patches),
            $status, $submissionId, $result['issues_count'], $issuesSummary,
        ) + $extra;
    } catch (\Throwable $e) {
        echo '  [ERROR] ' . $e->getMessage() . PHP_EOL;
        $stats['failed']++;
        $results[] = AmazonPatch::resultRow(
            $sku, $asin, $productType, count($patches),
            'ERROR', '', 0, $e->getMessage(),
        ) + $extra;
    }
}

// ---------------------------------------------------------------------------
// Write results CSV
// ---------------------------------------------------------------------------

if ($results) {
    $ts      = date('Y-m-d-H-i-s');
    $outFile = $paths['output'] . '/restore_results_' . $ts . '.csv';
    $fh      = fopen($outFile, 'w');

    $cols = array_merge(
        AmazonPatch::RESULT_COLUMNS,
        ['skipped_identifying', 'skipped_commercial'],
    );
    fputcsv($fh, $cols);
    foreach ($results as $row) {
        fputcsv($fh, array_map(fn($c) => $row[$c] ?? '', $cols));
    }
    fclose($fh);
    echo PHP_EOL . 'Results → ' . $outFile . PHP_EOL;
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo PHP_EOL;
echo '─────────────────────────────────────────' . PHP_EOL;

if (!$apply) {
    $queued = count(array_filter($results, fn($r) => $r['status'] === 'dry_run'));
    echo 'DRY RUN complete.' . PHP_EOL;
    echo 'SKUs queued to restore : ' . $queued . PHP_EOL;
    echo 'SKUs skipped           : ' . $stats['skipped'] . PHP_EOL;
    echo PHP_EOL;
    echo 'Pass --apply to submit to Amazon.' . PHP_EOL;
} else {
    echo 'Accepted             : ' . $stats['accepted'] . PHP_EOL;
    echo 'With warnings        : ' . $stats['with_warnings'] . PHP_EOL;
    echo 'Invalid              : ' . $stats['invalid'] . PHP_EOL;
    echo 'Failed/Error         : ' . $stats['failed'] . PHP_EOL;
    echo 'Skipped              : ' . $stats['skipped'] . PHP_EOL;
}
