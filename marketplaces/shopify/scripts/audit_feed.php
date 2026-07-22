<?php

declare(strict_types=1);

/**
 * Phase A — Feed / structured-data completeness audit (READ ONLY).
 *
 * The product feed is the backbone for AI-agent + Google Shopping pickup
 * (see geo_seo_strategy.md). This audits the structured attributes that
 * Merchant Center / ChatGPT / Gemini / Perplexity actually use to match and
 * recommend products, and flags what's missing.
 *
 * Pulls per product + variant: vendor(brand), category, status(availability),
 * images (+resolution), and per-variant sku + barcode(GTIN). Writes:
 *   - feed_audit.csv   (one row per product, with gap flags)
 *   - summary to STDOUT
 *
 * Never writes to Shopify.
 *
 * Usage: php audit_feed.php
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;


$shopDomain   = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken  = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion   = $_ENV['API_VERSION']     ?? '2026-04';
$appApiKey    = $_ENV['APP_API_KEY']     ?? 'custom-app';
$appApiSecret = $_ENV['APP_API_SECRET']  ?? 'custom-app';

if ($shopDomain === '' || $accessToken === '') {
    fwrite(STDERR, "Missing SHOP_DOMAIN or ADMIN_API_TOKEN in .env\n");
    exit(1);
}

const MIN_IMG = 500;       // Merchant Center min image edge (enforced Jan 2027)
const PAGE_SIZE = 50;      // smaller page: nested variants/images raise query cost

Context::initialize(
    apiKey: $appApiKey,
    apiSecretKey: $appApiSecret,
    scopes: ['read_products'],
    hostName: $shopDomain,
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $apiVersion,
    isEmbeddedApp: false,
);

$client = new Graphql($shopDomain, $accessToken);

$query = <<<'GQL'
query Feed($cursor: String) {
  products(first: %d, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      title
      status
      vendor
      productType
      onlineStoreUrl
      category { id fullName }
      featuredImage { width height }
      images(first: 15) { nodes { width height } }
      variants(first: 25) {
        nodes { sku barcode availableForSale }
      }
    }
  }
}
GQL;
$query = sprintf($query, PAGE_SIZE);

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

$rows = [];
$cursor = null;
$count = 0;
$totals = [];

echo "Auditing feed/structured-data fields from {$shopDomain}...\n";

do {
    $page = fetchPage($client, $query, $cursor);
    foreach ($page['nodes'] as $p) {
        $count++;
        $variants = $p['variants']['nodes'] ?? [];
        $vCount   = count($variants);

        // Variant-level: barcode (GTIN) and SKU coverage.
        $noBarcode = 0; $noSku = 0; $available = false;
        foreach ($variants as $v) {
            if (trim((string)($v['barcode'] ?? '')) === '') { $noBarcode++; }
            if (trim((string)($v['sku'] ?? '')) === '')     { $noSku++; }
            if (!empty($v['availableForSale'])) { $available = true; }
        }

        // Image resolution: smallest edge across all images.
        $imgs = $p['images']['nodes'] ?? [];
        $imgCount = count($imgs);
        $minEdge = null;
        foreach ($imgs as $im) {
            $w = (int)($im['width'] ?? 0); $h = (int)($im['height'] ?? 0);
            if ($w > 0 && $h > 0) {
                $edge = min($w, $h);
                $minEdge = $minEdge === null ? $edge : min($minEdge, $edge);
            }
        }

        $flags = [
            'no_barcode_any'      => $noBarcode === $vCount && $vCount > 0, // GTIN missing on ALL variants
            'no_barcode_some'     => $noBarcode > 0 && $noBarcode < $vCount,
            'no_sku_any'          => $noSku === $vCount && $vCount > 0,
            'no_category'         => trim((string)($p['category']['fullName'] ?? '')) === '',
            'no_brand'            => trim((string)($p['vendor'] ?? '')) === '',
            'not_active'          => ($p['status'] ?? '') !== 'ACTIVE',
            'not_available'       => !$available,
            'no_image'            => $imgCount === 0,
            'image_below_500'     => $minEdge !== null && $minEdge < MIN_IMG,
            'no_storefront_url'   => trim((string)($p['onlineStoreUrl'] ?? '')) === '',
        ];

        foreach ($flags as $k => $v) { if ($v) { $totals[$k] = ($totals[$k] ?? 0) + 1; } }

        $rows[] = [
            'numeric_id'   => preg_replace('#.*/#', '', (string)$p['id']),
            'title'        => $p['title'] ?? '',
            'status'       => $p['status'] ?? '',
            'vendor'       => $p['vendor'] ?? '',
            'variants'     => $vCount,
            'no_barcode'   => $noBarcode,
            'no_sku'       => $noSku,
            'images'       => $imgCount,
            'min_img_edge' => $minEdge ?? '',
        ] + array_map(fn($b) => $b ? 1 : 0, $flags);
    }
    $cursor = $page['cursor'];
    echo "  ...{$count} products audited\n";
    usleep(400_000);
} while ($page['hasNext']);

$fh = fopen(SHOPIFY_OUTPUT . '/feed_audit.csv', 'w');
fputcsv($fh, array_keys($rows[0] ?? ['x' => 1]));
foreach ($rows as $r) { fputcsv($fh, $r); }
fclose($fh);

echo "\n================ FEED / STRUCTURED-DATA AUDIT ================\n";
echo "Total products: {$count}\n";
echo "CSV: feed_audit.csv\n\n";
echo "Gap counts (these suppress AI-agent + Shopping pickup):\n";
$labels = [
    'no_barcode_any'    => 'GTIN/barcode missing on ALL variants',
    'no_barcode_some'   => 'GTIN/barcode missing on SOME variants',
    'no_sku_any'        => 'SKU missing on all variants',
    'no_category'       => 'No Google product category',
    'no_brand'          => 'No brand/vendor',
    'not_active'        => 'Status not ACTIVE',
    'not_available'     => 'No variant available for sale',
    'no_image'          => 'No image',
    'image_below_500'   => 'Smallest image edge < 500px',
    'no_storefront_url' => 'No published storefront URL',
];
foreach ($labels as $k => $label) {
    $n = $totals[$k] ?? 0;
    printf("  %-40s %4d (%.0f%%)\n", $label, $n, $count ? $n / $count * 100 : 0);
}
