<?php

declare(strict_types=1);

/**
 * Apply product SEO titles (seo.title) from shopify/data/drafts/seo_titles.json
 * (keyed by numeric product id). Authored to <=60 chars, ASCII-only, unique.
 *
 * Sets ONLY seo.title via productUpdate — seo.description is left untouched
 * (we pass just SEOInput.title, so Shopify keeps the existing description).
 *
 * DRY-RUN by default (prints intended diffs). Pass --apply to write.
 * Idempotent: reads current seo.title and skips anything already correct.
 * Guards: refuses to write a non-ASCII or >60-char title.
 *
 * Usage:
 *   php shopify/scripts/apply_seo_titles.php                 # dry run (all)
 *   php shopify/scripts/apply_seo_titles.php --ids=ID,ID     # canary subset
 *   php shopify/scripts/apply_seo_titles.php --limit=20      # first N
 *   php shopify/scripts/apply_seo_titles.php --apply         # write
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$apply = in_array('--apply', $argv, true);
$limit = null;
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

$titles = json_decode((string) file_get_contents(SHOPIFY_DATA . '/drafts/seo_titles.json'), true);
if (!is_array($titles) || $titles === []) {
    fwrite(STDERR, "Could not read drafts/seo_titles.json\n");
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

const READ  = 'query($id:ID!){ product(id:$id){ id title seo{ title description } } }';
const WRITE = 'mutation($id:ID!,$seo:SEOInput!){ productUpdate(product:{id:$id,seo:$seo}){ userErrors{field message} product{ id seo{ title description } } } }';

// Order by numeric id for deterministic runs.
$ids = array_keys($titles);
sort($ids, SORT_NUMERIC);
if ($onlyIds !== null) { $ids = array_values(array_intersect($ids, $onlyIds)); }
if ($limit !== null)   { $ids = array_slice($ids, 0, $limit); }

echo $apply ? "=== APPLYING SEO TITLES ===\n" : "=== DRY RUN (no writes; pass --apply) ===\n";
echo "products in scope: " . count($ids) . "\n";

$written = 0; $skipped = 0; $errors = 0; $wouldWrite = 0; $applied = [];

foreach ($ids as $numericId) {
    $newTitle = (string) $titles[$numericId];

    // Safety guards — never push a malformed title.
    if (!mb_check_encoding($newTitle, 'ASCII') || strlen($newTitle) > 60 || $newTitle === '') {
        echo "  [GUARD] {$numericId}: refusing bad title (len=" . strlen($newTitle) . ", ascii=" . (mb_check_encoding($newTitle, 'ASCII') ? 'y' : 'n') . ")\n";
        $errors++;
        continue;
    }

    $gid  = "gid://shopify/Product/{$numericId}";
    $data = gql($client, READ, ['id' => $gid]);
    if (isset($data['__error'])) {
        fwrite(STDERR, "  [ERR] {$numericId}: " . json_encode($data['__error']) . "\n");
        $errors++;
        continue;
    }
    $node = $data['product'] ?? null;
    if (!$node) { echo "  [MISS] {$numericId} not found\n"; $errors++; continue; }

    $curTitle = (string) ($node['seo']['title'] ?? '');
    $curDesc  = (string) ($node['seo']['description'] ?? '');
    if ($curTitle === $newTitle) { $skipped++; continue; }

    if (!$apply) {
        echo "  WOULD SET {$numericId} (" . strlen($newTitle) . "c): {$newTitle}\n";
        $wouldWrite++;
        continue;
    }

    // IMPORTANT: SEOInput replaces the whole object — must include the existing
    // description or Shopify nulls it. Carry curDesc along when present.
    $seoInput = ['title' => $newTitle];
    if ($curDesc !== '') { $seoInput['description'] = $curDesc; }
    $res  = gql($client, WRITE, ['id' => $gid, 'seo' => $seoInput]);
    $root = $res['productUpdate'] ?? [];
    $ue   = $root['userErrors'] ?? [];
    if (isset($res['__error']) || !empty($ue)) {
        fwrite(STDERR, "  [USERERR] {$numericId}: " . json_encode(!empty($ue) ? $ue : $res['__error']) . "\n");
        $errors++;
        continue;
    }
    echo "  SET {$numericId}: {$newTitle}\n";
    $written++;
    $applied[] = [$numericId, $node['title'] ?? '', $newTitle];
    usleep(300000); // ~3/sec pacing
}

echo "\n========================================\n";
echo ($apply ? "APPLIED" : "DRY RUN")
   . ": written {$written}, would-write {$wouldWrite}, skipped(already correct) {$skipped}, errors {$errors}\n";

if ($apply && $applied) {
    $path = SHOPIFY_DATA . '/output/seo_titles_applied.csv';
    $f = fopen($path, 'w');
    fputcsv($f, ['numeric_id', 'old_product_title', 'new_seo_title']);
    foreach ($applied as $row) { fputcsv($f, $row); }
    fclose($f);
    echo "Wrote {$path}\n";
}
