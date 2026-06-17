<?php

declare(strict_types=1);

/**
 * Set Google Shopping size fields on every product (mm-google-shopping namespace):
 *   size_system = "US"        (Google enum: the country sizing scale)
 *   size_type   = "regular"   (Google enum: the cut)
 *
 * These were blank on all 199. Idempotent: skips anything already correct.
 * DRY-RUN by default; pass --apply to write. --ids= / --limit= for canary.
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
if ($shopDomain === '' || $accessToken === '') { fwrite(STDERR, "Missing SHOP_DOMAIN/ADMIN_API_TOKEN\n"); exit(1); }

const NS = 'mm-google-shopping';
const WANT = ['size_system' => 'US', 'size_type' => 'regular'];

// reuse the reviewed product list (has numeric_id for all 199)
$csvPath = SHOPIFY_DATA . '/output/google_shopping_review.csv';
$fh = fopen($csvPath, 'r'); $hdr = fgetcsv($fh); $col = array_flip($hdr);
$ids = [];
while (($r = fgetcsv($fh)) !== false) { $id = $r[$col['numeric_id']] ?? ''; if ($id !== '') { $ids[] = $id; } }
fclose($fh);

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
            foreach ($body['errors'] as $e) { if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; } }
            if ($throttled && $attempts < 6) { sleep(3); continue; }
            return ['__error' => $body['errors']];
        }
        return $body['data'] ?? [];
    }
}

const READ  = 'query($id:ID!){ product(id:$id){ id
    ss: metafield(namespace:"mm-google-shopping", key:"size_system"){ value }
    st: metafield(namespace:"mm-google-shopping", key:"size_type"){ value }
} }';
const WRITE = 'mutation($mf:[MetafieldsSetInput!]!){ metafieldsSet(metafields:$mf){ userErrors{field message} metafields{ key value } } }';

sort($ids, SORT_NUMERIC);
if ($onlyIds !== null) { $ids = array_values(array_intersect($ids, $onlyIds)); }
if ($limit !== null)   { $ids = array_slice($ids, 0, $limit); }

echo $apply ? "=== APPLYING size_system/size_type ===\n" : "=== DRY RUN (pass --apply) ===\n";
echo "products in scope: " . count($ids) . "\n";

$written = 0; $skipped = 0; $errors = 0; $fieldWrites = 0;
foreach ($ids as $numericId) {
    $gid  = "gid://shopify/Product/{$numericId}";
    $data = gql($client, READ, ['id' => $gid]);
    if (isset($data['__error'])) { fwrite(STDERR, "  [ERR] {$numericId}: " . json_encode($data['__error']) . "\n"); $errors++; continue; }
    $node = $data['product'] ?? null;
    if (!$node) { echo "  [MISS] {$numericId}\n"; $errors++; continue; }

    $cur = ['size_system' => (string) ($node['ss']['value'] ?? ''), 'size_type' => (string) ($node['st']['value'] ?? '')];
    $mf = [];
    foreach (WANT as $key => $val) {
        if ($cur[$key] === $val) { continue; }
        $mf[] = ['ownerId' => $gid, 'namespace' => NS, 'key' => $key, 'type' => 'single_line_text_field', 'value' => $val];
    }
    if ($mf === []) { $skipped++; continue; }

    if (!$apply) { echo "  WOULD SET {$numericId}: " . implode(',', array_column($mf, 'key')) . "\n"; $fieldWrites += count($mf); continue; }

    $res = gql($client, WRITE, ['mf' => $mf]);
    $ue  = $res['metafieldsSet']['userErrors'] ?? [];
    if (isset($res['__error']) || !empty($ue)) { fwrite(STDERR, "  [USERERR] {$numericId}: " . json_encode(!empty($ue) ? $ue : $res['__error']) . "\n"); $errors++; continue; }
    echo "  SET {$numericId}\n"; $written++; $fieldWrites += count($mf); usleep(300000);
}

echo "\n========================================\n";
echo ($apply ? "APPLIED" : "DRY RUN") . ": products-changed {$written}, field-writes {$fieldWrites}, skipped {$skipped}, errors {$errors}\n";
