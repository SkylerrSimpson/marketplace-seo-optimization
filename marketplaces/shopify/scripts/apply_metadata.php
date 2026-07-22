<?php

declare(strict_types=1);

/**
 * Phase 3 — Apply approved metadata to Shopify (WRITE path).
 *
 * Reads phase2_output.json (the reviewed drafts) and, for each product, sets:
 *   - seo.description  (the new meta description)         via productUpdate
 *   - productType      (only the 23 that were blank)       via productUpdate
 *   - featured image alt (new_image_alt)                   via fileUpdate
 * productUpdate carries desc + productType in one call; the image alt is a
 * separate fileUpdate against the featured media id.
 *
 * Safety built in:
 *   - DRY-RUN BY DEFAULT. Logs intended changes; sends nothing. Pass --apply to write.
 *   - IDEMPOTENT. Reads each product's current seo.description + productType first and
 *     skips it if already correct, so re-runs are safe and cheap.
 *   - userErrors checked on every mutation.
 *   - THROTTLED handling: backs off on the cost-based leaky bucket and paces by the
 *     throttleStatus returned with each response.
 *   - --limit N to process only the first N (good for a small live canary).
 *   - --ids=ID,ID to process only specific products (e.g. hand-picked low-traffic
 *     items for testing); takes precedence over --limit.
 *
 * Requires a token with the write_products scope. The current read-only audit token
 * will 403 on --apply until re-authorized with write access.
 *
 * Usage:
 *   php apply_metadata.php            # dry-run, all 199 (default)
 *   php apply_metadata.php --limit 3  # dry-run, first 3
 *   php apply_metadata.php --apply    # LIVE write (needs write_products scope)
 *   php apply_metadata.php --apply --limit 3        # live canary on first 3
 *   php apply_metadata.php --ids=123,456            # dry-run only those 2
 *   php apply_metadata.php --apply --ids=123,456    # live write only those 2
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

// ---------------------------------------------------------------------------
// Args + config
// ---------------------------------------------------------------------------

$apply = in_array('--apply', $argv, true);
$limit = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--limit')) {
        $parts = explode('=', $a);
        $limit = isset($parts[1]) ? (int) $parts[1] : null;
    }
}
// also support "--limit 3" (space-separated)
$idx = array_search('--limit', $argv, true);
if ($idx !== false && isset($argv[$idx + 1]) && is_numeric($argv[$idx + 1])) {
    $limit = (int) $argv[$idx + 1];
}

// --ids=ID,ID,... — process ONLY these numeric product ids (a hand-picked canary,
// e.g. low-traffic items). Takes precedence over --limit. Supports = or space form.
$onlyIds = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--ids=')) {
        $onlyIds = array_filter(array_map('trim', explode(',', substr($a, 6))));
    }
}
$iidx = array_search('--ids', $argv, true);
if ($iidx !== false && isset($argv[$iidx + 1]) && str_starts_with($argv[$iidx + 1], '--') === false) {
    $onlyIds = array_filter(array_map('trim', explode(',', $argv[$iidx + 1])));
}
$onlyIds = array_map('strval', $onlyIds);


$shopDomain   = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken  = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion   = $_ENV['API_VERSION']     ?? '2026-04';
$appApiKey    = $_ENV['APP_API_KEY']     ?? 'custom-app';
$appApiSecret = $_ENV['APP_API_SECRET']  ?? 'custom-app';

if ($shopDomain === '' || $accessToken === '') {
    fwrite(STDERR, "Missing SHOP_DOMAIN or ADMIN_API_TOKEN in .env\n");
    exit(1);
}

$inputPath = SHOPIFY_OUTPUT . '/phase2_output.json';
if (!is_file($inputPath)) {
    fwrite(STDERR, "phase2_output.json not found — run assemble_output.php first.\n");
    exit(1);
}
$rows = json_decode((string) file_get_contents($inputPath), true);

// Throttle: pause when the GraphQL bucket gets low (cost-based leaky bucket).
const THROTTLE_FLOOR = 200; // points; sleep until refilled above this

Context::initialize(
    apiKey: $appApiKey,
    apiSecretKey: $appApiSecret,
    scopes: ['write_products'],
    hostName: $shopDomain,
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $apiVersion,
    isEmbeddedApp: false,
);

$client = new Graphql($shopDomain, $accessToken);

// ---------------------------------------------------------------------------
// GraphQL
// ---------------------------------------------------------------------------

const READ_QUERY = <<<'GQL'
query Current($id: ID!) {
  product(id: $id) {
    id
    productType
    seo { description }
    featuredMedia { id alt }
  }
}
GQL;

const UPDATE_MUTATION = <<<'GQL'
mutation ApplyMeta($product: ProductUpdateInput!) {
  productUpdate(product: $product) {
    product { id }
    userErrors { field message }
  }
}
GQL;

// Image alt lives on the media/file, set via fileUpdate (separate from productUpdate).
const ALT_MUTATION = <<<'GQL'
mutation ApplyAlt($files: [FileUpdateInput!]!) {
  fileUpdate(files: $files) {
    files { id alt }
    userErrors { field message }
  }
}
GQL;

/**
 * Send a GraphQL op with THROTTLED backoff + leaky-bucket pacing.
 *
 * THROTTLED is always retried (transient). Other top-level errors: if $fatal,
 * print + exit (used for the idempotency read — a broken read means we can't reason
 * about state). If not $fatal, return them to the caller so a single product/field
 * failure (e.g. a missing scope on one mutation) doesn't kill the whole batch.
 *
 * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<int,mixed>} [data, extensions, errors]
 */
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
                if (($e['extensions']['code'] ?? '') === 'THROTTLED') {
                    $throttled = true;
                }
            }
            if ($throttled && $attempts < 8) {
                sleep(2 * $attempts); // linear backoff
                continue;
            }
            if ($fatal) {
                fwrite(STDERR, "GraphQL error: " . json_encode($body['errors']) . "\n");
                exit(1);
            }
            return [[], [], $body['errors']]; // non-fatal: let caller handle
        }

        // Pace by remaining bucket so we don't trip THROTTLED on the next call.
        $cost = $body['extensions']['cost']['throttleStatus'] ?? null;
        if ($cost !== null && ($cost['currentlyAvailable'] ?? 9999) < THROTTLE_FLOOR) {
            $restore = max(1, (int) ($cost['restoreRate'] ?? 50));
            $need    = THROTTLE_FLOOR - (int) $cost['currentlyAvailable'];
            sleep((int) ceil($need / $restore));
        }

        return [$body['data'] ?? [], $body['extensions'] ?? [], []];
    }
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

$totalAll = count($rows);

// --ids: restrict to the hand-picked product ids (preserving file order).
if (!empty($onlyIds)) {
    $want = array_flip($onlyIds);
    $rows = array_values(array_filter($rows, fn($r) => isset($want[(string) $r['numeric_id']])));
    $found = array_map(fn($r) => (string) $r['numeric_id'], $rows);
    $missing = array_diff($onlyIds, $found);
    if (!empty($missing)) {
        fwrite(STDERR, "WARNING: --ids not found in phase2_output.json: " . implode(', ', $missing) . "\n");
    }
    if (empty($rows)) {
        fwrite(STDERR, "No matching products for --ids — nothing to do.\n");
        exit(1);
    }
    $limit = null; // --ids defines the set; ignore --limit
}

$scope = !empty($onlyIds)
    ? count($rows) . " hand-picked id(s)"
    : ($limit ?? count($rows)) . " of {$totalAll}";

echo ($apply ? "LIVE APPLY" : "DRY-RUN") . " — seo.description + productType + image.alt over " .
    "{$scope} products (api {$apiVersion})\n";
if (!$apply) {
    echo "(no writes will be sent; pass --apply to write — needs write_products scope)\n";
}
echo str_repeat('-', 70) . "\n";

$processed = 0; $wouldWrite = 0; $written = 0; $skipped = 0; $errors = 0;

foreach ($rows as $r) {
    if ($limit !== null && $processed >= $limit) {
        break;
    }
    $processed++;

    $gid          = (string) $r['gid'];
    $newDesc      = (string) $r['new_meta_description'];
    $typeChanged  = (int) ($r['product_type_changed'] ?? 0) === 1;
    $desiredType  = (string) $r['product_type_final'];
    $newAlt       = (string) ($r['new_image_alt'] ?? '');
    $mediaId      = (string) ($r['featured_media_id'] ?? '');

    // Idempotency: read current state, skip if everything is already correct.
    [$cur] = gql($client, READ_QUERY, ['id' => $gid]);
    $curDesc  = trim((string) ($cur['product']['seo']['description'] ?? ''));
    $curType  = trim((string) ($cur['product']['productType'] ?? ''));
    $curAlt   = trim((string) ($cur['product']['featuredMedia']['alt'] ?? ''));
    $curMedia = (string) ($cur['product']['featuredMedia']['id'] ?? '');

    $descNeedsWrite = $newDesc !== '' && $curDesc !== $newDesc;
    $typeNeedsWrite = $typeChanged && $curType !== $desiredType;
    // Prefer the live media id from the read; fall back to the exported one.
    $altMediaId     = $curMedia !== '' ? $curMedia : $mediaId;
    $altNeedsWrite  = $newAlt !== '' && $altMediaId !== '' && $curAlt !== $newAlt;

    if (!$descNeedsWrite && !$typeNeedsWrite && !$altNeedsWrite) {
        echo "  SKIP (already correct) {$r['numeric_id']}\n";
        $skipped++;
        continue;
    }

    // Build the minimal ProductUpdateInput (description + productType).
    $product = ['id' => $gid];
    if ($descNeedsWrite) { $product['seo'] = ['description' => $newDesc]; }
    if ($typeNeedsWrite) { $product['productType'] = $desiredType; }

    $changes = [];
    if ($descNeedsWrite) { $changes[] = 'seo.description'; }
    if ($typeNeedsWrite) { $changes[] = "productType -> {$desiredType}"; }
    if ($altNeedsWrite)  { $changes[] = 'image.alt'; }
    $wouldWrite++;

    if (!$apply) {
        printf("  WOULD UPDATE %-14s [%s]\n", $r['numeric_id'], implode(', ', $changes));
        continue;
    }

    // LIVE writes. productUpdate for desc/type, then fileUpdate for the image alt.
    // Both are non-fatal per-row: a failure on one product/field is logged and the
    // batch continues. desc/type and alt are tracked separately so a missing
    // write_files scope (alt) doesn't mask a successful description write.
    $done = [];
    $failed = [];

    if ($descNeedsWrite || $typeNeedsWrite) {
        [$data, , $errs] = gql($client, UPDATE_MUTATION, ['product' => $product], false);
        $ue = $data['productUpdate']['userErrors'] ?? [];
        if (!empty($errs) || !empty($ue)) {
            $failed[] = 'desc/type';
            fwrite(STDERR, "  productUpdate FAILED {$r['numeric_id']}: " . json_encode(!empty($errs) ? $errs : $ue) . "\n");
        } else {
            if ($descNeedsWrite) { $done[] = 'seo.description'; }
            if ($typeNeedsWrite) { $done[] = "productType -> {$desiredType}"; }
        }
    }

    if ($altNeedsWrite) {
        [$data, , $errs] = gql($client, ALT_MUTATION, ['files' => [['id' => $altMediaId, 'alt' => $newAlt]]], false);
        $ue = $data['fileUpdate']['userErrors'] ?? [];
        if (!empty($errs) || !empty($ue)) {
            $failed[] = 'image.alt';
            fwrite(STDERR, "  fileUpdate FAILED {$r['numeric_id']}: " . json_encode(!empty($errs) ? $errs : $ue) . "\n");
        } else {
            $done[] = 'image.alt';
        }
    }

    if (!empty($done) && empty($failed)) {
        $written++;
        printf("  UPDATED %-14s [%s]\n", $r['numeric_id'], implode(', ', $done));
    } elseif (!empty($done) && !empty($failed)) {
        $written++; $errors++;
        printf("  PARTIAL %-14s ok:[%s]  failed:[%s]\n", $r['numeric_id'], implode(', ', $done), implode(', ', $failed));
    } else {
        $errors++;
        printf("  FAILED  %-14s [%s]\n", $r['numeric_id'], implode(', ', $failed));
    }
}

echo str_repeat('-', 70) . "\n";
echo "Processed: {$processed}\n";
if ($apply) {
    echo "Written:   {$written}\n";
    echo "Errors:    {$errors}\n";
} else {
    echo "Would write: {$wouldWrite}\n";
}
echo "Skipped (already correct / no draft): {$skipped}\n";
echo $apply ? "Done.\n" : "Dry-run only — nothing was sent to Shopify.\n";
