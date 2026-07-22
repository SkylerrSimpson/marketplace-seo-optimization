<?php

declare(strict_types=1);

/**
 * Phase 1 — Merchant Listings report export (READ ONLY).
 *
 * Requests two reports from the Reports API in parallel (both created upfront
 * so Amazon processes them simultaneously), polls each to completion, and
 * writes output files to amazon/data/input/reports/:
 *
 *   listings_{timestamp}.tsv         — raw all-listings TSV
 *   listings_{timestamp}.json        — lossless sidecar keyed by seller-sku;
 *                                      SKU→ASIN map consumed by Phase 2+
 *   suppressed_{timestamp}.tsv       — raw suppressed-listings TSV
 *   suppressed_{timestamp}.json      — sidecar keyed by SKU with reason/issue
 *
 * Usage:
 *   php amazon/scripts/export_listings_report.php [--account=IGE|DOWS] [--force]
 *
 * Flags:
 *   --account=  Seller account to export. Default: IGE.
 *   --force     Re-run even if today's reports already exist on disk.
 *   --help      Show this help message.
 *
 * .env keys (Amazon block):
 *   AMAZON_SPAPI_CLIENT_ID, AMAZON_SPAPI_CLIENT_SECRET,
 *   AMAZON_SPAPI_REFRESH_TOKEN, AMAZON_SPAPI_SELLER_ID,
 *   AMAZON_SPAPI_SELLER_ID_DOWS, AMAZON_SPAPI_REFRESH_TOKEN_DOWS,
 *   AMAZON_SPAPI_REGION, AMAZON_SPAPI_MARKETPLACE_ID,
 *   AMAZON_SPAPI_SANDBOX
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/AmazonClient.php';

use SellingPartnerApi\Seller\ReportsV20210630\Dto\CreateReportSpecification;
use SellingPartnerApi\Seller\ReportsV20210630\Responses\Report;

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/export_listings_report.php [--account=IGE|DOWS] [--force]

Flags:
  --account=IGE|DOWS   Seller account to export. Default: IGE.
  --force              Re-run even if today's reports already exist on disk.
  --help               Show this help message.
HELP;
    echo PHP_EOL;
    exit(0);
}

$force   = in_array('--force', $argv ?? [], true);
$account = 'IGE';
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    }
}

$amazon     = new AmazonClient($account);
$paths      = amazon_paths($account);
$reportsApi = $amazon->connector->reportsV20210630();

echo 'Account: ' . $amazon->account . PHP_EOL;

const LISTINGS_REPORT_TYPE   = 'GET_MERCHANT_LISTINGS_ALL_DATA';
const SUPPRESSED_REPORT_TYPE = 'GET_MERCHANTS_LISTINGS_FYP_REPORT';

// ---------------------------------------------------------------------------
// Idempotency — skip if both of today's reports already exist
// ---------------------------------------------------------------------------

$today              = date('Y-m-d');
$existingListings   = glob($paths['reports'] . "/listings_{$today}*.tsv")   ?: [];
$existingSuppressed = glob($paths['reports'] . "/suppressed_{$today}*.tsv") ?: [];

if ($existingListings && $existingSuppressed && !$force) {
    echo 'Reports for ' . $today . ' already exist:' . PHP_EOL;
    echo '  ' . basename($existingListings[0]) . PHP_EOL;
    echo '  ' . basename($existingSuppressed[0]) . PHP_EOL;
    echo 'Re-run with --force to overwrite.' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// Step 1: Create both reports upfront so Amazon processes them simultaneously
// ---------------------------------------------------------------------------

echo 'Requesting ' . LISTINGS_REPORT_TYPE . ' ... ';
$listingsSpec = new CreateReportSpecification(
    reportType:     LISTINGS_REPORT_TYPE,
    marketplaceIds: [$amazon->marketplaceId],
);
$listingsReportId = $reportsApi->createReport($listingsSpec)->dto()->reportId;
echo $listingsReportId . PHP_EOL;

echo 'Requesting ' . SUPPRESSED_REPORT_TYPE . ' ... ';
$suppressedSpec = new CreateReportSpecification(
    reportType:     SUPPRESSED_REPORT_TYPE,
    marketplaceIds: [$amazon->marketplaceId],
);
$suppressedReportId = $reportsApi->createReport($suppressedSpec)->dto()->reportId;
echo $suppressedReportId . PHP_EOL;

// ---------------------------------------------------------------------------
// Step 2: Poll both to completion
// ---------------------------------------------------------------------------

echo 'Waiting for listings report...' . PHP_EOL;
$listingsReport = pollUntilDone($reportsApi, $listingsReportId, LISTINGS_REPORT_TYPE);

echo 'Waiting for suppressed listings report...' . PHP_EOL;
$suppressedReport = pollUntilDone($reportsApi, $suppressedReportId, SUPPRESSED_REPORT_TYPE);

// ---------------------------------------------------------------------------
// Step 3: Download + write listings report
// ---------------------------------------------------------------------------

$listingsRaw = downloadDocument($reportsApi, $listingsReport, LISTINGS_REPORT_TYPE);

$timestamp   = date('Y-m-d_His');
$listingsTsv = $paths['reports'] . "/listings_{$timestamp}.tsv";
file_put_contents($listingsTsv, $listingsRaw);
echo 'TSV  → ' . $listingsTsv . PHP_EOL;

$listingsBySku = parseTsvBySku($listingsRaw, 'seller-sku');
$listingsJson  = $paths['reports'] . "/listings_{$timestamp}.json";
file_put_contents($listingsJson, json_encode($listingsBySku, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'JSON → ' . $listingsJson . PHP_EOL;
echo count($listingsBySku) . ' SKUs indexed.' . PHP_EOL;

// ---------------------------------------------------------------------------
// Step 4: Download + write suppressed listings report
// ---------------------------------------------------------------------------

$suppressedRaw = downloadDocument($reportsApi, $suppressedReport, SUPPRESSED_REPORT_TYPE);

$suppressedTsv = $paths['reports'] . "/suppressed_{$timestamp}.tsv";
file_put_contents($suppressedTsv, $suppressedRaw);
echo 'TSV  → ' . $suppressedTsv . PHP_EOL;

$suppressedBySku = parseTsvBySku($suppressedRaw, 'SKU');
$suppressedJson  = $paths['reports'] . "/suppressed_{$timestamp}.json";
file_put_contents($suppressedJson, json_encode($suppressedBySku, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'JSON → ' . $suppressedJson . PHP_EOL;
echo count($suppressedBySku) . ' suppressed SKUs indexed.' . PHP_EOL;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Poll getReport() until processingStatus is DONE.
 * Backs off from 30s → up to 120s between polls.
 * Exits on FATAL, CANCELLED, or after maxAttempts.
 */
function pollUntilDone(
    \SellingPartnerApi\Seller\ReportsV20210630\Api $api,
    string $reportId,
    string $label,
    int $maxAttempts = 20,
): Report {
    $delay = 30;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $report = $api->getReport($reportId)->dto();
        $status = $report->processingStatus;

        if ($status === 'DONE') {
            echo '  attempt ' . $attempt . ': ' . $status . PHP_EOL;
            return $report;
        }

        if ($status === 'FATAL' || $status === 'CANCELLED') {
            fwrite(STDERR, "  attempt {$attempt}: {$status} — giving up.\n");
            exit(1);
        }

        if ($attempt < $maxAttempts) {
            echo '  attempt ' . $attempt . ': ' . $status . ' — sleeping ' . $delay . 's' . PHP_EOL;
            sleep($delay);
            $delay = min($delay * 2, 120);
        }
    }

    fwrite(STDERR, "Report did not complete after {$maxAttempts} attempts ({$reportId}).\n");
    exit(1);
}

/**
 * Download and optionally decompress a report document.
 * Returns the raw document body as a string.
 */
function downloadDocument(
    \SellingPartnerApi\Seller\ReportsV20210630\Api $api,
    Report $report,
    string $reportType,
): string {
    if ($report->reportDocumentId === null) {
        fwrite(STDERR, "Report DONE but no reportDocumentId returned.\n");
        exit(1);
    }

    echo 'Downloading document ' . $report->reportDocumentId . ' ...' . PHP_EOL;

    $doc = $api->getReportDocument($report->reportDocumentId, $reportType)->dto();
    $raw = file_get_contents($doc->url);

    if ($raw === false) {
        fwrite(STDERR, "Failed to download report from presigned URL.\n");
        exit(1);
    }

    if ($doc->compressionAlgorithm === 'GZIP') {
        $raw = gzdecode($raw);
        if ($raw === false) {
            fwrite(STDERR, "Failed to decompress GZIP report.\n");
            exit(1);
        }
    }

    return $raw;
}

/**
 * Parse a tab-separated Amazon report into an array keyed by $skuColumn.
 * Strips the UTF-8 BOM Amazon prepends. Every column is preserved (lossless).
 *
 * @return array<string, array<string, string>>
 */
function parseTsvBySku(string $tsv, string $skuColumn): array
{
    $tsv   = ltrim($tsv, "\xEF\xBB\xBF"); // strip UTF-8 BOM Amazon prepends
    $lines = explode("\n", trim($tsv));

    if (count($lines) < 2) {
        return [];
    }

    $headers = str_getcsv(array_shift($lines), "\t");
    $map     = [];

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        $cols = str_getcsv($line, "\t");
        $row  = array_combine($headers, array_pad($cols, count($headers), ''));

        $sku = $row[$skuColumn] ?? '';
        if ($sku === '') {
            continue;
        }

        $map[$sku] = $row;
    }

    return $map;
}
