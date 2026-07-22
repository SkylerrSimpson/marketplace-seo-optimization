<?php

declare(strict_types=1);

/**
 * RESTORE seo.description from the pre-wipe snapshot products_export_1.csv
 * (column "SEO Description"), keyed by Handle.
 *
 * Background: apply_seo_titles.php wrote productUpdate(seo:{title}) WITHOUT a
 * description, and Shopify's SEOInput replaces the whole object — which nulled
 * seo.description on every product. This restores it.
 *
 * Critical: we pass BOTH title AND description in one SEOInput so neither field
 * clobbers the other. The title written is the product's CURRENT live seo.title
 * (already correct from the title run), read fresh per product.
 *
 * Idempotent: skips products whose seo.description already matches the snapshot.
 * Guards: refuses to write a non-ASCII description.
 *
 * DRY-RUN by default. Pass --apply to write.
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$apply   = in_array('--apply', $argv, true);
$limit   = null;
$onlyIds = null;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = (int) $m[1]; }
    if (preg_match('/^--ids=(.+)$/', $a, $m))    { $onlyIds = array_filter(array_map('trim', explode(',', $m[1]))); }
}

$shopDomain  = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion  = $_ENV['API_VERSION']     ?? '2026-04';
if ($shopDomain === '' || $accessToken === '') {
    fwrite(STDERR, "Missing SHOP_DOMAIN or ADMIN_API_TOKEN in .env\n");
    exit(1);
}

// --- handle -> SEO Description from the pre-wipe export ---
$exportPath = SHOPIFY_DATA . '/output/products_export_1.csv';
$fh = fopen($exportPath, 'r');
$hdr = fgetcsv($fh);
$col = array_flip($hdr);
$descByHandle = [];
while (($r = fgetcsv($fh)) !== false) {
    $h = $r[$col['Handle']] ?? '';
    if ($h === '' || isset($descByHandle[$h])) { continue; }   // first row per handle
    $descByHandle[$h] = trim((string) ($r[$col['SEO Description']] ?? ''));
}
fclose($fh);

// --- handle -> numeric_id ---
$refPath = SHOPIFY_DATA . '/output/product_handle_reference.csv';
$idByHandle = [];
$rf = fopen($refPath, 'r');
$rhdr = fgetcsv($rf);
$rcol = array_flip($rhdr);
while (($r = fgetcsv($rf)) !== false) {
    $idByHandle[$r[$rcol['product_handle']]] = $r[$rcol['product_id']];
}
fclose($rf);
$idByHandle['5pc-metal-detecting-tools-kit-with-drawstring-bag-3-colors'] ??= '10340913053996';

Context::initialize(
    apiKey: $_ENV['APP_API_KEY'] ?? 'custom-app',
    apiSecretKey: $_ENV['APP_API_SECRET'] ?? 'custom-app',
    scopes: ['write_products'],
    hostName: $shopDomain,
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $apiVersion,
    isEmbeddedApp: false,
);
$client = new Graphql($shopDomain, $accessToken);

function gql(Graphql $client, string $query, array $vars = []): array
{
    $attempts = 0;
    while (true) {
        $attempts++;
        $body = $client->query(['query' => $query, 'variables' => $vars])->getDecodedBody();
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $e) {
                if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; }
            }
            if ($throttled && $attempts < 6) { sleep(3); continue; }
            return ['__error' => $body['errors']];
        }
        return $body['data'] ?? [];
    }
}

const READ  = 'query($id:ID!){ product(id:$id){ id seo{ title description } } }';
const WRITE = 'mutation($id:ID!,$seo:SEOInput!){ productUpdate(product:{id:$id,seo:$seo}){ userErrors{field message} product{ id seo{ title description } } } }';

// Build work list: products that have a snapshot description.
$work = [];
foreach ($descByHandle as $handle => $desc) {
    if ($desc === '') { continue; }                 // nothing to restore
    $id = $idByHandle[$handle] ?? null;
    if ($id === null) { echo "  [NO-ID] {$handle}\n"; continue; }
    $work[$id] = $desc;
}
$ids = array_keys($work);
sort($ids, SORT_NUMERIC);
if ($onlyIds !== null) { $ids = array_values(array_intersect($ids, $onlyIds)); }
if ($limit !== null)   { $ids = array_slice($ids, 0, $limit); }

echo $apply ? "=== RESTORING seo.description ===\n" : "=== DRY RUN (no writes; pass --apply) ===\n";
echo "products with a snapshot description: " . count($ids) . "\n";

$written = 0; $skipped = 0; $errors = 0; $would = 0;
foreach ($ids as $numericId) {
    $desc = $work[$numericId];

    if (!mb_check_encoding($desc, 'ASCII')) {
        echo "  [GUARD] {$numericId}: non-ASCII description, skipping\n";
        $errors++;
        continue;
    }

    $gid  = "gid://shopify/Product/{$numericId}";
    $data = gql($client, READ, ['id' => $gid]);
    if (isset($data['__error'])) { fwrite(STDERR, "  [ERR] {$numericId}: " . json_encode($data['__error']) . "\n"); $errors++; continue; }
    $node = $data['product'] ?? null;
    if (!$node) { echo "  [MISS] {$numericId} not found\n"; $errors++; continue; }

    $curTitle = (string) ($node['seo']['title'] ?? '');
    $curDesc  = (string) ($node['seo']['description'] ?? '');
    if ($curDesc === $desc) { $skipped++; continue; }   // already restored

    if (!$apply) {
        echo "  WOULD RESTORE {$numericId} (" . strlen($desc) . "c): " . substr($desc, 0, 70) . "...\n";
        $would++;
        continue;
    }

    // Pass BOTH title and description so neither is nulled.
    $seo = ['title' => $curTitle, 'description' => $desc];
    $res = gql($client, WRITE, ['id' => $gid, 'seo' => $seo]);
    $ue  = $res['productUpdate']['userErrors'] ?? [];
    if (isset($res['__error']) || !empty($ue)) {
        fwrite(STDERR, "  [USERERR] {$numericId}: " . json_encode(!empty($ue) ? $ue : $res['__error']) . "\n");
        $errors++;
        continue;
    }
    echo "  RESTORED {$numericId}\n";
    $written++;
    usleep(300000);
}

echo "\n========================================\n";
echo ($apply ? "RESTORED" : "DRY RUN")
   . ": written {$written}, would-write {$would}, skipped(already ok) {$skipped}, errors {$errors}\n";
