<?php

declare(strict_types=1);

/**
 * Collections metadata audit (READ ONLY) — the collection mirror of
 * audit_products.php (Phase 0 for collections).
 *
 * Paginates every collection via the Admin GraphQL API and writes:
 *   - collections_audit.csv  (one row per collection, with gap flags + priority)
 *   - a summary to STDOUT
 *
 * Never writes to Shopify. Reads only (read_products covers collections).
 *
 * Usage: php audit_collections.php
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

// SEO description sweet spot (characters). Collection meta descriptions follow
// the same SERP rules as products.
const SEO_MIN = 70;
const SEO_MAX = 160;
// Collection intro/body copy below this many chars is "thin" (most have none).
const BODY_MIN = 120;
const PAGE_SIZE = 100;

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
query Collections($cursor: String) {
  collections(first: %d, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      title
      handle
      updatedAt
      descriptionHtml
      productsCount { count }
      ruleSet { appliedDisjunctively rules { column } }
      seo { title description }
      image { url altText }
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
        $response = $client->query(['query' => $query, 'variables' => ['cursor' => $cursor]]);
        $body = $response->getDecodedBody();
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $err) {
                if (($err['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; }
            }
            if ($throttled && $attempts < 5) { sleep(2); continue; }
            fwrite(STDERR, "GraphQL error: " . json_encode($body['errors']) . "\n");
            exit(1);
        }
        $c = $body['data']['collections'];
        return ['nodes' => $c['nodes'], 'hasNext' => $c['pageInfo']['hasNextPage'], 'cursor' => $c['pageInfo']['endCursor']];
    }
}

function auditCollection(array $c): array
{
    $gid       = (string) $c['id'];
    $numericId = (string) preg_replace('#.*/#', '', $gid);
    $seoDesc   = trim((string) ($c['seo']['description'] ?? ''));
    $seoTitle  = trim((string) ($c['seo']['title'] ?? ''));
    $bodyText  = trim(strip_tags((string) ($c['descriptionHtml'] ?? '')));
    $bodyLen   = mb_strlen($bodyText);
    $seoLen    = mb_strlen($seoDesc);
    $prodCount = (int) ($c['productsCount']['count'] ?? 0);
    $isSmart   = isset($c['ruleSet']) && $c['ruleSet'] !== null;
    $imgUrl    = $c['image']['url'] ?? '';
    $imgAlt    = trim((string) ($c['image']['altText'] ?? ''));

    $flags = [
        'missing_seo_description' => $seoDesc === '',
        'seo_too_short'           => $seoDesc !== '' && $seoLen < SEO_MIN,
        'seo_too_long'            => $seoLen > SEO_MAX,
        'missing_body'            => $bodyLen === 0,
        'thin_body'               => $bodyLen > 0 && $bodyLen < BODY_MIN,
        'empty_collection'        => $prodCount === 0,
        'missing_image_alt'       => $imgUrl !== '' && $imgAlt === '',
    ];

    $weights = [
        'missing_seo_description' => 3,
        'missing_body'            => 3,
        'thin_body'               => 2,
        'seo_too_short'           => 1,
        'seo_too_long'            => 1,
        'empty_collection'        => 1,
        'missing_image_alt'       => 1,
    ];
    $priority = 0;
    foreach ($flags as $n => $set) { if ($set) { $priority += $weights[$n]; } }

    $row = [
        'numeric_id'    => $numericId,
        'gid'           => $gid,
        'handle'        => $c['handle'] ?? '',
        'title'         => $c['title'] ?? '',
        'type'          => $isSmart ? 'smart' : 'manual',
        'products_count'=> $prodCount,
        'seo_title'     => $seoTitle,
        'seo_desc'      => $seoDesc,
        'seo_desc_len'  => $seoLen,
        'body_len'      => $bodyLen,
        'image_alt'     => $imgAlt,
        'url'           => 'https://asroutdoor.com/collections/' . ($c['handle'] ?? ''),
    ];
    foreach ($flags as $n => $set) { $row[$n] = $set ? 1 : 0; }
    $row['priority'] = $priority;
    return ['row' => $row, 'issues' => $priority];
}

$rows = []; $cursor = null; $count = 0; $totals = [];
echo "Auditing collections from {$shopDomain} (api {$apiVersion})...\n";
do {
    $page = fetchPage($client, $query, $cursor);
    foreach ($page['nodes'] as $node) {
        $res = auditCollection($node);
        $rows[] = $res['row'];
        $count++;
        foreach ([
            'missing_seo_description','seo_too_short','seo_too_long',
            'missing_body','thin_body','empty_collection','missing_image_alt',
        ] as $col) {
            $totals[$col] = ($totals[$col] ?? 0) + (int) $res['row'][$col];
        }
    }
    $cursor = $page['cursor'];
    echo "  ...{$count} collections scanned\n";
    usleep(300_000);
} while ($page['hasNext']);

// Sort highest-priority first (same as product audit intent).
usort($rows, fn($a, $b) => $b['priority'] <=> $a['priority']);

$outDir = SHOPIFY_DATA . '/output';
if (!is_dir($outDir)) { @mkdir($outDir, 0775, true); }
$out = fopen($outDir . '/collections_audit.csv', 'w');
fputcsv($out, array_keys($rows[0]));
foreach ($rows as $r) { fputcsv($out, $r); }
fclose($out);

echo "\n========== COLLECTIONS AUDIT SUMMARY ==========\n";
echo "Collections scanned: {$count}\n";
echo "-----------------------------------------------\n";
foreach ($totals as $col => $n) {
    printf("  %-26s %3d\n", $col, $n);
}
echo "-----------------------------------------------\n";
echo "Wrote: {$outDir}/collections_audit.csv\n";
