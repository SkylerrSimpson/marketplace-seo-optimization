<?php

declare(strict_types=1);

/**
 * Product VIDEO inventory (READ ONLY).
 *
 * Paginates every product and inspects its media, recording which products
 * already have a self-hosted Shopify video (mediaContentType VIDEO) and/or an
 * external embed (EXTERNAL_VIDEO, e.g. YouTube). Captures the hosted mp4 URL +
 * preview image + duration, and the external host/embed URL, so we can decide
 * per product whether it needs a video added vs. just VideoObject markup.
 *
 * Writes ONLY a local CSV (data/output/product_video_inventory.csv). Never
 * writes to Shopify. read_products scope.
 *
 * Usage: php marketplaces/shopify/scripts/audit_product_media.php
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
query Products($cursor: String) {
  products(first: 60, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      title
      handle
      media(first: 25) {
        nodes {
          mediaContentType
          ... on Video {
            duration
            filename
            alt
            preview { image { url } }
            sources { url mimeType height }
          }
          ... on ExternalVideo {
            host
            originUrl
            embedUrl
            alt
          }
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

// pick the highest-res mp4 source
function bestSource(array $sources): string
{
    $best = ''; $bestH = -1;
    foreach ($sources as $s) {
        if (($s['mimeType'] ?? '') === 'video/mp4' && (int)($s['height'] ?? 0) >= $bestH) {
            $bestH = (int)($s['height'] ?? 0); $best = $s['url'] ?? '';
        }
    }
    return $best;
}

$rows = []; $cursor = null; $count = 0;
$nHosted = 0; $nExternal = 0; $nBoth = 0; $nNone = 0;
echo "Auditing product media from {$shopDomain}...\n";
do {
    $page = fetchPage($client, $query, $cursor);
    foreach ($page['nodes'] as $p) {
        $hostedUrl = ''; $hostedPrev = ''; $hostedDur = ''; $extHost = ''; $extUrl = ''; $alt = '';
        $hasHosted = false; $hasExternal = false;
        foreach ($p['media']['nodes'] ?? [] as $m) {
            $type = $m['mediaContentType'] ?? '';
            if ($type === 'VIDEO') {
                $hasHosted = true;
                if ($hostedUrl === '') {
                    $hostedUrl  = bestSource($m['sources'] ?? []);
                    $hostedPrev = $m['preview']['image']['url'] ?? '';
                    $hostedDur  = isset($m['duration']) ? (string)$m['duration'] : '';
                    $alt        = $m['alt'] ?? '';
                }
            } elseif ($type === 'EXTERNAL_VIDEO') {
                $hasExternal = true;
                if ($extUrl === '') {
                    $extHost = $m['host'] ?? '';
                    $extUrl  = $m['originUrl'] ?? ($m['embedUrl'] ?? '');
                    if ($alt === '') { $alt = $m['alt'] ?? ''; }
                }
            }
        }
        if ($hasHosted && $hasExternal) { $nBoth++; }
        elseif ($hasHosted) { $nHosted++; }
        elseif ($hasExternal) { $nExternal++; }
        else { $nNone++; }

        $rows[] = [
            'handle'           => $p['handle'] ?? '',
            'title'            => $p['title'] ?? '',
            'has_hosted_video' => $hasHosted ? 1 : 0,
            'has_external_video' => $hasExternal ? 1 : 0,
            'has_videoobject_markup' => '', // filled later by storefront check if desired
            'hosted_video_url' => $hostedUrl,
            'hosted_preview'   => $hostedPrev,
            'hosted_duration_ms' => $hostedDur,
            'external_host'    => $extHost,
            'external_url'     => $extUrl,
            'video_alt'        => $alt,
            'url'              => 'https://asroutdoor.com/products/' . ($p['handle'] ?? ''),
        ];
        $count++;
    }
    $cursor = $page['cursor'];
    echo "  ...{$count} products scanned\n";
    usleep(250_000);
} while ($page['hasNext']);

$outDir = SHOPIFY_DATA . '/output';
$path = $outDir . '/product_video_inventory.csv';
$out = fopen($path, 'w');
fputcsv($out, array_keys($rows[0]));
// videos first, then the rest
usort($rows, fn($a,$b) => ($b['has_hosted_video']+$b['has_external_video']) <=> ($a['has_hosted_video']+$a['has_external_video']));
foreach ($rows as $r) { fputcsv($out, $r); }
fclose($out);

echo "\n========== PRODUCT VIDEO INVENTORY ==========\n";
echo "Products scanned:        {$count}\n";
echo "  hosted video only:     {$nHosted}\n";
echo "  external (YouTube etc): {$nExternal}\n";
echo "  both hosted + external: {$nBoth}\n";
echo "  NO video:              {$nNone}\n";
echo "---------------------------------------------\n";
echo "Wrote: {$path}\n";
