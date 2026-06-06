<?php

declare(strict_types=1);

/**
 * Back-fill missing variant GTIN/UPC (barcode) into Shopify from the
 * inventory-sourced sheet.  Updates ONLY variant.barcode via
 * productVariantsBulkUpdate. Keys every write on variant_id (NOT sku, since a
 * few variants have blank skus).
 *
 * SOURCE: shopify/data/output/gtin_missing_partial_fill.csv
 *   uses the "BEST UPC" column (inventory guy's authoritative value).
 *
 * SAFETY:
 *   - DRY-RUN BY DEFAULT. Logs intended changes; sends nothing. Pass --apply to write.
 *   - Validates each UPC: digits only, length 12-14, GTIN check-digit must pass.
 *     Anything failing validation is SKIPPED and reported (never written).
 *   - Skips blank BEST UPC (still being sourced).
 *   - Skips any UPC that appears on >1 variant (duplicate) UNLESS --allow-dupes,
 *     because each variant should carry its own GTIN (e.g. the 4 paydirt sizes).
 *   - IDEMPOTENT: reads each product's current variant barcodes first; a variant
 *     already holding the target barcode is SKIPPED (no redundant write).
 *   - Per-product non-fatal: one product's userError won't abort the batch.
 *   - Needs write_products scope; will 403 on --apply until re-authorized.
 *
 * USAGE:
 *   php apply_gtins.php                 # dry-run, all (default)
 *   php apply_gtins.php --limit 3       # dry-run, first 3 products
 *   php apply_gtins.php --apply         # LIVE write (needs write_products)
 *   php apply_gtins.php --apply --limit 3   # live canary on first 3 products
 *   php apply_gtins.php --allow-dupes   # also include duplicate-UPC variants
 *   php apply_gtins.php --csv           # write apply_gtins_plan.csv of the plan
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

const THROTTLE_FLOOR = 200;
const SRC_CSV        = '/output/gtin_missing_partial_fill.csv';
const BEST_COL       = 'BEST UPC';

// ---- args ----------------------------------------------------------------
$apply      = in_array('--apply', $argv, true);
$allowDupes = in_array('--allow-dupes', $argv, true);
$writeCsv   = in_array('--csv', $argv, true);
$limit      = null;
$idx = array_search('--limit', $argv, true);
if ($idx !== false && isset($argv[$idx + 1]) && is_numeric($argv[$idx + 1])) {
    $limit = (int) $argv[$idx + 1];
}

// ---- env / context -------------------------------------------------------
$shopDomain  = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion  = $_ENV['API_VERSION']     ?? '2026-04';
if ($shopDomain === '' || $accessToken === '') {
    fwrite(STDERR, "Missing SHOP_DOMAIN or ADMIN_API_TOKEN in .env\n");
    exit(1);
}
Context::initialize(
    apiKey: $_ENV['APP_API_KEY'] ?? 'custom-app',
    apiSecretKey: $_ENV['APP_API_SECRET'] ?? 'custom-app',
    scopes: ['write_products'],
    hostName: $shopDomain,
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $apiVersion,
    isEmbeddedApp: false,
);
$client = new Graphql($shopDomain, $accessToken);

// ---- helpers -------------------------------------------------------------
// Strip non-digits AND restore a leading zero stripped by spreadsheets: a
// UPC-A is 12 digits, so a bare 11-digit value is a 12-digit UPC that lost its
// leading 0 (e.g. "60886070054" -> "060886070054"). Pad 11 -> 12.
function clean_upc(?string $v): string
{
    $d = preg_replace('/\D/', '', (string) $v);
    if (strlen($d) === 11) { $d = '0' . $d; }
    return $d;
}

function gtin_check_ok(string $d): bool
{
    $len = strlen($d);
    if ($len < 12 || $len > 14) { return false; }
    $body  = substr($d, 0, -1);
    $check = (int) substr($d, -1);
    $sum = 0;
    $rev = strrev($body);
    for ($i = 0; $i < strlen($rev); $i++) {
        $sum += ((int) $rev[$i]) * ($i % 2 === 0 ? 3 : 1);
    }
    return ((10 - ($sum % 10)) % 10) === $check;
}

const READ_VARIANTS = <<<'GQL'
query($id: ID!) {
  product(id: $id) { variants(first: 100) { nodes { id barcode } } }
}
GQL;

const BULK_MUTATION = <<<'GQL'
mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id barcode }
    userErrors { field message }
  }
}
GQL;

function gql(Graphql $client, string $query, array $variables, bool $fatal = true): array
{
    $attempts = 0;
    while (true) {
        $attempts++;
        $resp = $client->query(['query' => $query, 'variables' => $variables]);
        $body = $resp->getDecodedBody();
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $e) {
                if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; }
            }
            if ($throttled && $attempts < 8) { sleep(2 * $attempts); continue; }
            if ($fatal) {
                fwrite(STDERR, "GraphQL error: " . json_encode($body['errors']) . "\n");
                exit(1);
            }
            return [[], [], $body['errors']];
        }
        $cost = $body['extensions']['cost']['throttleStatus'] ?? null;
        if ($cost !== null && ($cost['currentlyAvailable'] ?? 9999) < THROTTLE_FLOOR) {
            $restore = max(1, (int) ($cost['restoreRate'] ?? 50));
            $need    = THROTTLE_FLOOR - (int) $cost['currentlyAvailable'];
            sleep((int) ceil($need / $restore));
        }
        return [$body['data'] ?? [], $body['extensions'] ?? [], []];
    }
}

// ---- load + validate the sheet ------------------------------------------
$path = SHOPIFY_DATA . SRC_CSV;
if (!is_file($path)) { fwrite(STDERR, "Source CSV not found: {$path}\n"); exit(1); }
$fh = fopen($path, 'r');
$header = fgetcsv($fh);
$col = array_flip($header);
foreach (['product_id', 'variant_id', BEST_COL] as $need) {
    if (!isset($col[$need])) { fwrite(STDERR, "CSV missing column: {$need}\n"); exit(1); }
}

$candidates = [];   // valid, ready
$skipBlank = 0; $skipInvalid = []; $skipDupe = [];
$rawByUpc = [];     // upc => count (for duplicate detection)

$all = [];
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) < count($header)) { $r = array_pad($r, count($header), ''); }
    $all[] = $r;
    $upc = clean_upc($r[$col[BEST_COL]]);
    if ($upc !== '') { $rawByUpc[$upc] = ($rawByUpc[$upc] ?? 0) + 1; }
}
fclose($fh);

foreach ($all as $r) {
    $pid   = trim($r[$col['product_id']]);
    $vid   = trim($r[$col['variant_id']]);
    $vtit  = $r[$col['variant_title']] ?? '';
    $ptit  = $r[$col['product_title']] ?? '';
    $upc   = clean_upc($r[$col[BEST_COL]]);

    if ($upc === '') { $skipBlank++; continue; }
    if (!gtin_check_ok($upc)) { $skipInvalid[] = "{$ptit} / {$vtit}: '{$upc}'"; continue; }
    $isDupe = ($rawByUpc[$upc] ?? 0) > 1;
    if ($isDupe && !$allowDupes) { $skipDupe[] = "{$ptit} / {$vtit}: {$upc}"; continue; }

    $candidates[$pid][] = ['vid' => $vid, 'upc' => $upc, 'vtit' => $vtit, 'ptit' => $ptit];
}

if ($limit !== null) {
    $candidates = array_slice($candidates, 0, $limit, true);
}

// ---- report plan ---------------------------------------------------------
$totalWrites = array_sum(array_map('count', $candidates));
echo ($apply ? "LIVE APPLY" : "DRY-RUN") . " — back-fill variant barcode/GTIN\n";
echo "Source: {$path} (column '" . BEST_COL . "')\n";
echo str_repeat('-', 60) . "\n";
echo "Products to touch:        " . count($candidates) . "\n";
echo "Variant writes planned:   {$totalWrites}\n";
echo "Skipped — blank UPC:      {$skipBlank}\n";
echo "Skipped — failed check:   " . count($skipInvalid) . "\n";
echo "Skipped — duplicate UPC:  " . count($skipDupe) . ($allowDupes ? " (included via --allow-dupes)" : "") . "\n";
if ($skipInvalid) { echo "  invalid:\n   - " . implode("\n   - ", $skipInvalid) . "\n"; }
if ($skipDupe && !$allowDupes) { echo "  duplicates held back:\n   - " . implode("\n   - ", $skipDupe) . "\n"; }
echo str_repeat('-', 60) . "\n";
if (!$apply) { echo "(dry-run: nothing sent. Pass --apply to write — needs write_products scope)\n"; }

// ---- optional plan CSV ---------------------------------------------------
if ($writeCsv) {
    $out = fopen(SHOPIFY_DATA . '/output/apply_gtins_plan.csv', 'w');
    fputcsv($out, ['product_id', 'variant_id', 'product_title', 'variant_title', 'upc_to_write']);
    foreach ($candidates as $pid => $vs) {
        foreach ($vs as $v) { fputcsv($out, [$pid, $v['vid'], $v['ptit'], $v['vtit'], $v['upc']]); }
    }
    fclose($out);
    echo "Wrote plan: " . SHOPIFY_DATA . "/output/apply_gtins_plan.csv\n";
}

// ---- execute -------------------------------------------------------------
$written = 0; $skippedSame = 0; $failed = 0; $done = 0;
foreach ($candidates as $pid => $vs) {
    $done++;
    $gidProduct = "gid://shopify/Product/{$pid}";

    // idempotency: pull current barcodes for this product's variants
    [$rd] = gql($client, READ_VARIANTS, ['id' => $gidProduct], false);
    $current = [];
    foreach ($rd['product']['variants']['nodes'] ?? [] as $n) {
        $current[(string) preg_replace('#.*/#', '', $n['id'])] = trim((string) ($n['barcode'] ?? ''));
    }

    $variantsInput = [];
    foreach ($vs as $v) {
        if (($current[$v['vid']] ?? null) === $v['upc']) { $skippedSame++; continue; }
        $variantsInput[] = ['id' => "gid://shopify/ProductVariant/{$v['vid']}", 'barcode' => $v['upc']];
    }
    if (empty($variantsInput)) {
        echo "  [{$done}/" . count($candidates) . "] {$vs[0]['ptit']} — already correct, skip\n";
        continue;
    }

    echo "  [{$done}/" . count($candidates) . "] {$vs[0]['ptit']} — " . count($variantsInput) . " variant(s)";
    if (!$apply) { echo " (dry-run)\n"; $written += count($variantsInput); continue; }

    [$data, , $errs] = gql($client, BULK_MUTATION,
        ['productId' => $gidProduct, 'variants' => $variantsInput], false);
    if (!empty($errs)) {
        $failed += count($variantsInput);
        echo " — FAILED: " . json_encode($errs) . "\n";
        continue;
    }
    $ue = $data['productVariantsBulkUpdate']['userErrors'] ?? [];
    if (!empty($ue)) {
        $failed += count($variantsInput);
        echo " — userErrors: " . json_encode($ue) . "\n";
        continue;
    }
    $written += count($data['productVariantsBulkUpdate']['productVariants'] ?? $variantsInput);
    echo " — OK\n";
    usleep(300_000);
}

echo str_repeat('=', 60) . "\n";
echo ($apply ? "APPLIED" : "DRY-RUN COMPLETE") . "\n";
echo "  variant barcodes " . ($apply ? "written" : "would write") . ": {$written}\n";
echo "  already-correct skipped: {$skippedSame}\n";
if ($apply) { echo "  failed: {$failed}\n"; }
