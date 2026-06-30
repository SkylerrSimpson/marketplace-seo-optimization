<?php
declare(strict_types=1);
/**
 * READ-ONLY: list gold-panning-kits products whose title mentions "sluice",
 * showing product title vs SEO title (metafield global title_tag) so we can see
 * if the high-intent "gold panning kit with sluice box" query is matched.
 */
require __DIR__ . '/../../lib/bootstrap.php';
use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

Context::initialize(
    apiKey: $_ENV['APP_API_KEY'] ?? 'custom-app',
    apiSecretKey: $_ENV['APP_API_SECRET'] ?? 'custom-app',
    scopes: ['read_products'],
    hostName: $_ENV['SHOP_DOMAIN'] ?? '',
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $_ENV['API_VERSION'] ?? '2026-04',
    isEmbeddedApp: false,
);
$client = new Graphql($_ENV['SHOP_DOMAIN'] ?? '', $_ENV['ADMIN_API_TOKEN'] ?? '');

$q = <<<'GQL'
query($cursor: String) {
  collectionByHandle(handle: "gold-panning-kits") {
    products(first: 50, after: $cursor) {
      pageInfo { hasNextPage endCursor }
      nodes {
        title
        handle
        seo { title description }
      }
    }
  }
}
GQL;

$cursor = null;
do {
    $r = $client->query(['query' => $q, 'variables' => ['cursor' => $cursor]]);
    $d = $r->getDecodedBody()['data']['collectionByHandle']['products'];
    foreach ($d['nodes'] as $p) {
        if (stripos($p['title'], 'sluice') === false) continue;
        echo "TITLE:   {$p['title']}\n";
        echo "  SEO:   " . ($p['seo']['title'] ?: '(none -> falls back to product title)') . "\n";
        echo "  handle: {$p['handle']}\n\n";
    }
    $cursor = $d['pageInfo']['hasNextPage'] ? $d['pageInfo']['endCursor'] : null;
} while ($cursor);
