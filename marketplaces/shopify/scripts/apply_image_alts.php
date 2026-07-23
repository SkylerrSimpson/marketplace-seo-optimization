<?php

declare(strict_types=1);

/**
 * Apply image alt text from data/drafts/image_alts.json (hand-authored,
 * keyed by MediaImage gid -> alt string). Authored ASCII-only, <=125 chars.
 *
 * Writes alt via fileUpdate(files:[{id, alt}]) — nothing else on the file/product
 * is touched. Idempotent: reads each image's current alt and skips matches.
 * Guards: refuses to write a non-ASCII or >125-char alt.
 *
 * Prerequisites: data/drafts/image_alts.json must already exist.
 * DRY-RUN by default. Pass --apply to write.
 *
 * Usage:
 *   php marketplaces/shopify/scripts/apply_image_alts.php               # dry run (all)
 *   php marketplaces/shopify/scripts/apply_image_alts.php --limit=20    # first N
 *   php marketplaces/shopify/scripts/apply_image_alts.php --ids=GID,GID # canary subset
 *   php marketplaces/shopify/scripts/apply_image_alts.php --apply       # write
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

$alts = json_decode((string) file_get_contents(SHOPIFY_DATA . '/drafts/image_alts.json'), true);
if (!is_array($alts) || $alts === []) {
    fwrite(STDERR, "Could not read drafts/image_alts.json\n");
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

const READ  = 'query($ids:[ID!]!){ nodes(ids:$ids){ ... on MediaImage { id alt } } }';
const WRITE = 'mutation($files:[FileUpdateInput!]!){ fileUpdate(files:$files){ userErrors{field message} files{ id alt } } }';

$ids = array_keys($alts);
if ($onlyIds !== null) { $ids = array_values(array_intersect($ids, $onlyIds)); }
if ($limit !== null)   { $ids = array_slice($ids, 0, $limit); }

echo $apply ? "=== APPLYING IMAGE ALTS ===\n" : "=== DRY RUN (no writes; pass --apply) ===\n";
echo "images in scope: " . count($ids) . "\n";

// Guard every alt up front; never let a bad one into a batch.
$bad = 0;
foreach ($ids as $gid) {
    $alt = (string) $alts[$gid];
    if ($alt === '' || !mb_check_encoding($alt, 'ASCII') || strlen($alt) > 125) {
        echo "  [GUARD] {$gid}: refusing bad alt (len=" . strlen($alt) . ", ascii=" . (mb_check_encoding($alt, 'ASCII') ? 'y' : 'n') . ")\n";
        $bad++;
    }
}
if ($bad > 0) { fwrite(STDERR, "Aborting: {$bad} alt(s) failed the ASCII/length guard.\n"); exit(1); }

// 1) Read current alts (chunked) to make this idempotent.
$current = [];
foreach (array_chunk($ids, 50) as $chunk) {
    $data = gql($client, READ, ['ids' => $chunk]);
    if (isset($data['__error'])) {
        fwrite(STDERR, "  [READ ERR] " . json_encode($data['__error']) . "\n");
        exit(1);
    }
    foreach (($data['nodes'] ?? []) as $n) {
        if (is_array($n) && isset($n['id'])) { $current[$n['id']] = (string) ($n['alt'] ?? ''); }
    }
    usleep(200000);
}

// 2) Figure out what actually needs writing.
$toWrite = [];
$skipped = 0; $missing = 0;
foreach ($ids as $gid) {
    if (!array_key_exists($gid, $current)) { echo "  [MISS] {$gid} not found\n"; $missing++; continue; }
    $new = (string) $alts[$gid];
    if ($current[$gid] === $new) { $skipped++; continue; }
    $toWrite[] = ['id' => $gid, 'alt' => $new];
}

echo "needs write: " . count($toWrite) . ", already-correct: {$skipped}, missing: {$missing}\n";

if (!$apply) {
    foreach (array_slice($toWrite, 0, 1000) as $w) {
        echo "  WOULD SET {$w['id']} (" . strlen($w['alt']) . "c): {$w['alt']}\n";
    }
    echo "\nDRY RUN: would-write " . count($toWrite) . ", skipped {$skipped}, missing {$missing}\n";
    exit(0);
}

// 3) Apply in batches.
$written = 0; $errors = 0; $applied = [];
foreach (array_chunk($toWrite, 20) as $batch) {
    $res = gql($client, WRITE, ['files' => $batch]);
    $ue  = $res['fileUpdate']['userErrors'] ?? [];
    if (isset($res['__error']) || !empty($ue)) {
        fwrite(STDERR, "  [ERR] batch: " . json_encode(!empty($ue) ? $ue : $res['__error']) . "\n");
        $errors += count($batch);
        continue;
    }
    foreach ($res['fileUpdate']['files'] ?? [] as $f) {
        $written++;
        $applied[] = [$f['id'], $f['alt'] ?? ''];
        echo "  SET {$f['id']}: " . ($f['alt'] ?? '') . "\n";
    }
    usleep(400000);
}

echo "\n========================================\n";
echo "APPLIED: written {$written}, skipped(already correct) {$skipped}, missing {$missing}, errors {$errors}\n";

if ($applied) {
    $path = SHOPIFY_DATA . '/output/image_alts_applied.csv';
    $f = fopen($path, 'w');
    fputcsv($f, ['media_id', 'alt']);
    foreach ($applied as $row) { fputcsv($f, $row); }
    fclose($f);
    echo "Wrote {$path}\n";
}
