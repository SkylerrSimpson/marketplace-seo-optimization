<?php

declare(strict_types=1);

/**
 * Phase 2 (step 1) — Export product descriptions (READ ONLY).
 *
 * Re-pulls every product from the Admin GraphQL API and this time KEEPS the
 * full body description (descriptionHtml) that the audit measured but discarded.
 * Writes a generator-input file the meta-description drafter will read from:
 *
 *   - phase2_input.json   (one object per product, full text — the real input)
 *   - phase2_preview.csv  (human-eyeball file: id, title, type, body length, body snippet)
 *
 * This script never writes to Shopify. It only reads.
 *
 * Usage:
 *   php export_descriptions.php
 *
 * Reuses the same .env keys as audit_products.php (SHOP_DOMAIN, ADMIN_API_TOKEN,
 * API_VERSION, APP_API_KEY, APP_API_SECRET).
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

// Same "thin body" threshold as the audit, so the weak-source flag matches.
const BODY_MIN  = 200;
const PAGE_SIZE = 100;

// ---------------------------------------------------------------------------
// SDK init (custom app: token auth, non-embedded) — read only
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
// Query — like the audit, but the body text is what we keep this time.
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
    }
  }
}
GQL;
$query = sprintf($query, PAGE_SIZE);

/**
 * Run one page, retrying on THROTTLED.
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
// Crawl + collect
// ---------------------------------------------------------------------------

$records = [];
$cursor  = null;
$count   = 0;
$thin    = 0;

echo "Exporting descriptions from {$shopDomain} (api {$apiVersion})...\n";

do {
    $page = fetchPage($client, $query, $cursor);

    foreach ($page['nodes'] as $p) {
        $gid       = (string) $p['id'];
        $numericId = (string) preg_replace('#.*/#', '', $gid);
        $html      = (string) ($p['descriptionHtml'] ?? '');
        // Strip tags, then decode HTML entities (&amp; -> &) so the AI reads clean text.
        $bodyText  = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $bodyText  = trim(preg_replace('/\s+/', ' ', $bodyText));
        $bodyLen   = mb_strlen($bodyText);
        $isThin    = $bodyLen < BODY_MIN;
        if ($isThin) {
            $thin++;
        }

        $records[] = [
            'numeric_id'       => $numericId,
            'gid'              => $gid,
            'handle'           => $p['handle'] ?? '',
            'title'            => $p['title'] ?? '',
            'status'           => $p['status'] ?? '',
            'product_type'     => trim((string) ($p['productType'] ?? '')),
            'category'         => $p['category']['fullName'] ?? '',
            'current_seo_desc' => trim((string) ($p['seo']['description'] ?? '')),
            'description_html' => $html,        // raw, for fidelity
            'body_text'        => $bodyText,    // stripped, what the AI reads
            'body_len'         => $bodyLen,
            'weak_source'      => $isThin,      // thin body => weak baseline, eyeball it
        ];
        $count++;
    }

    $cursor = $page['cursor'];
    echo "  ...{$count} products exported\n";
    usleep(300_000); // gentle pacing between pages
} while ($page['hasNext']);

// ---------------------------------------------------------------------------
// Write the generator input (JSON) + a human preview (CSV)
// ---------------------------------------------------------------------------

$jsonPath = SHOPIFY_INPUT . '/phase2_input.json';
file_put_contents(
    $jsonPath,
    json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

$csvPath = SHOPIFY_OUTPUT . '/phase2_preview.csv';
$fh = fopen($csvPath, 'w');
fputcsv($fh, ['numeric_id', 'title', 'product_type', 'category', 'body_len', 'weak_source', 'body_snippet']);
foreach ($records as $r) {
    fputcsv($fh, [
        $r['numeric_id'],
        $r['title'],
        $r['product_type'],
        $r['category'],
        $r['body_len'],
        $r['weak_source'] ? 'WEAK' : '',
        mb_substr($r['body_text'], 0, 180),
    ]);
}
fclose($fh);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n==================== EXPORT SUMMARY ====================\n";
echo "Products exported: {$count}\n";
echo "Generator input:   {$jsonPath}\n";
echo "Human preview:     {$csvPath}\n";
echo "Weak-source (thin body < " . BODY_MIN . " chars): {$thin} — review these before drafting.\n";
