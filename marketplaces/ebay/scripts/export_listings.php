<?php

declare(strict_types=1);

/**
 * PHASE 1 (enumeration half) — list every ACTIVE listing for an eBay account.
 *
 * Why this shape: the data we ultimately need per listing is Item Specifics +
 * leaf category, but no single REACHABLE REST endpoint gives that for this
 * store's LEGACY listings:
 *   - Trading GetSellerList/GetItem (has specifics) is Akamai edge-blocked here.
 *   - Modern Inventory API is empty (these listings predate it).
 *   - Sell Feed LMS_ACTIVE_INVENTORY_REPORT (this script) is reachable but only
 *     returns ItemID / SKU / Price / Quantity / SiteID — NO specifics.
 * So this script ENUMERATES the active roster (ItemID + SKU + format); a later
 * enrichment step (Browse getItem) attaches specifics + category per ItemID.
 *
 * Flow (Sell Feed API, REST, https://api.ebay.com/sell/feed/v1):
 *   1. POST  /inventory_task  {feedType:LMS_ACTIVE_INVENTORY_REPORT,...}  -> 202 + Location (taskId)
 *   2. GET   /inventory_task/{taskId}                                     -> poll until COMPLETED
 *   3. GET   /inventory_task/{taskId}/download_result_file               -> gzip/zip of the report XML
 *   4. parse SKUDetails -> per-account sidecar + summary
 *
 * The POST in step 1 creates a REPORT task; it does NOT modify any listing.
 *
 * Output (under marketplaces/ebay/data/{account}/output/):
 *   active_inventory_report.xml   raw decompressed report (for schema inspection)
 *   listings.json                 [{item_id, sku, price, quantity, site_id, format}]
 *   skus.csv                      same, flat, for eyeballing
 *
 * Usage:
 *   php marketplaces/ebay/scripts/export_listings.php --account=dows
 *   php marketplaces/ebay/scripts/export_listings.php --account=ige --task=12345   # reuse an existing task
 *   php marketplaces/ebay/scripts/export_listings.php --account=dows --format=FIXED_PRICE
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

const FEED_BASE   = 'https://api.ebay.com/sell/feed/v1';
const MARKETPLACE = 'EBAY_US';
const POLL_SECONDS = 5;
const POLL_MAX     = 60;   // ~5 min ceiling

$opts    = getopt('', ['account:', 'task:', 'format:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php export_listings.php --account=dows|ige [--task=ID] [--format=FIXED_PRICE|AUCTION]\n");
    exit(0);
}
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$format  = isset($opts['format']) ? strtoupper((string) $opts['format']) : null;

$client = new EbayClient($account);
$outDir = ebay_dir($account, 'output');

echo "=== Phase 1 export: {$account} (" . $client->env() . ") ===\n";

// --- Step 1: get a task (reuse --task, else create one) ------------------------
// eBay returns the task's resource URL in the Location header; it may be under
// /task/{id} OR /inventory_task/{id}. We track the full URL and poll/download it.
$taskId  = null;
$taskUrl = null;

if (isset($opts['task'])) {
    $taskId  = (string) $opts['task'];
    // Accept a bare id (default to /task/) or a full URL.
    $taskUrl = str_starts_with($taskId, 'http') ? $taskId : FEED_BASE . "/task/{$taskId}";
    echo "Reusing task: {$taskId}\n";
} else {
    $payload = ['feedType' => 'LMS_ACTIVE_INVENTORY_REPORT', 'schemaVersion' => '1.0'];
    if ($format !== null) {
        $payload['filterCriteria'] = ['listingFormat' => $format];
    }
    echo "Creating ACTIVE_INVENTORY_REPORT task (report only; no listing changes)...\n";
    $res = $client->userSend('POST', FEED_BASE . '/inventory_task', json_encode($payload), [
        'X-EBAY-C-MARKETPLACE-ID' => MARKETPLACE,
    ]);
    if (!in_array($res['status'], [200, 201, 202], true)) {
        fwrite(STDERR, "  [ERR] create task {$res['status']}: " . substr($res['body'], 0, 400) . "\n");
        exit(1);
    }
    $loc = $res['headers']['location'] ?? '';
    if ($loc !== '' && preg_match('#/(?:inventory_)?task/([^/?]+)#', $loc, $m)) {
        $taskId  = $m[1];
        $taskUrl = $loc;
    } else {
        $taskId  = (string) ($res['json']['taskId'] ?? '');
        $taskUrl = FEED_BASE . "/task/{$taskId}";
    }
    if ($taskId === '') {
        fwrite(STDERR, "  [ERR] no taskId in response (Location='{$loc}')\n");
        exit(1);
    }
    echo "  task created: {$taskId}\n";
}

// --- Step 2: poll until COMPLETED ---------------------------------------------
$status = '';
for ($i = 0; $i < POLL_MAX; $i++) {
    $res = $client->userSend('GET', $taskUrl);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        fwrite(STDERR, "  [ERR] get task {$res['status']}: " . substr($res['body'], 0, 400) . "\n");
        exit(1);
    }
    $status = (string) ($res['json']['status'] ?? 'UNKNOWN');
    echo "  poll " . ($i + 1) . ": {$status}\n";
    if ($status === 'COMPLETED') { break; }
    if (in_array($status, ['COMPLETED_WITH_ERROR', 'FAILED'], true)) {
        fwrite(STDERR, "  [ERR] task ended {$status}: " . json_encode($res['json']['detail'] ?? $res['json']) . "\n");
        // COMPLETED_WITH_ERROR can still have a partial result file — fall through to try download.
        if ($status === 'FAILED') { exit(1); }
        break;
    }
    sleep(POLL_SECONDS);
}
if ($status !== 'COMPLETED' && $status !== 'COMPLETED_WITH_ERROR') {
    fwrite(STDERR, "  [ERR] task did not complete in time (last status: {$status}). Re-run with --task={$taskId}.\n");
    exit(1);
}

// --- Step 3: download + decompress the report ---------------------------------
echo "Downloading result file...\n";
$blob = $client->userDownload($taskUrl . '/download_result_file');
$xml  = decompress($blob);

$xmlPath = $outDir . '/active_inventory_report.xml';
file_put_contents($xmlPath, $xml);
echo "  raw report: {$xmlPath} (" . number_format(strlen($xml)) . " bytes)\n";

// --- Step 4: parse the report -------------------------------------------------
[$listings, $holds] = parseReport($xml);

// listings.json: one structured entry per ItemID, variations nested. This is the
// roster the enrichment step (Browse getItem) iterates by ItemID.
$jsonPath = $outDir . '/listings.json';
file_put_contents($jsonPath, json_encode($listings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// skus.csv: one row per SELLABLE sku (parent simple listings + every variation).
// This is the grain we match against Usurper master data.
$csvPath = $outDir . '/skus.csv';
$fh = fopen($csvPath, 'w');
fputcsv($fh, ['item_id', 'sku', 'is_variation', 'price', 'quantity', 'variation_specifics']);
$skuCount = 0;
foreach ($listings as $l) {
    if ($l['variations'] === []) {
        fputcsv($fh, [$l['item_id'], $l['sku'], 0, $l['price'], $l['quantity'], '']);
        $skuCount++;
    } else {
        foreach ($l['variations'] as $v) {
            fputcsv($fh, [$l['item_id'], $v['sku'], 1, $v['price'], $v['quantity'], $v['specifics']]);
            $skuCount++;
        }
    }
}
fclose($fh);

$withVars = count(array_filter($listings, fn($l) => $l['variations'] !== []));
echo "\n========================================\n";
echo "listings (ItemIDs): " . count($listings) . " | sellable SKUs: {$skuCount} | multi-variation listings: {$withVars}\n";
if ($holds !== []) {
    echo "ON HOLD / policy issues ({" . count($holds) . "}): " . implode('; ', array_map(fn($h) => "[{$h['code']}] {$h['message']}", $holds)) . "\n";
}
echo "  {$jsonPath}\n  {$csvPath}\n";

// --- helpers -------------------------------------------------------------------

/** Decompress a Feed result blob (gzip or zip), else return as-is. */
function decompress(string $blob): string
{
    if (strncmp($blob, "\x1f\x8b", 2) === 0) {            // gzip magic
        $out = @gzdecode($blob);
        return $out === false ? $blob : $out;
    }
    if (strncmp($blob, "PK\x03\x04", 4) === 0) {          // zip magic
        $tmp = tempnam(sys_get_temp_dir(), 'ebayrep');
        file_put_contents($tmp, $blob);
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            $name = $zip->getNameIndex(0);
            $out  = $name !== false ? $zip->getFromName($name) : false;
            $zip->close();
            @unlink($tmp);
            if ($out !== false) { return $out; }
        }
        @unlink($tmp);
    }
    return $blob;
}

/**
 * Parse the ActiveInventoryReport XML. Schema namespace is
 * urn:ebay:apis:eBLBaseComponents; each active listing is a <SKUDetails> with an
 * ItemID, a parent SKU, and either a flat price/qty (simple listing) or a
 * <Variations> block of per-variation SKU/price/qty + VariationSpecifics.
 *
 * NOTE: this report does NOT carry item-level Item Specifics (aspects) or the
 * leaf category — only variation-defining specifics. Those are fetched in the
 * enrichment step (Browse getItem) keyed by ItemID.
 *
 * @return array{0: list<array{item_id:string,sku:string,price:string,quantity:string,variations:list<array{sku:string,price:string,quantity:string,specifics:string}>}>, 1: list<array{code:string,message:string}>}
 */
function parseReport(string $xml): array
{
    $listings = [];
    $holds    = [];
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
        fwrite(STDERR, "  [WARN] could not parse report XML; inspect the raw file.\n");
        return [$listings, $holds];
    }
    $sx->registerXPathNamespace('e', 'urn:ebay:apis:eBLBaseComponents');

    foreach ($sx->xpath('//e:Errors') ?: [] as $e) {
        $holds[] = ['code' => (string) $e->ErrorCode, 'message' => trim((string) $e->LongMessage)];
    }

    $nodes = $sx->xpath('//e:SKUDetails') ?: ($sx->xpath('//SKUDetails') ?: []);
    foreach ($nodes as $n) {
        $variations = [];
        if (isset($n->Variations)) {
            foreach ($n->Variations->Variation as $v) {
                $specs = [];
                foreach ($v->VariationSpecifics->NameValueList ?? [] as $nv) {
                    $specs[] = trim((string) $nv->Name) . '=' . trim((string) $nv->Value);
                }
                $variations[] = [
                    'sku'       => (string) $v->SKU,
                    'price'     => (string) $v->Price,
                    'quantity'  => (string) $v->Quantity,
                    'specifics' => implode('; ', $specs),
                ];
            }
        }
        $listings[] = [
            'item_id'    => (string) ($n->ItemID ?? ''),
            'sku'        => (string) ($n->SKU ?? ''),
            'price'      => (string) ($n->Price ?? ''),
            'quantity'   => (string) ($n->Quantity ?? ''),
            'variations' => $variations,
        ];
    }
    return [$listings, $holds];
}
