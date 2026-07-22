<?php

declare(strict_types=1);

/**
 * Set Google Shopping attributes at the VARIANT level (mm-google-shopping),
 * which is where this store's metafield definitions live and where the Google
 * & YouTube feed reads them (one feed item per variant):
 *
 *   condition   = "new"
 *   gender      = "unisex"
 *   age_group   = "adult"
 *   size_system = "US"
 *   size_type   = "regular"
 *
 * MPN is intentionally NOT written (done later, from real manufacturer numbers).
 * google_product_category is product/standard-category scoped, not here.
 *
 * Idempotent: reads current variant values and writes only the deltas; existing
 * capitalized values (e.g. "New") are normalized to the lowercase spec value.
 *
 * DRY-RUN by default; pass --apply to write. --limit=N caps products scanned.
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
$limit = null;
foreach ($argv as $a) { if (preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = (int) $m[1]; } }

$shopDomain  = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion  = $_ENV['API_VERSION']     ?? '2026-04';
if ($shopDomain === '' || $accessToken === '') { fwrite(STDERR, "Missing SHOP_DOMAIN/ADMIN_API_TOKEN\n"); exit(1); }

const NS   = 'mm-google-shopping';
const WANT = [
    'condition'   => 'new',
    'gender'      => 'unisex',
    'age_group'   => 'adult',
    'size_system' => 'US',
    'size_type'   => 'regular',
];

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
        try {
            $body = $client->query(['query' => $query, 'variables' => $vars])->getDecodedBody();
        } catch (\Throwable $ex) {
            // Transient HTTP error (502/503/timeout/non-JSON). Back off and retry.
            if ($attempts < 8) { sleep(min(2 * $attempts, 10)); continue; }
            return ['__error' => [['message' => 'HTTP: ' . $ex->getMessage()]]];
        }
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $e) { if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; } }
            if ($throttled && $attempts < 8) { sleep(3); continue; }
            return ['__error' => $body['errors']];
        }
        return $body['data'] ?? [];
    }
}

const READ = 'query($after:String){ products(first:50, after:$after){ pageInfo{hasNextPage endCursor}
  edges{ node{ id variants(first:100){ edges{ node{ id
    condition:metafield(namespace:"mm-google-shopping",key:"condition"){value}
    gender:metafield(namespace:"mm-google-shopping",key:"gender"){value}
    age_group:metafield(namespace:"mm-google-shopping",key:"age_group"){value}
    size_system:metafield(namespace:"mm-google-shopping",key:"size_system"){value}
    size_type:metafield(namespace:"mm-google-shopping",key:"size_type"){value}
  }}}}}}}';
const WRITE = 'mutation($mf:[MetafieldsSetInput!]!){ metafieldsSet(metafields:$mf){ userErrors{field message} } }';

// 1) Collect every metafield write needed across all variants.
$pending = [];   // flat list of MetafieldsSetInput
$variantsSeen = 0; $productsSeen = 0;
$after = null;
do {
    $data = gql($client, READ, ['after' => $after]);
    if (isset($data['__error'])) { fwrite(STDERR, "READ ERR: " . json_encode($data['__error']) . "\n"); exit(1); }
    $conn = $data['products'];
    foreach ($conn['edges'] as $pe) {
        $productsSeen++;
        foreach ($pe['node']['variants']['edges'] as $ve) {
            $variantsSeen++;
            $vid = $ve['node']['id'];
            foreach (WANT as $key => $val) {
                $cur = $ve['node'][$key]['value'] ?? null;
                if ($cur === $val) { continue; }            // already correct
                $pending[] = ['ownerId' => $vid, 'namespace' => NS, 'key' => $key, 'type' => 'single_line_text_field', 'value' => $val];
            }
        }
        if ($limit !== null && $productsSeen >= $limit) { break 2; }
    }
    $after = $conn['pageInfo']['hasNextPage'] ? $conn['pageInfo']['endCursor'] : null;
    usleep(200000);
} while ($after);

echo $apply ? "=== APPLYING variant Google Shopping attrs ===\n" : "=== DRY RUN (pass --apply) ===\n";
echo "products: {$productsSeen}, variants: {$variantsSeen}, field-writes needed: " . count($pending) . "\n";
$byField = [];
foreach ($pending as $p) { $byField[$p['key']] = ($byField[$p['key']] ?? 0) + 1; }
foreach ($byField as $k => $n) { echo "  {$k}: {$n}\n"; }

if (!$apply) { echo "\nDRY RUN: would write " . count($pending) . " variant metafields\n"; exit(0); }

// 2) Write in batches of 25 metafields per call.
$written = 0; $errors = 0;
foreach (array_chunk($pending, 25) as $batch) {
    $res = gql($client, WRITE, ['mf' => $batch]);
    $ue  = $res['metafieldsSet']['userErrors'] ?? [];
    if (isset($res['__error']) || !empty($ue)) {
        fwrite(STDERR, "  [ERR] batch: " . json_encode(!empty($ue) ? $ue : $res['__error']) . "\n");
        $errors += count($batch);
        continue;
    }
    $written += count($batch);
    usleep(300000);
}

echo "\n========================================\n";
echo "APPLIED: variant field-writes {$written}, errors {$errors}\n";
