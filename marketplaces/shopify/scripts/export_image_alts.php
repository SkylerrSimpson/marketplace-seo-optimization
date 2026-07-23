<?php

declare(strict_types=1);

/**
 * Export each product's FEATURED image: media id + current alt text (READ ONLY).
 *
 * Feeds the image-alt before/after work: the media id is what apply_image_alts.php
 * (and author_descriptions_ai.php) need to write a new alt via productUpdateMedia.
 * Writes image_alts.json keyed by numeric_id: { media_id, old_alt, url, title }.
 *
 * Usage: php marketplaces/shopify/scripts/export_image_alts.php
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
query Imgs($cursor: String) {
  products(first: 100, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      title
      featuredMedia {
        id
        alt
        ... on MediaImage { image { url } }
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

$result = [];
$cursor = null;
$count = 0;
$missing = 0;

echo "Exporting featured-image alts from {$shopDomain}...\n";

do {
    $page = fetchPage($client, $query, $cursor);
    foreach ($page['nodes'] as $p) {
        $numericId = (string) preg_replace('#.*/#', '', (string) $p['id']);
        $media     = $p['featuredMedia'] ?? null;
        $oldAlt    = trim((string) ($media['alt'] ?? ''));
        if ($oldAlt === '') { $missing++; }
        $result[$numericId] = [
            'title'    => $p['title'] ?? '',
            'media_id' => $media['id'] ?? '',
            'old_alt'  => $oldAlt,
            'url'      => $media['image']['url'] ?? '',
        ];
        $count++;
    }
    $cursor = $page['cursor'];
    echo "  ...{$count} products\n";
    usleep(300_000);
} while ($page['hasNext']);

file_put_contents(SHOPIFY_INPUT . '/image_alts.json',
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT));

echo "\nExported {$count} featured-image alts to image_alts.json\n";
echo "Currently MISSING alt: {$missing}\n";
