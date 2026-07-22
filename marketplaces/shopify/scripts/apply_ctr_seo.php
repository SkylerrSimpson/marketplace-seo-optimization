<?php

declare(strict_types=1);

/**
 * Apply hand-reviewed CTR SEO title/meta changes (collections + product).
 *
 * Targets the striking-distance pages from the GSC analysis. Each change below
 * was reviewed/approved. Sets seo.title (+ seo.description where a new one was
 * written; otherwise keeps the existing description untouched).
 *
 * Blog posts are NOT handled here: the token lacks read_content/write_content,
 * so the 3 blog metas are applied manually in the Shopify admin.
 *
 * DRY-RUN by default (prints intended diffs). Pass --apply to write.
 * Idempotent: reads current seo, skips anything already correct.
 *
 * Usage:
 *   php shopify/scripts/apply_ctr_seo.php            # dry run
 *   php shopify/scripts/apply_ctr_seo.php --apply    # write
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);

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

// Reviewed changes. desc=null => keep existing description (only change title).
$CHANGES = [
    [
        'kind'   => 'collection',
        'handle' => 'gold-panning-kits',
        'title'  => 'Gold Panning Kits for Beginners & Pros | ASR Outdoor',
        'desc'   => 'Gold panning kits for beginners and experienced prospectors - complete sets with gold pans, classifiers and sluice boxes to start finding gold.',
    ],
    [
        'kind'   => 'collection',
        'handle' => 'sifters-or-classifiers',
        'title'  => 'Gold Classifier Screens & Sifting Sieves | ASR Outdoor',
        'desc'   => null, // keep existing description
    ],
    [
        'kind'   => 'product',
        'handle' => '5-gallong-bucket-gold-panning-classifier-screen-prospecting-equipment',
        'title'  => '5 Gallon Bucket Gold Classifier Screens & Sifters | ASR Outdoor',
        'desc'   => null, // keep existing description
    ],
];

function gql(Graphql $client, string $query, array $vars = []): array
{
    $attempts = 0;
    while (true) {
        $attempts++;
        $resp = $client->query(['query' => $query, 'variables' => $vars]);
        $body = $resp->getDecodedBody();
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $e) {
                if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; }
            }
            if ($throttled && $attempts < 6) { sleep(3); continue; }
            fwrite(STDERR, "GraphQL error: " . json_encode($body['errors']) . "\n");
            return ['__error' => $body['errors']];
        }
        return $body['data'] ?? [];
    }
}

$READ = [
    'collection' => 'query($h:String!){ collectionByHandle(handle:$h){ id title seo{ title description } } }',
    'product'    => 'query($h:String!){ productByHandle(handle:$h){ id title onlineStoreUrl seo{ title description } } }',
];
$WRITE = [
    'collection' => 'mutation($id:ID!,$seo:SEOInput!){ collectionUpdate(input:{id:$id,seo:$seo}){ userErrors{field message} collection{ id seo{ title description } } } }',
    'product'    => 'mutation($id:ID!,$seo:SEOInput!){ productUpdate(product:{id:$id,seo:$seo}){ userErrors{field message} product{ id seo{ title description } } } }',
];

echo $apply ? "=== APPLYING CHANGES ===\n" : "=== DRY RUN (no writes; pass --apply to write) ===\n";

$applied = []; $skipped = 0; $errors = 0;
foreach ($CHANGES as $c) {
    $kind = $c['kind'];
    $data = gql($client, $READ[$kind], ['h' => $c['handle']]);
    if (isset($data['__error'])) { $errors++; continue; }
    $node = $data[$kind === 'collection' ? 'collectionByHandle' : 'productByHandle'] ?? null;
    if (!$node) { echo "  [MISS] {$kind} {$c['handle']} not found\n"; $errors++; continue; }

    $curTitle = $node['seo']['title'] ?? '';
    $curDesc  = $node['seo']['description'] ?? '';
    $newTitle = $c['title'];
    $newDesc  = $c['desc'] ?? $curDesc; // keep existing when null

    echo "\n--- {$kind}: {$c['handle']} ---\n";
    echo "  TITLE old: {$curTitle}\n";
    echo "  TITLE new: {$newTitle}\n";
    if ($c['desc'] !== null) {
        echo "  DESC  old: {$curDesc}\n";
        echo "  DESC  new: {$newDesc}\n";
    } else {
        echo "  DESC  kept: {$curDesc}\n";
    }

    if ($curTitle === $newTitle && $curDesc === $newDesc) {
        echo "  => already correct, SKIP\n";
        $skipped++;
        continue;
    }

    if (!$apply) { echo "  => WOULD WRITE\n"; continue; }

    $res = gql($client, $WRITE[$kind], ['id' => $node['id'], 'seo' => ['title' => $newTitle, 'description' => $newDesc]]);
    $root = $res[$kind === 'collection' ? 'collectionUpdate' : 'productUpdate'] ?? [];
    $ue = $root['userErrors'] ?? [];
    if (!empty($ue)) { echo "  => USER ERROR: " . json_encode($ue) . "\n"; $errors++; continue; }
    echo "  => WRITTEN OK\n";
    $applied[] = [
        'kind' => $kind,
        'handle' => $c['handle'],
        'url' => $node['onlineStoreUrl'] ?? ('https://asroutdoor.com/' . ($kind === 'collection' ? 'collections/' : 'products/') . $c['handle']),
        'title' => $newTitle,
        'desc' => $newDesc,
    ];
}

echo "\n========================================\n";
echo ($apply ? "APPLIED" : "DRY RUN") . ": written " . count($applied) . ", skipped {$skipped}, errors {$errors}\n";
if ($apply && $applied) {
    $path = SHOPIFY_DATA . '/output/ctr_seo_applied.csv';
    $f = fopen($path, 'w');
    fputcsv($f, ['kind', 'handle', 'url', 'new_seo_title', 'new_seo_description']);
    foreach ($applied as $a) { fputcsv($f, [$a['kind'], $a['handle'], $a['url'], $a['title'], $a['desc']]); }
    fclose($f);
    echo "Wrote {$path}\n";
}
