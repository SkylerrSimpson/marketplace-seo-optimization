<?php

declare(strict_types=1);

/**
 * Export each product's variant options/attributes (READ ONLY).
 *
 * For the SEO-description revision: parents (products with real variants) should
 * reference actual variant attribute values (sizes, colors, materials) instead of
 * generic "multiple sizes". Writes variants.json keyed by numeric_id:
 *   { is_parent, variant_count, options: [ {name, values:[...]} ] }
 * No automated consumer — output is a reference file for whoever authors the
 * description copy by hand.
 *
 * Usage: php marketplaces/shopify/scripts/export_variants.php
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
query Variants($cursor: String) {
  products(first: 100, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      hasOnlyDefaultVariant
      variantsCount { count }
      options { name optionValues { name } }
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

$result = [];
$cursor = null;
$count = 0;
$parents = 0;

echo "Exporting variant options from {$shopDomain}...\n";

do {
    $page = fetchPage($client, $query, $cursor);
    foreach ($page['nodes'] as $p) {
        $numericId = (string) preg_replace('#.*/#', '', (string) $p['id']);
        $isParent  = !($p['hasOnlyDefaultVariant'] ?? true);
        if ($isParent) { $parents++; }

        $options = [];
        foreach ($p['options'] ?? [] as $opt) {
            $values = array_map(static fn($v) => $v['name'], $opt['optionValues'] ?? []);
            // Skip Shopify's default "Title / Default Title" placeholder option.
            if (strtolower($opt['name']) === 'title' && $values === ['Default Title']) {
                continue;
            }
            $options[] = ['name' => $opt['name'], 'values' => $values];
        }

        $result[$numericId] = [
            'is_parent'     => $isParent,
            'variant_count' => $p['variantsCount']['count'] ?? 1,
            'options'       => $options,
        ];
        $count++;
    }
    $cursor = $page['cursor'];
    echo "  ...{$count}\n";
    usleep(300_000);
} while ($page['hasNext']);

file_put_contents(SHOPIFY_INPUT . '/variants.json',
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT));

echo "\nExported {$count} products to variants.json\n";
echo "Parents (real variants): {$parents}\n";
