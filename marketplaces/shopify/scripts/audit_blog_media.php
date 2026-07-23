<?php

declare(strict_types=1);

/**
 * Blog ARTICLE video inventory (READ ONLY).
 *
 * Paginates every blog + article and scans the article body HTML for:
 *   - self-hosted Shopify MP4s (cdn.shopify.com/videos, *.mp4, <video>)
 *   - existing YouTube embeds (<iframe ...youtube...>, youtu.be, youtube.com)
 * so we know which blog posts still need their MP4s swapped to YouTube.
 *
 * Writes ONLY a local CSV (data/output/blog_video_inventory.csv). Never writes
 * to Shopify. Needs read_content scope.
 *
 * Usage: php marketplaces/shopify/scripts/audit_blog_media.php
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
    scopes: ['read_content'],
    hostName: $shopDomain,
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $apiVersion,
    isEmbeddedApp: false,
);
$client = new Graphql($shopDomain, $accessToken);

$query = <<<'GQL'
query Articles($cursor: String) {
  articles(first: 50, after: $cursor) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      title
      handle
      body
      blog { handle title }
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
        $p = $body['data']['articles'];
        return ['nodes' => $p['nodes'], 'hasNext' => $p['pageInfo']['hasNextPage'], 'cursor' => $p['pageInfo']['endCursor']];
    }
}

// pull distinct matches of a regex from html
function grabAll(string $re, string $html): array
{
    $out = [];
    if (preg_match_all($re, $html, $m)) {
        foreach ($m[0] as $hit) { $out[$hit] = true; }
    }
    return array_keys($out);
}

$rows = []; $cursor = null; $count = 0; $withMp4 = 0; $withYt = 0;
echo "Auditing blog article media from {$shopDomain}...\n";
do {
    $page = fetchPage($client, $query, $cursor);
    foreach ($page['nodes'] as $a) {
        $html = $a['body'] ?? '';
        $mp4s = array_merge(
            grabAll('#https?://[^"\'\s]+\.mp4[^"\'\s]*#i', $html),
            grabAll('#https?://cdn\.shopify\.com/videos/[^"\'\s]+#i', $html)
        );
        $mp4s = array_values(array_unique($mp4s));
        $hasVideoTag = (bool)preg_match('#<video[\s>]#i', $html);
        $yts = grabAll('#https?://(?:www\.)?(?:youtube\.com|youtu\.be)/[^"\'\s<]+#i', $html);
        $hasMp4 = !empty($mp4s) || $hasVideoTag;
        $hasYt  = !empty($yts);
        if ($hasMp4) { $withMp4++; }
        if ($hasYt)  { $withYt++; }
        $count++;
        if (!$hasMp4 && !$hasYt) { continue; } // only record posts that have video of some kind
        $blogH = $a['blog']['handle'] ?? '';
        $rows[] = [
            'blog'        => $blogH,
            'article'     => $a['handle'] ?? '',
            'title'       => $a['title'] ?? '',
            'has_mp4'     => $hasMp4 ? 1 : 0,
            'has_youtube' => $hasYt ? 1 : 0,
            'video_tag'   => $hasVideoTag ? 1 : 0,
            'mp4_urls'    => implode(' | ', $mp4s),
            'youtube_urls'=> implode(' | ', $yts),
            'url'         => "https://asroutdoor.com/blogs/{$blogH}/" . ($a['handle'] ?? ''),
        ];
    }
    $cursor = $page['cursor'];
    echo "  ...{$count} articles scanned\n";
    usleep(250_000);
} while ($page['hasNext']);

$outDir = SHOPIFY_DATA . '/output';
$path = $outDir . '/blog_video_inventory.csv';
$out = fopen($path, 'w');
if (!empty($rows)) {
    fputcsv($out, array_keys($rows[0]));
    // mp4-bearing posts first
    usort($rows, fn($a, $b) => [$b['has_mp4'], $b['has_youtube']] <=> [$a['has_mp4'], $a['has_youtube']]);
    foreach ($rows as $r) { fputcsv($out, $r); }
}
fclose($out);

echo "\n========== BLOG VIDEO INVENTORY ==========\n";
echo "Articles scanned:        {$count}\n";
echo "  with MP4 / <video>:    {$withMp4}\n";
echo "  with YouTube embed:    {$withYt}\n";
echo "------------------------------------------\n";
foreach ($rows as $r) {
    $flags = ($r['has_mp4'] ? 'MP4 ' : '') . ($r['has_youtube'] ? 'YT' : '');
    echo "  [{$flags}] {$r['url']}\n";
    if ($r['mp4_urls'] !== '') { echo "        mp4: {$r['mp4_urls']}\n"; }
}
echo "Wrote: {$path}\n";
