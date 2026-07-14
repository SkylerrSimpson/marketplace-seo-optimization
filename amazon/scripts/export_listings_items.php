<?php

declare(strict_types=1);

/**
 * Phase 2 — Per-SKU listings item snapshot (READ ONLY).
 *
 * Fetches the full submitted attribute set, issues, summaries, offers,
 * fulfillment availability, procurement, relationships, and product types
 * for every listing using searchListingsItems.
 *
 * Amazon caps searchListingsItems at 1000 results regardless of pagination.
 * For accounts with > 1000 SKUs the script switches to date-range chunking:
 * it reads open-dates from the Phase 1 report, splits the catalog into
 * windows of CHUNK_SIZE SKUs, and calls searchListingsItems with
 * createdAfter/createdBefore on each window. Any SKUs still missing after
 * all windows are fetched individually via getListingsItem as a last resort.
 *
 * This approach is the one endorsed by Amazon's SP-API developer services
 * team (github.com/amzn/selling-partner-api-models/issues/4765).
 *
 * Phase 1 (export_listings_report.php) must be run first for accounts with
 * > 1000 SKUs so that open-date data is available for date-range chunking.
 *
 * Writes one file per SKU to amazon/data/{account}/input/listings/{sku}.json.
 * Each file is the raw JSON item from the API — lossless.
 *
 * Usage:
 *   php amazon/scripts/export_listings_items.php [--account=IGE|DOWS] [--force] [--limit=N]
 *
 * Flags:
 *   --account=  Seller account to export. Default: IGE.
 *   --force     Re-fetch and overwrite existing per-SKU files.
 *   --limit=N   Stop after writing N SKU files (canary mode).
 *   --help      Show this help message.
 *
 * .env keys: same Amazon block as other scripts.
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/AmazonClient.php';
require __DIR__ . '/../../lib/AmazonOperationIds.php';
require __DIR__ . '/../../lib/AmazonRateLimits.php';

use \SellingPartnerApi\Seller\ListingsItemsV20210801\Api as ListingsItemsApi;

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/export_listings_items.php [--account=IGE|DOWS] [--force] [--limit=N]

Flags:
  --account=IGE|DOWS   Seller account to export. Default: IGE.
  --force              Re-fetch and overwrite existing per-SKU files.
  --limit=N            Stop after writing N SKU files (canary mode).
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

$amazon      = new AmazonClient($account);
$paths       = amazon_paths($account);
$listingsApi = $amazon->connector->listingsItemsV20210801();

echo 'Account: ' . $amazon->account . PHP_EOL;

const INCLUDED_DATA = [
    'attributes',
    'issues',
    'summaries',
    'offers',
    'fulfillmentAvailability',
    'procurement',
    'relationships',
    'productTypes',
];

const PAGE_SIZE  = 20;
const CHUNK_SIZE = 500; // SKUs per date-range window; well under the 1000 cap

// ---------------------------------------------------------------------------
// Step 1: Probe — one call to learn the total SKU count
// ---------------------------------------------------------------------------

$probe     = AmazonRateLimits::retryWithBackoff(
    fn() => $listingsApi->searchListingsItems(
        sellerId:       $amazon->sellerId,
        marketplaceIds: [$amazon->marketplaceId],
        includedData:   INCLUDED_DATA,
        pageSize:       1,
    )->json(),
    AmazonOperationIds::SEARCH_LISTINGS_ITEMS,
);
$totalSkus = $probe['numberOfResults'] ?? 0;

echo 'Total SKUs reported by API: ' . $totalSkus . PHP_EOL;

$saved       = 0;
$skipped     = 0;
$fetchedSkus = [];

if ($totalSkus <= 1000) {
    // -----------------------------------------------------------------------
    // Fast path: paginate normally — everything fits within the API cap
    // -----------------------------------------------------------------------
    fetchAllPages($listingsApi, $amazon, $paths, $force, $limit, $fetchedSkus, $saved, $skipped);
} else {
    // -----------------------------------------------------------------------
    // Chunked path: date-range windows derived from the Phase 1 report
    // -----------------------------------------------------------------------
    $reportFiles = glob($paths['reports'] . '/listings_*.json') ?: [];
    if (!$reportFiles) {
        fwrite(STDERR, "Account {$account} has {$totalSkus} SKUs (> 1000 cap).\n");
        fwrite(STDERR, "Run export_listings_report.php --account={$account} first to enable date-range chunking.\n");
        exit(1);
    }

    rsort($reportFiles);
    $report = json_decode(file_get_contents($reportFiles[0]), true) ?? [];

    echo 'Using report: ' . basename($reportFiles[0]) . ' (' . count($report) . ' SKUs)' . PHP_EOL;

    fetchChunked($listingsApi, $amazon, $paths, $report, $force, $limit, $fetchedSkus, $saved, $skipped);
}

// ---------------------------------------------------------------------------
// Final fallback: per-SKU getListingsItem for any report SKUs still missing
// ---------------------------------------------------------------------------

$reportFiles = glob($paths['reports'] . '/listings_*.json') ?: [];
if ($reportFiles && ($limit === null || $saved < $limit)) {
    rsort($reportFiles);
    $reportSkus = array_keys(json_decode(file_get_contents($reportFiles[0]), true) ?? []);
    $missing    = array_diff($reportSkus, $fetchedSkus);

    if ($missing) {
        echo PHP_EOL . 'Fallback: ' . count($missing) . ' SKU(s) not returned by any search window...' . PHP_EOL;
        fetchFallback($listingsApi, $amazon, $paths, $missing, $force, $limit, $saved, $skipped, $fetchedSkus);
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo PHP_EOL;
echo 'Saved:   ' . $saved . PHP_EOL;
echo 'Skipped: ' . $skipped . ' (already exist; use --force to overwrite)' . PHP_EOL;
echo 'Files  → ' . $paths['listings'] . PHP_EOL;

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * Simple pagination — used when totalSkus <= 1000.
 */
function fetchAllPages(
    ListingsItemsApi $listingsApi,
    AmazonClient $amazon,
    array $paths,
    bool $force,
    ?int $limit,
    array &$fetchedSkus,
    int &$saved,
    int &$skipped,
): void {
    $pageToken = null;
    $page      = 0;

    do {
        $page++;
        $data  = AmazonRateLimits::retryWithBackoff(
            fn() => $listingsApi->searchListingsItems(
                sellerId:       $amazon->sellerId,
                marketplaceIds: [$amazon->marketplaceId],
                includedData:   INCLUDED_DATA,
                pageSize:       PAGE_SIZE,
                pageToken:      $pageToken,
            )->json(),
            AmazonOperationIds::SEARCH_LISTINGS_ITEMS,
        );

        $items = $data['items'] ?? [];
        echo '  Page ' . $page . ': ' . count($items) . ' items' . PHP_EOL;

        foreach ($items as $item) {
            if (saveItem($item, $paths, $force, $fetchedSkus, $saved, $skipped)) {
                if ($limit !== null && $saved >= $limit) {
                    echo PHP_EOL . 'Limit reached.' . PHP_EOL;
                    return;
                }
            }
        }

        $pageToken = $data['pagination']['nextToken'] ?? null;
        if ($pageToken !== null) {
            AmazonRateLimits::throttle(AmazonOperationIds::SEARCH_LISTINGS_ITEMS);
        }
    } while ($pageToken !== null);
}

/**
 * Date-range chunked fetching — used when totalSkus > 1000.
 *
 * Sorts report SKUs by open-date, groups into CHUNK_SIZE windows, and issues
 * one searchListingsItems call per window with createdAfter/createdBefore.
 */
function fetchChunked(
    ListingsItemsApi $listingsApi,
    AmazonClient $amazon,
    array $paths,
    array $report,
    bool $force,
    ?int $limit,
    array &$fetchedSkus,
    int &$saved,
    int &$skipped,
): void {
    // Parse and sort by open_date timestamp
    $skuDates = [];
    foreach ($report as $sku => $row) {
        $dateStr = $row['open-date'] ?? '';
        $ts      = $dateStr ? strtotime($dateStr) : 0;
        if ($ts !== false && $ts > 0) {
            $skuDates[$sku] = $ts;
        }
    }
    asort($skuDates);

    $chunks     = array_chunk(array_keys($skuDates), CHUNK_SIZE, preserve_keys: false);
    $totalChunks = count($chunks);

    echo 'Date-range chunks: ' . $totalChunks . ' (CHUNK_SIZE=' . CHUNK_SIZE . ')' . PHP_EOL;

    foreach ($chunks as $i => $chunkSkus) {
        $chunkNum = $i + 1;

        // Window = 1 minute buffer on each side to absorb clock skew
        $after  = new DateTimeImmutable('@' . ($skuDates[$chunkSkus[0]] - 60));
        $before = new DateTimeImmutable('@' . ($skuDates[$chunkSkus[count($chunkSkus) - 1]] + 60));

        echo PHP_EOL . "Chunk {$chunkNum}/{$totalChunks}: "
            . $after->format('Y-m-d') . ' → ' . $before->format('Y-m-d')
            . ' (' . count($chunkSkus) . ' SKUs)' . PHP_EOL;

        $pageToken = null;
        $page      = 0;

        do {
            $page++;
            $data  = AmazonRateLimits::retryWithBackoff(
                fn() => $listingsApi->searchListingsItems(
                    sellerId:       $amazon->sellerId,
                    marketplaceIds: [$amazon->marketplaceId],
                    includedData:   INCLUDED_DATA,
                    pageSize:       PAGE_SIZE,
                    createdAfter:   $after,
                    createdBefore:  $before,
                    pageToken:      $pageToken,
                )->json(),
                AmazonOperationIds::SEARCH_LISTINGS_ITEMS,
            );

            $items = $data['items'] ?? [];
            echo '  Page ' . $page . ': ' . count($items)
                . ' (window total: ' . ($data['numberOfResults'] ?? '?') . ')' . PHP_EOL;

            foreach ($items as $item) {
                if (saveItem($item, $paths, $force, $fetchedSkus, $saved, $skipped)) {
                    if ($limit !== null && $saved >= $limit) {
                        echo PHP_EOL . 'Limit reached.' . PHP_EOL;
                        return;
                    }
                }
            }

            $pageToken = $data['pagination']['nextToken'] ?? null;
            if ($pageToken !== null) {
                AmazonRateLimits::throttle(AmazonOperationIds::SEARCH_LISTINGS_ITEMS);
            }
        } while ($pageToken !== null);

        // Brief pause between windows
        if ($chunkNum < $totalChunks) {
            AmazonRateLimits::throttle(AmazonOperationIds::SEARCH_LISTINGS_ITEMS);
        }
    }
}

/**
 * Last-resort per-SKU fallback for any SKUs still not fetched after all
 * search windows (boundary gaps, clock skew, non-buyable listings, etc.).
 */
function fetchFallback(
    ListingsItemsApi $listingsApi,
    AmazonClient $amazon,
    array $paths,
    array $missingSKUs,
    bool $force,
    ?int $limit,
    int &$saved,
    int &$skipped,
    array &$fetchedSkus,
): void {
    foreach ($missingSKUs as $sku) {
        $path = $paths['listings'] . '/' . $sku . '.json';
        if (file_exists($path) && !$force) {
            $skipped++;
            continue;
        }

        echo '  getListingsItem: ' . $sku . PHP_EOL;

        try {
            $item = AmazonRateLimits::retryWithBackoff(
                fn() => $listingsApi->getListingsItem(
                    sellerId:       $amazon->sellerId,
                    sku:            $sku,
                    marketplaceIds: [$amazon->marketplaceId],
                    includedData:   INCLUDED_DATA,
                )->json(),
                AmazonOperationIds::GET_LISTINGS_ITEM,
            );
        } catch (\Saloon\Exceptions\Request\ClientException $e) {
            fwrite(STDERR, "  SKIP {$sku}: HTTP {$e->getStatus()} — {$e->getMessage()}\n");
            continue;
        }

        file_put_contents($path, json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $fetchedSkus[] = $sku;
        $saved++;

        AmazonRateLimits::throttle(AmazonOperationIds::GET_LISTINGS_ITEM);

        if ($limit !== null && $saved >= $limit) {
            echo PHP_EOL . 'Limit reached.' . PHP_EOL;
            return;
        }
    }
}

/**
 * Write one item to disk if not already present (or if --force).
 * Returns true if the item was processed (saved or skipped), false if sku empty.
 */
function saveItem(
    array $item,
    array $paths,
    bool $force,
    array &$fetchedSkus,
    int &$saved,
    int &$skipped,
): bool {
    $sku = $item['sku'] ?? '';
    if ($sku === '') {
        return false;
    }

    $fetchedSkus[] = $sku;
    $path = $paths['listings'] . '/' . $sku . '.json';

    if (file_exists($path) && !$force) {
        $skipped++;
        return true;
    }

    file_put_contents($path, json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $saved++;
    return true;
}
