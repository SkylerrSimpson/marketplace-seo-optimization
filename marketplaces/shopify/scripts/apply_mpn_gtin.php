<?php

declare(strict_types=1);

/**
 * Finish the Shopify identifier back-fill from the authoritative sheet
 * data/output/mpn_worklist_updated.csv (hand-reviewed; no producer script in
 * this repo). Two writes per variant:
 *
 *   1. variant.barcode  <- "correct GTIN-14"   (REPLACES the current barcode;
 *      the previous GTINs were entered wrong). Done via productVariantsBulkUpdate.
 *   2. metafield mm-google-shopping.mpn <- "MPN (fill in)"  via metafieldsSet.
 *
 * CRITICAL — leading zeros: GTIN-14 values carry two leading zeros
 * (e.g. 00810131703004). They are read as STRINGS from the CSV and sent as
 * strings; never cast to int or the zeros vanish. Every GTIN-14 is validated
 * (14 chars after digit-strip is allowed, length 12-14, GS1 check digit) and a
 * failing value is SKIPPED + reported, never written.
 *
 * Keyed on variant_id (some variants have blank SKUs). Idempotent: reads current
 * barcode + mpn for every variant first and writes only the deltas. Rows with a
 * blank GTIN-14 / blank MPN are skipped (the no-identifier bucket).
 *
 * DRY-RUN by default. Flags:
 *   --apply            write live (needs write_products)
 *   --only=gtin|mpn    do just one side (default: both)
 *   --limit=N          first N worklist rows (after parsing)
 *   --ids=VID,VID      restrict to specific variant_ids (canary subset)
 *
 * Usage:
 *   php marketplaces/shopify/scripts/apply_mpn_gtin.php                 # dry-run, both
 *   php marketplaces/shopify/scripts/apply_mpn_gtin.php --only=gtin     # dry-run, barcodes only
 *   php marketplaces/shopify/scripts/apply_mpn_gtin.php --apply         # live, both
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

const NS         = 'mm-google-shopping';
const MPN_KEY    = 'mpn';
const SRC        = '/output/mpn_worklist_updated.csv';
const THROTTLE_FLOOR = 200;

$apply = in_array('--apply', $argv, true);
$only  = 'both';
$limit = null;
$onlyIds = null;
foreach ($argv as $a) {
    if (preg_match('/^--only=(gtin|mpn|both)$/', $a, $m)) { $only = $m[1]; }
    if (preg_match('/^--limit=(\d+)$/', $a, $m))          { $limit = (int) $m[1]; }
    if (preg_match('/^--ids=(.+)$/', $a, $m))             { $onlyIds = array_filter(array_map('trim', explode(',', $m[1]))); }
}
$doGtin = ($only === 'both' || $only === 'gtin');
$doMpn  = ($only === 'both' || $only === 'mpn');

$shopDomain  = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion  = $_ENV['API_VERSION']     ?? '2026-04';
if ($shopDomain === '' || $accessToken === '') { fwrite(STDERR, "Missing SHOP_DOMAIN/ADMIN_API_TOKEN\n"); exit(1); }

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

function gtin_check_ok(string $d): bool
{
    $len = strlen($d);
    if ($len < 12 || $len > 14) { return false; }
    $body  = substr($d, 0, -1);
    $check = (int) substr($d, -1);
    $sum = 0; $rev = strrev($body);
    for ($i = 0; $i < strlen($rev); $i++) { $sum += ((int) $rev[$i]) * ($i % 2 === 0 ? 3 : 1); }
    return ((10 - ($sum % 10)) % 10) === $check;
}

function gql(Graphql $client, string $query, array $vars, bool $fatal = true): array
{
    $attempts = 0;
    while (true) {
        $attempts++;
        try {
            $body = $client->query(['query' => $query, 'variables' => $vars])->getDecodedBody();
        } catch (\Throwable $e) {
            if ($attempts < 8) { sleep(min(2 * $attempts, 10)); continue; }
            return ['__error' => [['message' => 'HTTP: ' . $e->getMessage()]]];
        }
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $e) { if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; } }
            if ($throttled && $attempts < 8) { sleep(2 * $attempts); continue; }
            if ($fatal) { fwrite(STDERR, "GraphQL error: " . json_encode($body['errors']) . "\n"); exit(1); }
            return ['__error' => $body['errors']];
        }
        $cost = $body['extensions']['cost']['throttleStatus'] ?? null;
        if ($cost !== null && ($cost['currentlyAvailable'] ?? 9999) < THROTTLE_FLOOR) {
            $restore = max(1, (int) ($cost['restoreRate'] ?? 50));
            sleep((int) ceil((THROTTLE_FLOOR - (int) $cost['currentlyAvailable']) / $restore));
        }
        return $body['data'] ?? [];
    }
}

// ---- load worklist -----------------------------------------------------------
$path = SHOPIFY_DATA . SRC;
if (!is_file($path)) { fwrite(STDERR, "Missing {$path}\n"); exit(1); }
$fh = fopen($path, 'r');
$hdr = fgetcsv($fh); $col = array_flip($hdr);
foreach (['variant_id', 'product_id', 'correct GTIN-14', 'MPN (fill in)'] as $need) {
    if (!isset($col[$need])) { fwrite(STDERR, "CSV missing column: {$need}\n"); exit(1); }
}

$rows = [];           // variant_id => [pid, gtin, mpn, label]
$badGtin = []; $blankGtin = 0; $blankMpn = 0;
$gtinUse = [];        // gtin => [variant_ids] for dupe report
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) < count($hdr)) { $r = array_pad($r, count($hdr), ''); }
    $vid  = trim((string) $r[$col['variant_id']]);
    $pid  = trim((string) $r[$col['product_id']]);
    if ($vid === '' || $pid === '') { continue; }
    $gtin = trim((string) $r[$col['correct GTIN-14']]);   // keep as STRING — leading zeros
    $mpn  = trim((string) $r[$col['MPN (fill in)']]);
    $label = trim((string) $r[$col['product_title']]) . ' / ' . trim((string) $r[$col['variant']]);

    if ($gtin === '') { $blankGtin++; }
    elseif (!ctype_digit($gtin) || !gtin_check_ok($gtin)) { $badGtin[] = "{$gtin} ({$label})"; $gtin = ''; }
    else { $gtinUse[$gtin][] = $vid; }

    if ($mpn === '') { $blankMpn++; }
    elseif (!mb_check_encoding($mpn, 'ASCII')) { $mpn = ''; }   // never write non-ASCII

    $rows[$vid] = ['pid' => $pid, 'gtin' => $gtin, 'mpn' => $mpn, 'label' => $label];
}
fclose($fh);
if ($onlyIds !== null) { $rows = array_intersect_key($rows, array_flip($onlyIds)); }
if ($limit !== null)   { $rows = array_slice($rows, 0, $limit, true); }

// ---- read current state for idempotency (paginate all products) --------------
const READ = 'query($after:String){ products(first:50, after:$after){ pageInfo{hasNextPage endCursor}
  nodes{ variants(first:100){ nodes{ id barcode
    mpn:metafield(namespace:"mm-google-shopping",key:"mpn"){ value } } } } } }';
$curBarcode = []; $curMpn = [];   // variant_numeric_id => value
$after = null;
do {
    $d = gql($client, READ, ['after' => $after]);
    if (isset($d['__error'])) { fwrite(STDERR, "READ ERR: " . json_encode($d['__error']) . "\n"); exit(1); }
    foreach ($d['products']['nodes'] as $p) {
        foreach ($p['variants']['nodes'] as $v) {
            $vid = preg_replace('#.*/#', '', $v['id']);
            $curBarcode[$vid] = trim((string) ($v['barcode'] ?? ''));
            $curMpn[$vid]     = trim((string) ($v['mpn']['value'] ?? ''));
        }
    }
    $after = $d['products']['pageInfo']['hasNextPage'] ? $d['products']['pageInfo']['endCursor'] : null;
    usleep(150000);
} while ($after);

// ---- compute deltas ----------------------------------------------------------
$gtinByProduct = [];   // pid => [ [vid,gtin], ... ]
$mpnPending    = [];   // metafieldsSet inputs
$gtinSame = 0; $mpnSame = 0; $gtinPlanned = 0; $mpnPlanned = 0;
foreach ($rows as $vid => $row) {
    if ($doGtin && $row['gtin'] !== '') {
        if (($curBarcode[$vid] ?? null) === $row['gtin']) { $gtinSame++; }
        else { $gtinByProduct[$row['pid']][] = ['vid' => $vid, 'gtin' => $row['gtin']]; $gtinPlanned++; }
    }
    if ($doMpn && $row['mpn'] !== '') {
        if (($curMpn[$vid] ?? null) === $row['mpn']) { $mpnSame++; }
        else {
            $mpnPending[] = ['ownerId' => "gid://shopify/ProductVariant/{$vid}", 'namespace' => NS, 'key' => MPN_KEY, 'type' => 'single_line_text_field', 'value' => $row['mpn']];
            $mpnPlanned++;
        }
    }
}

// ---- report ------------------------------------------------------------------
echo ($apply ? "=== LIVE APPLY ===" : "=== DRY RUN (pass --apply) ===") . " only={$only}\n";
echo "worklist rows: " . count($rows) . "\n";
if ($doGtin) echo "GTIN-14 -> barcode: write {$gtinPlanned}, already-correct {$gtinSame}, blank {$blankGtin}, invalid " . count($badGtin) . "\n";
if ($doMpn)  echo "MPN -> metafield:   write {$mpnPlanned}, already-correct {$mpnSame}, blank {$blankMpn}\n";
if ($badGtin) { echo "  INVALID GTIN-14 (skipped):\n   - " . implode("\n   - ", $badGtin) . "\n"; }
$dupes = array_filter($gtinUse, fn($v) => count($v) > 1);
if ($dupes) {
    echo "  ⚠ GTIN-14 used on >1 variant (written as-is; verify manually):\n";
    foreach ($dupes as $g => $vids) { echo "   - {$g} -> variants " . implode(', ', $vids) . "\n"; }
}

if (!$apply) {
    echo "\nDRY RUN: nothing written. Re-run with --apply.\n";
    exit(0);
}

// ---- apply: barcodes (grouped per product) -----------------------------------
const BULK = 'mutation($productId:ID!,$variants:[ProductVariantsBulkInput!]!){
  productVariantsBulkUpdate(productId:$productId, variants:$variants){ productVariants{ id barcode } userErrors{ field message } } }';
$gtinWritten = 0; $gtinFailed = 0;
if ($doGtin) {
    foreach ($gtinByProduct as $pid => $vs) {
        $vinput = array_map(fn($v) => ['id' => "gid://shopify/ProductVariant/{$v['vid']}", 'barcode' => $v['gtin']], $vs);
        $res = gql($client, BULK, ['productId' => "gid://shopify/Product/{$pid}", 'variants' => $vinput], false);
        $ue  = $res['productVariantsBulkUpdate']['userErrors'] ?? [];
        if (isset($res['__error']) || !empty($ue)) {
            fwrite(STDERR, "  [GTIN ERR] product {$pid}: " . json_encode(!empty($ue) ? $ue : $res['__error']) . "\n");
            $gtinFailed += count($vinput); continue;
        }
        $gtinWritten += count($res['productVariantsBulkUpdate']['productVariants'] ?? $vinput);
        usleep(250000);
    }
}

// ---- apply: mpn metafields (batched) -----------------------------------------
const SETMF = 'mutation($mf:[MetafieldsSetInput!]!){ metafieldsSet(metafields:$mf){ userErrors{ field message } } }';
$mpnWritten = 0; $mpnFailed = 0;
if ($doMpn) {
    foreach (array_chunk($mpnPending, 25) as $batch) {
        $res = gql($client, SETMF, ['mf' => $batch], false);
        $ue  = $res['metafieldsSet']['userErrors'] ?? [];
        if (isset($res['__error']) || !empty($ue)) {
            fwrite(STDERR, "  [MPN ERR] batch: " . json_encode(!empty($ue) ? $ue : $res['__error']) . "\n");
            $mpnFailed += count($batch); continue;
        }
        $mpnWritten += count($batch);
        usleep(250000);
    }
}

echo "\n========================================\n";
echo "APPLIED";
if ($doGtin) echo " | barcodes written {$gtinWritten}, failed {$gtinFailed}";
if ($doMpn)  echo " | mpn written {$mpnWritten}, failed {$mpnFailed}";
echo "\n";
