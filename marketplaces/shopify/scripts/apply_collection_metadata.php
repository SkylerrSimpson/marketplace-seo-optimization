<?php

declare(strict_types=1);

/**
 * Apply collection SEO metadata (seo.title + seo.description) from
 * collections_phase2.json via collectionUpdate. Collection mirror of
 * apply_metadata.php.
 *
 * SAFETY:
 *   - DRY-RUN BY DEFAULT. Pass --apply to write. Needs write_products scope.
 *   - IDEMPOTENT: reads each collection's current seo first; skips fields that
 *     already match (SKIP), so it's safe to re-run.
 *   - Per-collection non-fatal: one userError won't abort the batch.
 *   - Validates each new description is <=160 chars and ASCII before sending.
 *
 * Prerequisites: data/output/collections_phase2.json (hand-authored; no
 * producer script in this repo) must already exist.
 *
 * USAGE:
 *   php marketplaces/shopify/scripts/apply_collection_metadata.php                # dry-run, all
 *   php marketplaces/shopify/scripts/apply_collection_metadata.php --limit 3      # dry-run, first 3
 *   php marketplaces/shopify/scripts/apply_collection_metadata.php --apply        # LIVE write
 *   php marketplaces/shopify/scripts/apply_collection_metadata.php --apply --limit 3   # live canary
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

const THROTTLE_FLOOR = 200;
const SRC = '/output/collections_phase2.json';

$apply = in_array('--apply', $argv, true);
$limit = null;
$idx = array_search('--limit', $argv, true);
if ($idx !== false && isset($argv[$idx + 1]) && is_numeric($argv[$idx + 1])) {
    $limit = (int) $argv[$idx + 1];
}
// --handles=geodes,paralace : restrict to specific collection handles (overrides --limit)
$onlyHandles = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--handles=')) {
        $onlyHandles = array_filter(array_map('trim', explode(',', substr($a, 10))));
    }
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

const READ_SEO = <<<'GQL'
query($id: ID!) { collection(id: $id) { seo { title description } } }
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

$rows = json_decode(file_get_contents(SHOPIFY_DATA . SRC), true);
if (!is_array($rows)) { fwrite(STDERR, "Could not read " . SRC . "\n"); exit(1); }
if (!empty($onlyHandles)) {
    $want = array_flip($onlyHandles);
    $rows = array_values(array_filter($rows, fn($r) => isset($want[$r['handle'] ?? ''])));
    $found = array_map(fn($r) => $r['handle'], $rows);
    $missing = array_diff($onlyHandles, $found);
    if (!empty($missing)) { fwrite(STDERR, "WARNING: handles not found: " . implode(', ', $missing) . "\n"); }
    if (empty($rows)) { fwrite(STDERR, "No matching collections for --handles — nothing to do.\n"); exit(1); }
} elseif ($limit !== null) {
    $rows = array_slice($rows, 0, $limit);
}

echo ($apply ? "LIVE APPLY" : "DRY-RUN") . " — collection seo.title + seo.description\n";
echo "Collections in plan: " . count($rows) . "\n";
echo str_repeat('-', 60) . "\n";
if (!$apply) { echo "(dry-run: nothing sent. Pass --apply to write — needs write_products)\n"; }

$written = 0; $skipped = 0; $failed = 0; $i = 0;
foreach ($rows as $r) {
    $i++;
    $gid  = $r['gid'] ?? '';
    $tit  = trim((string) ($r['new_seo_title'] ?? ''));
    $desc = trim((string) ($r['new_seo'] ?? ''));
    $name = $r['title'] ?? $gid;

    if ($gid === '' || $tit === '' || $desc === '') {
        echo "  [{$i}/" . count($rows) . "] {$name} — SKIP (missing gid/title/desc)\n"; $skipped++; continue;
    }
    // guardrails: ascii + length
    if (!mb_check_encoding($desc, 'ASCII') || !mb_check_encoding($tit, 'ASCII')) {
        echo "  [{$i}/" . count($rows) . "] {$name} — SKIP (non-ASCII)\n"; $skipped++; continue;
    }
    if (mb_strlen($desc) > 160 || mb_strlen($tit) > 70) {
        echo "  [{$i}/" . count($rows) . "] {$name} — SKIP (over length)\n"; $skipped++; continue;
    }

    // idempotency: read current
    [$rd] = gql($client, READ_SEO, ['id' => $gid], false);
    $curT = trim((string) ($rd['collection']['seo']['title'] ?? ''));
    $curD = trim((string) ($rd['collection']['seo']['description'] ?? ''));
    if ($curT === $tit && $curD === $desc) {
        echo "  [{$i}/" . count($rows) . "] {$name} — already correct, skip\n"; $skipped++; continue;
    }

    echo "  [{$i}/" . count($rows) . "] {$name}\n";
    echo "        title: " . ($curT === $tit ? "(unchanged)" : "{$tit}") . "\n";
    echo "        desc:  {$desc}\n";
    if (!$apply) { $written++; continue; }

    [$data, $errs] = gql($client, UPDATE, ['input' => [
        'id' => $gid,
        'seo' => ['title' => $tit, 'description' => $desc],
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
if ($apply) { echo "  failed: {$failed}\n"; }
