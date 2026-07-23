<?php

declare(strict_types=1);

/**
 * Push seo.title + seo.description for the 4 NEW aggregate "parent" collections
 * (Gold Prospecting, Paydirt Kits, Outdoor Gear, Rockhounding). Resolves each
 * collection's GID by handle live, so it does NOT depend on collections_phase2.json.
 *
 * SAFETY:
 *   - DRY-RUN BY DEFAULT. Pass --apply to write. Needs write_products scope.
 *   - IDEMPOTENT: reads current seo first; skips fields that already match.
 *   - Validates title <=70, description <=160, ASCII before sending.
 *   - Warns (does not crash) if a handle isn't live yet.
 *
 * USAGE:
 *   php marketplaces/shopify/scripts/apply_nav_collection_seo.php            # dry-run
 *   php marketplaces/shopify/scripts/apply_nav_collection_seo.php --apply    # LIVE write
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

const THROTTLE_FLOOR = 200;

// handle => [title, meta]
const SEO = [
    'gold-prospecting' => [
        'Gold Prospecting Equipment, Kits, Pans & Sluices | ASR Outdoor',
        'Shop the full ASR Outdoor gold prospecting lineup: gold panning kits, gold pans, classifier screens, sluice boxes and matting, and recovery accessories.',
    ],
    'paydirt-kits-1' => [
        'Gold Paydirt, Gemstone Paydirt & Geodes | ASR Outdoor',
        'Guaranteed gold paydirt bags, rough gemstone paydirt, and break-your-own geode kits from ASR Outdoor. Pan, sift, and crack open real finds at home.',
    ],
    'outdoor-gear' => [
        'Outdoor Gear: Camping, Survival, Knives & Detecting | ASR Outdoor',
        'ASR Outdoor camping and survival gear, outdoor knives, metal detecting accessories, and paracord essentials for the trail, campsite, and treasure hunt.',
    ],
    'rockhounding' => [
        'Rockhounding Kits, Tools & Accessories | ASR Outdoor',
        'Rockhounding kits, geology hammers, chisels, loupes, and tool bags from ASR Outdoor for digging, cracking, and examining rocks, minerals, and geodes.',
    ],
];

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

const READ = <<<'GQL'
query($handle: String!) {
  collectionByHandle(handle: $handle) { id title seo { title description } }
}
GQL;

const UPDATE = <<<'GQL'
mutation($input: CollectionInput!) {
  collectionUpdate(input: $input) {
    collection { id seo { title description } }
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

echo ($apply ? "LIVE APPLY" : "DRY-RUN") . " — nav collection seo.title + seo.description\n";
echo str_repeat('=', 60) . "\n";
if (!$apply) { echo "(dry-run: nothing sent. Pass --apply to write)\n"; }

$written = 0; $skipped = 0; $failed = 0; $missing = 0;
foreach (SEO as $handle => [$title, $meta]) {
    if (!mb_check_encoding($title, 'ASCII') || !mb_check_encoding($meta, 'ASCII')) {
        echo "  {$handle} — SKIP (non-ASCII)\n"; $skipped++; continue;
    }
    if (mb_strlen($title) > 70 || mb_strlen($meta) > 160) {
        echo "  {$handle} — SKIP (over length: title " . mb_strlen($title) . ", meta " . mb_strlen($meta) . ")\n";
        $skipped++; continue;
    }

    [$rd] = gql($client, READ, ['handle' => $handle], false);
    $col = $rd['collectionByHandle'] ?? null;
    if ($col === null) {
        echo "  {$handle} — NOT FOUND (create the collection first)\n"; $missing++; continue;
    }
    $gid  = $col['id'];
    $curT = trim((string) ($col['seo']['title'] ?? ''));
    $curD = trim((string) ($col['seo']['description'] ?? ''));
    if ($curT === $title && $curD === $meta) {
        echo "  {$handle} — already correct, skip\n"; $skipped++; continue;
    }

    echo "  {$handle}\n";
    echo "        title: {$title}\n";
    echo "        meta:  {$meta}\n";
    if (!$apply) { $written++; continue; }

    [$data, $errs] = gql($client, UPDATE, ['input' => [
        'id'  => $gid,
        'seo' => ['title' => $title, 'description' => $meta],
    ]], false);
    if (!empty($errs)) { echo "        FAILED: " . json_encode($errs) . "\n"; $failed++; continue; }
    $ue = $data['collectionUpdate']['userErrors'] ?? [];
    if (!empty($ue)) { echo "        userErrors: " . json_encode($ue) . "\n"; $failed++; continue; }
    echo "        OK\n"; $written++;
    usleep(300_000);
}

echo str_repeat('=', 60) . "\n";
echo ($apply ? "APPLIED" : "DRY-RUN COMPLETE") . "\n";
echo "  " . ($apply ? "written" : "would write") . ": {$written}\n";
echo "  skipped (already correct / invalid): {$skipped}\n";
echo "  not found (create first): {$missing}\n";
if ($apply) { echo "  failed: {$failed}\n"; }
