<?php

declare(strict_types=1);

/**
 * Apply dedicated NAV tags to every product that lives in a parent menu item's
 * child collections, so each multi-child nav parent can point at a Smart
 * collection (rule: "Product tag is equal to nav-<parent>").
 *
 * WHY: Shopify Smart collections can't say "include everything from collections
 * X, Y, Z". A shared tag is the join key. We use a fresh `nav-*` tag (not your
 * existing generic tags) so the aggregate is exactly the union of the listed
 * child collections and nothing else.
 *
 * SAFETY:
 *   - DRY-RUN BY DEFAULT. Pass --apply to write. Needs write_products scope.
 *   - IDEMPOTENT: tagsAdd is a no-op for a tag a product already has, and we
 *     also skip products that already carry the tag, so re-running is safe.
 *   - Reads collection membership LIVE from the API (fresh, includes new
 *     collections) — does not depend on any cached CSV/JSON.
 *
 * USAGE:
 *   php marketplaces/shopify/scripts/apply_nav_tags.php                       # dry-run, all parents
 *   php marketplaces/shopify/scripts/apply_nav_tags.php --only=nav-rock-hounding   # dry-run, one parent
 *   php marketplaces/shopify/scripts/apply_nav_tags.php --apply               # LIVE write
 *   php marketplaces/shopify/scripts/apply_nav_tags.php --apply --only=nav-rock-hounding
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

const THROTTLE_FLOOR = 200;

/*
 * parent nav tag  =>  child collection handles to union together.
 * Only multi-child parents need an entry. Single-child parents
 * (Metal Detecting, Camping, Survival, Knives) just link straight to their one
 * collection in Navigation — no tag needed.
 */
const PARENTS = [
    'nav-gold-prospecting' => [
        'gold-panning-kits',
        'gold-pans',
        'sifters-or-classifiers',     // classifier screens
        'sluice-boxes',               // sluice boxes + matting
        'gold-panning-accessories',   // gold prospecting equipment
    ],
    'nav-paydirt-kits' => [
        'paydirt-kits',               // gold paydirt
        'gemstone-paydirt',
        'geodes',
    ],
    'nav-outdoor-gear' => [
        'camping-gear',
        'survival-gear',
        'outdoor-knives',
        'paralace',
        'metal-detecting-equipment',
    ],
    'nav-rock-hounding' => [
        'rockhounding-kits',
        'rockhounding-accessories',
    ],
];

$apply = in_array('--apply', $argv, true);
$only  = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) { $only = trim(substr($a, 7)); }
}

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
    scopes: ['write_products'],
    hostName: $shopDomain,
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $apiVersion,
    isEmbeddedApp: false,
);
$client = new Graphql($shopDomain, $accessToken);

const READ_COLLECTION = <<<'GQL'
query($handle: String!, $cursor: String) {
  collectionByHandle(handle: $handle) {
    id
    title
    products(first: 250, after: $cursor) {
      pageInfo { hasNextPage endCursor }
      nodes { id title tags }
    }
  }
}
GQL;

const TAGS_ADD = <<<'GQL'
mutation($id: ID!, $tags: [String!]!) {
  tagsAdd(id: $id, tags: $tags) {
    userErrors { field message }
  }
}
GQL;

function gql(Graphql $client, string $query, array $variables, bool $fatal = true): array
{
    $attempts = 0;
    while (true) {
        $attempts++;
        $resp = $client->query(['query' => $query, 'variables' => $variables]);
        $body = $resp->getDecodedBody();
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $e) {
                if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; }
            }
            if ($throttled && $attempts < 8) { sleep(2 * $attempts); continue; }
            if ($fatal) { fwrite(STDERR, "GraphQL error: " . json_encode($body['errors']) . "\n"); exit(1); }
            return [[], $body['errors']];
        }
        $cost = $body['extensions']['cost']['throttleStatus'] ?? null;
        if ($cost !== null && ($cost['currentlyAvailable'] ?? 9999) < THROTTLE_FLOOR) {
            $restore = max(1, (int) ($cost['restoreRate'] ?? 50));
            $need    = THROTTLE_FLOOR - (int) $cost['currentlyAvailable'];
            sleep((int) ceil($need / $restore));
        }
        return [$body['data'] ?? [], []];
    }
}

/** Pull every product (id/title/tags) in a collection, following pagination. */
function collection_products(Graphql $client, string $handle): array
{
    $out = [];
    $cursor = null;
    $found = false;
    do {
        [$data] = gql($client, READ_COLLECTION, ['handle' => $handle, 'cursor' => $cursor]);
        $col = $data['collectionByHandle'] ?? null;
        if ($col === null) {
            fwrite(STDERR, "  WARNING: collection handle not found: {$handle}\n");
            return [];
        }
        $found = true;
        foreach ($col['products']['nodes'] as $p) {
            $out[$p['id']] = ['title' => $p['title'], 'tags' => $p['tags']];
        }
        $page = $col['products']['pageInfo'];
        $cursor = $page['hasNextPage'] ? $page['endCursor'] : null;
    } while ($cursor !== null);
    return $out;
}

$plan = PARENTS;
if ($only !== null) {
    if (!isset(PARENTS[$only])) {
        fwrite(STDERR, "Unknown --only=$only. Valid: " . implode(', ', array_keys(PARENTS)) . "\n");
        exit(1);
    }
    $plan = [$only => PARENTS[$only]];
}

echo ($apply ? "LIVE APPLY" : "DRY-RUN") . " — nav aggregate tags\n";
echo str_repeat('=', 60) . "\n";
if (!$apply) { echo "(dry-run: nothing sent. Pass --apply to write — needs write_products)\n\n"; }

$grandWritten = 0; $grandSkipped = 0; $grandFailed = 0;

foreach ($plan as $tag => $handles) {
    echo "TAG  {$tag}\n";

    // union of products across this parent's child collections
    $union = [];
    foreach ($handles as $h) {
        $prods = collection_products($client, $h);
        echo "  - {$h}: " . count($prods) . " products\n";
        foreach ($prods as $gid => $info) { $union[$gid] = $info; }
    }
    echo "  => " . count($union) . " unique products in union\n";

    $written = 0; $skipped = 0; $failed = 0; $i = 0; $n = count($union);
    foreach ($union as $gid => $info) {
        $i++;
        $has = in_array($tag, $info['tags'], true);
        if ($has) {
            echo "    [{$i}/{$n}] already tagged — {$info['title']}\n"; $skipped++; continue;
        }
        echo "    [{$i}/{$n}] +{$tag} — {$info['title']}\n";
        if (!$apply) { $written++; continue; }

        [$data, $errs] = gql($client, TAGS_ADD, ['id' => $gid, 'tags' => [$tag]], false);
        if (!empty($errs)) { echo "        FAILED: " . json_encode($errs) . "\n"; $failed++; continue; }
        $ue = $data['tagsAdd']['userErrors'] ?? [];
        if (!empty($ue)) { echo "        userErrors: " . json_encode($ue) . "\n"; $failed++; continue; }
        $written++;
        usleep(250_000);
    }

    echo "  " . ($apply ? "tagged" : "would tag") . ": {$written}";
    echo " | already tagged: {$skipped}";
    if ($apply) { echo " | failed: {$failed}"; }
    echo "\n" . str_repeat('-', 60) . "\n";

    $grandWritten += $written; $grandSkipped += $skipped; $grandFailed += $failed;
}

echo str_repeat('=', 60) . "\n";
echo ($apply ? "APPLIED" : "DRY-RUN COMPLETE") . "\n";
echo "  " . ($apply ? "tagged" : "would tag") . ": {$grandWritten}\n";
echo "  already tagged: {$grandSkipped}\n";
if ($apply) { echo "  failed: {$grandFailed}\n"; }
