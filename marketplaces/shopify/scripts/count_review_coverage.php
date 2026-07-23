<?php

declare(strict_types=1);

/**
 * READ-ONLY: count how many products have reviews (reviews.rating_count > 0)
 * vs zero. The zero bucket is what GSC flags "Missing aggregateRating" — only
 * fixable by earning reviews, never by inventing them.
 *
 * Usage: php marketplaces/shopify/scripts/count_review_coverage.php
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$shopDomain  = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion  = $_ENV['API_VERSION']     ?? '2026-04';

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
query($cursor: String) {
  products(first: 100, after: $cursor, query: "status:active") {
    pageInfo { hasNextPage endCursor }
    nodes {
      title
      rc: metafield(namespace: "reviews", key: "rating_count") { value }
    }
  }
}
GQL;

$total = 0; $with = 0; $without = 0; $cursor = null;
do {
    $resp = $client->query(['query' => $query, 'variables' => ['cursor' => $cursor]]);
    $d = $resp->getDecodedBody()['data']['products'];
    foreach ($d['nodes'] as $p) {
        $total++;
        $n = (int)($p['rc']['value'] ?? 0);
        if ($n > 0) { $with++; } else { $without++; }
    }
    $cursor = $d['pageInfo']['hasNextPage'] ? $d['pageInfo']['endCursor'] : null;
} while ($cursor);

echo "Active products: $total\n";
echo "  with reviews (>=1):     $with\n";
echo "  zero reviews (flagged): $without\n";
