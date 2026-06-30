<?php

declare(strict_types=1);

/**
 * READ-ONLY diagnostic for the GSC "Missing aggregateRating" + breadcrumb
 * collection-choice questions. For a set of product handles, prints:
 *   - reviews.rating_count / reviews.rating metafields (drives aggregateRating)
 *   - the product's collections IN ORDER (handle + title) so we can see which
 *     one the breadcrumb snippet would pick (first child_csv match).
 * Never writes.
 *
 * Usage: php diag_breadcrumb_reviews.php
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$shopDomain   = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken  = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion   = $_ENV['API_VERSION']     ?? '2026-04';

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

$handles = [
    '13pc-gold-panning-backpack-kit-collapsible-bucket',
    '14pc-backpack-gold-panning-kit-12-mini-sluice-box',
    'asr-outdoor-10l-collapsible-bucket-and-stand-gold-prospecting-equipment-6pc',
    '17pc-gold-prospecting-equipment-essentials-field-kit-with-classifier-screens',
];

$query = <<<'GQL'
query($handle: String!) {
  productByHandle(handle: $handle) {
    title
    rc: metafield(namespace: "reviews", key: "rating_count") { value }
    rt: metafield(namespace: "reviews", key: "rating") { value }
    collections(first: 30) {
      nodes { handle title }
    }
  }
}
GQL;

foreach ($handles as $h) {
    $resp = $client->query(['query' => $query, 'variables' => ['handle' => $h]]);
    $body = $resp->getDecodedBody();
    $p = $body['data']['productByHandle'] ?? null;
    echo "=== $h\n";
    if (!$p) { echo "  NOT FOUND\n\n"; continue; }
    echo "  title: {$p['title']}\n";
    $rc = $p['rc']['value'] ?? '(none)';
    $rt = $p['rt']['value'] ?? '(none)';
    echo "  reviews.rating_count: $rc\n";
    echo "  reviews.rating: $rt\n";
    echo "  collections (in order):\n";
    foreach ($p['collections']['nodes'] as $c) {
        echo "    - {$c['handle']}  ({$c['title']})\n";
    }
    echo "\n";
}
