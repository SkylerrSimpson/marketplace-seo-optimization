<?php

declare(strict_types=1);

/**
 * Mirror the variant MPN up to the PRODUCT-level mm-google-shopping.mpn metafield
 * for SINGLE-VARIANT products only. This is what the Shopify CSV export's
 * "Google Shopping / MPN" (product-level legacy) column reads, so populating it
 * makes that column reflect the MPN we already set at the variant level.
 *
 * Single-variant ONLY: a product-level MPN is one value, so it's only correct
 * when the product has exactly one variant (one MPN). Multi-variant products are
 * left product-blank on purpose — their MPNs differ per SKU and the feed reads
 * them at the variant level anyway.
 *
 * Idempotent: skips products whose product-level mpn already matches. Reads the
 * single variant's mm-google-shopping.mpn as the source value.
 *
 * DRY-RUN by default. --apply to write. --limit=N for a canary.
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

const NS = 'mm-google-shopping';
const KEY = 'mpn';

$apply = in_array('--apply', $argv, true);
$limit = null;
foreach ($argv as $a) { if (preg_match('/^--limit=(\d+)$/', $a, $m)) { $limit = (int) $m[1]; } }

$shopDomain  = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion  = $_ENV['API_VERSION']     ?? '2026-04';
if ($shopDomain === '' || $accessToken === '') { fwrite(STDERR, "Missing SHOP_DOMAIN/ADMIN_API_TOKEN\n"); exit(1); }

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

function gql(Graphql $client, string $query, array $vars, bool $fatal = true): array
{
    $attempts = 0;
    while (true) {
        $attempts++;
        try {
            $body = $client->query(['query' => $query, 'variables' => $vars])->getDecodedBody();
        } catch (\Throwable $e) {
            if ($attempts < 8) { sleep(min(2 * $attempts, 10)); continue; }
            return ['__error' => [['message' => 'HTTP: ' . $e->getMessage()]]];
        }
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $e) { if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; } }
            if ($throttled && $attempts < 8) { sleep(2 * $attempts); continue; }
            if ($fatal) { fwrite(STDERR, "GraphQL: " . json_encode($body['errors']) . "\n"); exit(1); }
            return ['__error' => $body['errors']];
        }
        return $body['data'] ?? [];
    }
}

// Read each product: variant count, the single variant's mpn, and current product-level mpn.
const READ = <<<'GQL'
query($after:String){
  products(first:50, after:$after){
    pageInfo{ hasNextPage endCursor }
    nodes{
      id title
      pmpn:metafield(namespace:"mm-google-shopping",key:"mpn"){ value }
      variants(first:2){ nodes{ mpn:metafield(namespace:"mm-google-shopping",key:"mpn"){ value } } }
    }
  }
}
GQL;

$pending = [];   // [pid_gid, title, mpn]
$single = 0; $multi = 0; $same = 0; $noMpn = 0;
$after = null;
do {
    $d = gql($client, READ, ['after' => $after]);
    if (isset($d['__error'])) { fwrite(STDERR, "READ ERR: " . json_encode($d['__error']) . "\n"); exit(1); }
    foreach ($d['products']['nodes'] as $p) {
        $vnodes = $p['variants']['nodes'];
        if (count($vnodes) !== 1) { $multi++; continue; }   // single-variant only
        $single++;
        $mpn = trim((string) ($vnodes[0]['mpn']['value'] ?? ''));
        if ($mpn === '') { $noMpn++; continue; }
        $cur = trim((string) ($p['pmpn']['value'] ?? ''));
        if ($cur === $mpn) { $same++; continue; }
        $pending[] = ['ownerId' => $p['id'], 'namespace' => NS, 'key' => KEY, 'type' => 'single_line_text_field', 'value' => $mpn];
    }
    $after = $d['products']['pageInfo']['hasNextPage'] ? $d['products']['pageInfo']['endCursor'] : null;
    usleep(150000);
} while ($after);

if ($limit !== null) { $pending = array_slice($pending, 0, $limit); }

echo ($apply ? "=== LIVE APPLY ===" : "=== DRY RUN (pass --apply) ===") . "\n";
echo "single-variant products: {$single} | multi-variant (skipped): {$multi}\n";
echo "product-level MPN: write " . count($pending) . ", already-correct {$same}, single-variant w/o MPN {$noMpn}\n";

if (!$apply) { echo "\nDRY RUN: nothing written.\n"; exit(0); }

const SETMF = 'mutation($mf:[MetafieldsSetInput!]!){ metafieldsSet(metafields:$mf){ userErrors{ field message } } }';
$written = 0; $failed = 0;
foreach (array_chunk($pending, 25) as $batch) {
    $res = gql($client, SETMF, ['mf' => $batch], false);
    $ue  = $res['metafieldsSet']['userErrors'] ?? [];
    if (isset($res['__error']) || !empty($ue)) {
        fwrite(STDERR, "  [ERR] batch: " . json_encode(!empty($ue) ? $ue : $res['__error']) . "\n");
        $failed += count($batch); continue;
    }
    $written += count($batch);
    usleep(250000);
}
echo "\n========================================\n";
echo "APPLIED: product-level MPN written {$written}, failed {$failed}\n";
