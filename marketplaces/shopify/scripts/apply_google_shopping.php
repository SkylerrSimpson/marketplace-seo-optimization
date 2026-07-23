<?php

declare(strict_types=1);

/**
 * Apply Google Shopping attributes from data/output/google_shopping_review.csv
 * (hand-reviewed/approved artifact — no producer script in this repo). Writes
 * ONLY these legacy mm-google-shopping metafields, matching where Shopify
 * already stores them:
 *
 *   mm-google-shopping.gender                  = "unisex"  (all products)
 *   mm-google-shopping.age_group               = "adult"   (all products)
 *   mm-google-shopping.condition               = "new"     (all products)
 *   mm-google-shopping.google_product_category = <id>      (only where proposed != current)
 *
 * MPN is intentionally NOT written (SKU != MPN; GTIN already identifies items).
 *
 * Idempotent: reads current metafield values and skips anything already correct.
 * Values are forced lowercase to match Google's enum spec.
 *
 * DRY-RUN by default. Pass --apply to write.
 *
 * Usage:
 *   php marketplaces/shopify/scripts/apply_google_shopping.php                # dry run (all)
 *   php marketplaces/shopify/scripts/apply_google_shopping.php --ids=ID,ID    # canary subset
 *   php marketplaces/shopify/scripts/apply_google_shopping.php --limit=10     # first N
 *   php marketplaces/shopify/scripts/apply_google_shopping.php --apply        # write
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

const NS = 'mm-google-shopping';

// --- load reviewed proposals from the CSV ---
$csvPath = SHOPIFY_DATA . '/output/google_shopping_review.csv';
$fh = fopen($csvPath, 'r');
if ($fh === false) { fwrite(STDERR, "Could not read {$csvPath}\n"); exit(1); }
$header = fgetcsv($fh);
$col = array_flip($header);
$rows = [];
while (($r = fgetcsv($fh)) !== false) {
    $id = $r[$col['numeric_id']] ?? '';
    if ($id === '') { continue; }
    $rows[$id] = [
        'title'  => $r[$col['title']] ?? '',
        'gender' => strtolower(trim($r[$col['gender_proposed']] ?? '')),
        'age'    => strtolower(trim($r[$col['agegroup_proposed']] ?? '')),
        'cond'   => strtolower(trim($r[$col['condition_proposed']] ?? '')),
        'gpc'    => trim($r[$col['gpc_proposed']] ?? ''),
    ];
}
fclose($fh);
if ($rows === []) { fwrite(STDERR, "No rows in review CSV\n"); exit(1); }

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

const READ  = 'query($id:ID!){ product(id:$id){ id
    gender: metafield(namespace:"mm-google-shopping", key:"gender"){ value }
    age: metafield(namespace:"mm-google-shopping", key:"age_group"){ value }
    cond: metafield(namespace:"mm-google-shopping", key:"condition"){ value }
    gpc: metafield(namespace:"mm-google-shopping", key:"google_product_category"){ value }
} }';
const WRITE = 'mutation($mf:[MetafieldsSetInput!]!){ metafieldsSet(metafields:$mf){ userErrors{field message} metafields{ key value } } }';

$ids = array_keys($rows);
sort($ids, SORT_NUMERIC);
if ($onlyIds !== null) { $ids = array_values(array_intersect($ids, $onlyIds)); }
if ($limit !== null)   { $ids = array_slice($ids, 0, $limit); }

echo $apply ? "=== APPLYING GOOGLE SHOPPING ATTRS ===\n" : "=== DRY RUN (no writes; pass --apply) ===\n";
echo "products in scope: " . count($ids) . "\n";

// what to set: key => [api key, proposed value]. GPC only when non-empty.
$written = 0; $skipped = 0; $errors = 0; $fieldWrites = 0; $applied = [];
$byField = ['gender' => 0, 'age_group' => 0, 'condition' => 0, 'google_product_category' => 0];

foreach ($ids as $numericId) {
    $p   = $rows[$numericId];
    $gid = "gid://shopify/Product/{$numericId}";

    $data = gql($client, READ, ['id' => $gid]);
    if (isset($data['__error'])) { fwrite(STDERR, "  [ERR] {$numericId}: " . json_encode($data['__error']) . "\n"); $errors++; continue; }
    $node = $data['product'] ?? null;
    if (!$node) { echo "  [MISS] {$numericId} not found\n"; $errors++; continue; }

    $cur = [
        'gender'                  => (string) ($node['gender']['value'] ?? ''),
        'age_group'               => (string) ($node['age']['value'] ?? ''),
        'condition'               => (string) ($node['cond']['value'] ?? ''),
        'google_product_category' => (string) ($node['gpc']['value'] ?? ''),
    ];
    $want = [
        'gender'                  => $p['gender'],
        'age_group'               => $p['age'],
        'condition'               => $p['cond'],
        'google_product_category' => $p['gpc'],
    ];

    $mf = [];
    $changed = [];
    foreach ($want as $key => $val) {
        if ($val === '') { continue; }                         // never blank-out
        if ($key === 'google_product_category' && $cur[$key] === $val) { continue; }
        // gender/age/condition: compare case-insensitively, but write lowercase canonical
        if ($cur[$key] === $val) { continue; }
        $mf[] = [
            'ownerId'   => $gid,
            'namespace' => NS,
            'key'       => $key,
            'type'      => 'single_line_text_field',
            'value'     => $val,
        ];
        $changed[] = "{$key}:'{$cur[$key]}'->'{$val}'";
        $byField[$key]++;
    }

    if ($mf === []) { $skipped++; continue; }

    if (!$apply) {
        echo "  WOULD SET {$numericId}: " . implode('  ', $changed) . "\n";
        $fieldWrites += count($mf);
        continue;
    }

    $res = gql($client, WRITE, ['mf' => $mf]);
    $ue  = $res['metafieldsSet']['userErrors'] ?? [];
    if (isset($res['__error']) || !empty($ue)) {
        fwrite(STDERR, "  [USERERR] {$numericId}: " . json_encode(!empty($ue) ? $ue : $res['__error']) . "\n");
        $errors++;
        continue;
    }
    echo "  SET {$numericId}: " . implode('  ', $changed) . "\n";
    $written++;
    $fieldWrites += count($mf);
    $applied[] = [$numericId, $p['title'], implode(' | ', $changed)];
    usleep(300000);
}

echo "\n========================================\n";
echo ($apply ? "APPLIED" : "DRY RUN")
   . ": products-changed {$written}, field-writes {$fieldWrites}, skipped(already correct) {$skipped}, errors {$errors}\n";
echo "  by field: gender {$byField['gender']}, age_group {$byField['age_group']}, condition {$byField['condition']}, google_product_category {$byField['google_product_category']}\n";

if ($apply && $applied) {
    $path = SHOPIFY_DATA . '/output/google_shopping_applied.csv';
    $f = fopen($path, 'w');
    fputcsv($f, ['numeric_id', 'title', 'changes']);
    foreach ($applied as $row) { fputcsv($f, $row); }
    fclose($f);
    echo "Wrote {$path}\n";
}
