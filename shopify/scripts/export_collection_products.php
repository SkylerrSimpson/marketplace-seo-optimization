<?php

declare(strict_types=1);

/**
 * Export each collection's membership + existing body copy (READ ONLY).
 *
 * For grounding collection meta descriptions in REAL data: pulls, per
 * collection, its existing descriptionHtml (merchant's own intro copy) and the
 * list of products it actually contains (title, productType, vendor, a short
 * body excerpt). Writes collection_products.json keyed by handle. Writes
 * nothing to Shopify.
 *
 * Usage: php export_collection_products.php
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$shopDomain   = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken  = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion   = $_ENV['API_VERSION']     ?? '2026-04';

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

// Collections page; products nested (first 60 per collection is plenty for grounding).
$query = <<<'GQL'
query Coll($cursor: String) {
  collections(first: 25, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      title
      handle
      descriptionHtml
      productsCount { count }
      seo { description }
      products(first: 60) {
        nodes {
          title
          productType
          vendor
          tags
          descriptionHtml
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
        $c = $body['data']['collections'];
        return ['nodes' => $c['nodes'], 'hasNext' => $c['pageInfo']['hasNextPage'], 'cursor' => $c['pageInfo']['endCursor']];
    }
}

function excerpt(string $html, int $len = 160): string
{
    $t = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    return mb_strlen($t) > $len ? mb_substr($t, 0, $len) . '…' : $t;
}

$result = []; $cursor = null; $count = 0;
echo "Exporting collection membership from {$shopDomain}...\n";
do {
    $page = fetchPage($client, $query, $cursor);
    foreach ($page['nodes'] as $c) {
        $handle = $c['handle'];
        $products = [];
        $typeTally = [];
        $vendorTally = [];
        foreach ($c['products']['nodes'] ?? [] as $p) {
            $pt = trim((string) ($p['productType'] ?? ''));
            $vd = trim((string) ($p['vendor'] ?? ''));
            if ($pt !== '') { $typeTally[$pt] = ($typeTally[$pt] ?? 0) + 1; }
            if ($vd !== '') { $vendorTally[$vd] = ($vendorTally[$vd] ?? 0) + 1; }
            $products[] = [
                'title'   => $p['title'] ?? '',
                'type'    => $pt,
                'vendor'  => $vd,
                'tags'    => $p['tags'] ?? [],
                'excerpt' => excerpt((string) ($p['descriptionHtml'] ?? ''), 140),
            ];
        }
        arsort($typeTally);
        arsort($vendorTally);
        $result[$handle] = [
            'title'           => $c['title'] ?? '',
            'handle'          => $handle,
            'products_count'  => $c['productsCount']['count'] ?? 0,
            'sampled'         => count($products),
            'existing_seo'    => trim((string) ($c['seo']['description'] ?? '')),
            'existing_body'   => excerpt((string) ($c['descriptionHtml'] ?? ''), 600),
            'product_types'   => $typeTally,
            'vendors'         => $vendorTally,
            'products'        => $products,
        ];
        $count++;
    }
    $cursor = $page['cursor'];
    echo "  ...{$count} collections\n";
    usleep(300_000);
} while ($page['hasNext']);

file_put_contents(SHOPIFY_DATA . '/output/collection_products.json',
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "\nWrote " . SHOPIFY_DATA . "/output/collection_products.json  ({$count} collections)\n";
