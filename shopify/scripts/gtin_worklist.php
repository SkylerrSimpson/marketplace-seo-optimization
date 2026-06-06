<?php

declare(strict_types=1);

/**
 * GTIN / barcode worklist (READ ONLY — no writes to the store).
 *
 * Pulls every product + variant live and reports which variants are missing a
 * `barcode` (Shopify's UPC/EAN/GTIN field). Produces:
 *   - data/output/gtin_status_all.csv      (every variant + has/missing barcode)
 *   - data/output/gtin_missing.csv          (only the variants missing a barcode,
 *                                             with SKU so the supplier can fill UPCs)
 * and prints a summary.
 *
 * Context uses ONLY read_products. No mutation is issued.
 *
 * Usage: php gtin_worklist.php
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

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
    scopes: ['read_products'],
    hostName: $shopDomain,
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $apiVersion,
    isEmbeddedApp: false,
);

$client = new Graphql($shopDomain, $accessToken);

$query = <<<'GQL'
query GtinWorklist($cursor: String) {
  products(first: 60, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      title
      handle
      vendor
      productType
      status
      variants(first: 100) {
        nodes {
          id
          title
          sku
          barcode
        }
      }
    }
  }
}
GQL;

function fetchPage(Graphql $client, string $query, ?string $cursor): array
{
    $attempts = 0;
    while (true) {
        $attempts++;
        $resp = $client->query(['query' => $query, 'variables' => ['cursor' => $cursor]]);
        $body = $resp->getDecodedBody();
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $e) {
                if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; }
            }
            if ($throttled && $attempts < 6) { sleep(3); continue; }
            fwrite(STDERR, "GraphQL error: " . json_encode($body['errors']) . "\n");
            exit(1);
        }
        $p = $body['data']['products'];
        return ['nodes' => $p['nodes'], 'hasNext' => $p['pageInfo']['hasNextPage'], 'cursor' => $p['pageInfo']['endCursor']];
    }
}

$cursor = null;
$rowsAll     = [];
$rowsMissing = [];
$numProducts = 0;
$numVariants = 0;
$numMissing  = 0;
$productsFullyMissing = 0; // products where EVERY variant lacks a barcode

echo "Pulling catalog from {$shopDomain} (read only)...\n";

do {
    $page = fetchPage($client, $query, $cursor);
    foreach ($page['nodes'] as $p) {
        $numProducts++;
        $pid    = (string) preg_replace('#.*/#', '', (string) $p['id']);
        $title  = (string) ($p['title'] ?? '');
        $handle = (string) ($p['handle'] ?? '');
        $vendor = (string) ($p['vendor'] ?? '');
        $ptype  = (string) ($p['productType'] ?? '');
        $status = (string) ($p['status'] ?? '');

        $variants = $p['variants']['nodes'] ?? [];
        $missingInProduct = 0;
        foreach ($variants as $v) {
            $numVariants++;
            $vid     = (string) preg_replace('#.*/#', '', (string) $v['id']);
            $vtitle  = (string) ($v['title'] ?? '');
            $sku     = (string) ($v['sku'] ?? '');
            $barcode = trim((string) ($v['barcode'] ?? ''));
            $hasBarcode = $barcode !== '';
            if (!$hasBarcode) { $numMissing++; $missingInProduct++; }

            $row = [$pid, $handle, $title, $status, $vid, $vtitle, $sku, $barcode, $hasBarcode ? 'has' : 'MISSING'];
            $rowsAll[] = $row;
            if (!$hasBarcode) {
                // supplier-facing: blank gtin column for them to fill
                $rowsMissing[] = [$pid, $handle, $title, $ptype, $vid, $vtitle, $sku, ''];
            }
        }
        if ($missingInProduct === count($variants) && count($variants) > 0) {
            $productsFullyMissing++;
        }
    }
    $cursor = $page['cursor'];
    echo "  ...products: {$numProducts}  variants: {$numVariants}  missing: {$numMissing}\n";
    usleep(300_000);
} while ($page['hasNext']);

function writeCsv(string $path, array $header, array $rows): void
{
    $fh = fopen($path, 'w');
    fputcsv($fh, $header);
    foreach ($rows as $r) { fputcsv($fh, $r); }
    fclose($fh);
}

$outDir = SHOPIFY_DATA . '/output';
if (!is_dir($outDir)) { @mkdir($outDir, 0775, true); }

writeCsv(
    $outDir . '/gtin_status_all.csv',
    ['product_id', 'handle', 'product_title', 'status', 'variant_id', 'variant_title', 'sku', 'current_barcode', 'gtin_state'],
    $rowsAll
);
writeCsv(
    $outDir . '/gtin_missing.csv',
    ['product_id', 'handle', 'product_title', 'product_type', 'variant_id', 'variant_title', 'sku', 'gtin_to_fill'],
    $rowsMissing
);

echo "\n========== GTIN WORKLIST SUMMARY ==========\n";
echo "Products scanned:           {$numProducts}\n";
echo "Variants scanned:           {$numVariants}\n";
echo "Variants WITH barcode:      " . ($numVariants - $numMissing) . "\n";
echo "Variants MISSING barcode:   {$numMissing}\n";
echo "Products with ALL variants missing: {$productsFullyMissing}\n";
echo "-------------------------------------------\n";
echo "Wrote: {$outDir}/gtin_status_all.csv  ({$numVariants} rows)\n";
echo "Wrote: {$outDir}/gtin_missing.csv     ({$numMissing} rows)\n";
