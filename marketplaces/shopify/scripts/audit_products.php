<?php

declare(strict_types=1);

/**
 * Phase 0 — Product metadata audit (READ ONLY).
 *
 * Paginates every product via the Admin GraphQL API and writes:
 *   - products_audit.csv  (one row per product, with gap flags + priority)
 *   - a summary to STDOUT
 *
 * This script never writes to Shopify. It only reads.
 *
 * Setup:
 *   composer install
 *   cp .env.example .env   # then fill in values
 *   php marketplaces/shopify/scripts/audit_products.php
 *
 * .env keys:
 *   SHOP_DOMAIN=asroutdoor.myshopify.com   (the *.myshopify.com admin domain)
 *   ADMIN_API_TOKEN=shpat_xxx              (custom app Admin API access token)
 *   API_VERSION=2026-04
 *   APP_API_KEY=...                        (custom app API key)
 *   APP_API_SECRET=...                     (custom app API secret key)
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------


$shopDomain   = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken  = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion   = $_ENV['API_VERSION']     ?? '2026-04';
$appApiKey    = $_ENV['APP_API_KEY']     ?? 'custom-app';
$appApiSecret = $_ENV['APP_API_SECRET']  ?? 'custom-app';

if ($shopDomain === '' || $accessToken === '') {
    fwrite(STDERR, "Missing SHOP_DOMAIN or ADMIN_API_TOKEN in .env\n");
    exit(1);
}

// SEO description sweet spot (characters).
const SEO_MIN = 70;
const SEO_MAX = 160;
// Below this many characters of body text, the description is "thin".
const BODY_MIN = 200;
// Products per page. 100 keeps GraphQL query cost comfortably under the cap.
const PAGE_SIZE = 100;

// ---------------------------------------------------------------------------
// SDK init (custom app: token auth, non-embedded)
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Query
// ---------------------------------------------------------------------------

$query = <<<'GQL'
query Products($cursor: String) {
  products(first: %d, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      title
      handle
      status
      vendor
      productType
      tags
      descriptionHtml
      onlineStoreUrl
      category { id fullName }
      seo { title description }
      featuredImage { url altText }
    }
  }
}
GQL;
$query = sprintf($query, PAGE_SIZE);

/**
 * Run one page, with a simple retry on throttling.
 *
 * @return array{nodes: array<int, array<string, mixed>>, hasNext: bool, cursor: ?string}
 */
function fetchPage(Graphql $client, string $query, ?string $cursor): array
{
    $attempts = 0;

    while (true) {
        $attempts++;
        $response = $client->query([
            'query'     => $query,
            'variables' => ['cursor' => $cursor],
        ]);

        $body = $response->getDecodedBody();

        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $err) {
                if (($err['extensions']['code'] ?? '') === 'THROTTLED') {
                    $throttled = true;
                }
            }
            if ($throttled && $attempts < 5) {
                sleep(2);
                continue;
            }
            fwrite(STDERR, "GraphQL error: " . json_encode($body['errors']) . "\n");
            exit(1);
        }

        $products = $body['data']['products'];

        return [
            'nodes'   => $products['nodes'],
            'hasNext' => $products['pageInfo']['hasNextPage'],
            'cursor'  => $products['pageInfo']['endCursor'],
        ];
    }
}

// ---------------------------------------------------------------------------
// Audit one product -> flags + row
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $p
 * @return array{row: array<string, mixed>, issues: int}
 */
function auditProduct(array $p): array
{
    $gid        = (string) $p['id'];
    $numericId  = (string) (preg_replace('#.*/#', '', $gid));
    $seoDesc    = trim((string) ($p['seo']['description'] ?? ''));
    $seoTitle   = trim((string) ($p['seo']['title'] ?? ''));
    $bodyText   = trim(strip_tags((string) ($p['descriptionHtml'] ?? '')));
    $bodyLen    = mb_strlen($bodyText);
    $seoLen     = mb_strlen($seoDesc);
    $category   = $p['category']['fullName'] ?? '';
    $productType = trim((string) ($p['productType'] ?? ''));
    $vendor     = trim((string) ($p['vendor'] ?? ''));
    $imgUrl     = $p['featuredImage']['url'] ?? '';
    $imgAlt     = trim((string) ($p['featuredImage']['altText'] ?? ''));
    $tags       = $p['tags'] ?? [];

    $flags = [
        'missing_seo_description' => $seoDesc === '',
        'seo_too_short'           => $seoDesc !== '' && $seoLen < SEO_MIN,
        'seo_too_long'            => $seoLen > SEO_MAX,
        'missing_category'        => $category === '',
        'missing_product_type'    => $productType === '',
        'thin_body'               => $bodyLen < BODY_MIN,
        'missing_vendor'          => $vendor === '',
        'missing_image'           => $imgUrl === '',
        'missing_image_alt'       => $imgUrl !== '' && $imgAlt === '',
    ];

    // Weight the two attributes that matter most for click potential + LLM
    // surfacing more heavily than cosmetic gaps.
    $weights = [
        'missing_seo_description' => 3,
        'missing_category'        => 3,
        'thin_body'               => 2,
        'missing_product_type'    => 2,
        'seo_too_short'           => 1,
        'seo_too_long'            => 1,
        'missing_vendor'          => 1,
        'missing_image'           => 2,
        'missing_image_alt'       => 1,
    ];

    $priority = 0;
    foreach ($flags as $name => $isSet) {
        if ($isSet) {
            $priority += $weights[$name];
        }
    }

    $row = [
        'numeric_id'   => $numericId,
        'gid'          => $gid,
        'handle'       => $p['handle'] ?? '',
        'title'        => $p['title'] ?? '',
        'status'       => $p['status'] ?? '',
        'vendor'       => $vendor,
        'product_type' => $productType,
        'category'     => $category,
        'seo_title'    => $seoTitle,
        'seo_desc'     => $seoDesc,
        'seo_desc_len' => $seoLen,
        'body_len'     => $bodyLen,
        'tags_count'   => count($tags),
        'image_alt'    => $imgAlt,
        'url'          => $p['onlineStoreUrl'] ?? '',
    ];

    // Append boolean flag columns + priority.
    foreach ($flags as $name => $isSet) {
        $row[$name] = $isSet ? 1 : 0;
    }
    $row['priority'] = $priority;

    return ['row' => $row, 'issues' => $priority];
}

// ---------------------------------------------------------------------------
// Crawl + write
// ---------------------------------------------------------------------------

$rows    = [];
$cursor  = null;
$count   = 0;
$totals  = [];

echo "Auditing products from {$shopDomain} (api {$apiVersion})...\n";

do {
    $page = fetchPage($client, $query, $cursor);

    foreach ($page['nodes'] as $node) {
        $result = auditProduct($node);
        $rows[] = $result['row'];
        $count++;

        foreach ($result['row'] as $col => $val) {
            // Tally the boolean flag columns for the summary.
            if (in_array($col, [
                'missing_seo_description', 'seo_too_short', 'seo_too_long',
                'missing_category', 'missing_product_type', 'thin_body',
                'missing_vendor', 'missing_image', 'missing_image_alt',
            ], true)) {
                $totals[$col] = ($totals[$col] ?? 0) + (int) $val;
            }
        }
    }

    $cursor = $page['cursor'];
    echo "  ...{$count} products scanned\n";
    usleep(300_000); // gentle pacing between pages
} while ($page['hasNext']);

// Highest-priority products first.
usort($rows, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

// Write CSV.
$csvPath = SHOPIFY_OUTPUT . '/products_audit.csv';
$fh = fopen($csvPath, 'w');
if ($fh === false) {
    fwrite(STDERR, "Could not open {$csvPath} for writing\n");
    exit(1);
}
fputcsv($fh, array_keys($rows[0] ?? []));
foreach ($rows as $row) {
    fputcsv($fh, $row);
}
fclose($fh);

// Summary.
echo "\n==================== AUDIT SUMMARY ====================\n";
echo "Total products: {$count}\n";
echo "CSV written to: {$csvPath}\n\n";
echo "Products with each gap:\n";
foreach ($totals as $flag => $n) {
    printf("  %-26s %5d (%.0f%%)\n", $flag, $n, $count ? ($n / $count * 100) : 0);
}
$needWork = count(array_filter($rows, static fn (array $r): bool => $r['priority'] > 0));
echo "\nProducts with at least one gap: {$needWork} of {$count}\n";
echo "Worklist is sorted by priority (highest first) in the CSV.\n";
