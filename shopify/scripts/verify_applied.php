<?php

declare(strict_types=1);

/**
 * Phase 3 verification — re-pull the ENTIRE live catalog and diff it, 1-to-1,
 * against the intended values in phase2_output.json.
 *
 * For every product we compare the three applied fields exactly (string-equal):
 *   - seo.description   vs  new_meta_description
 *   - productType       vs  product_type_final
 *   - featuredMedia.alt vs  new_image_alt   (only when we authored an alt)
 *
 * Read-only. Reports per-field match counts and lists every mismatch so we can
 * confirm all changes went through fully, or pinpoint exactly what didn't.
 *
 * Usage:
 *   php shopify/scripts/verify_applied.php           # summary + any mismatches
 *   php shopify/scripts/verify_applied.php --csv      # also write a per-product report
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$writeCsv = in_array('--csv', $argv, true);

$shopDomain  = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion  = $_ENV['API_VERSION']     ?? '2026-04';

if ($shopDomain === '' || $accessToken === '') {
    fwrite(STDERR, "Missing SHOP_DOMAIN or ADMIN_API_TOKEN in .env\n");
    exit(1);
}

$intendedPath = SHOPIFY_OUTPUT . '/phase2_output.json';
$intended = json_decode((string) file_get_contents($intendedPath), true);
if (!$intended) {
    fwrite(STDERR, "Could not read phase2_output.json\n");
    exit(1);
}

Context::initialize(
    apiKey: $_ENV['APP_API_KEY'] ?? 'custom-app',
    apiSecretKey: $_ENV['APP_API_SECRET'] ?? 'custom-app',
    scopes: ['read_products'],
    hostName: $shopDomain,
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $apiVersion,
    isEmbeddedApp: false,
);
$client = new Graphql($shopDomain, $accessToken);

// ---------------------------------------------------------------------------
// 1. Pull the entire live catalog (paginated) keyed by numeric id.
// ---------------------------------------------------------------------------

echo "Pulling live catalog from {$shopDomain} (api {$apiVersion})...\n";
$live = [];
$cursor = null;
do {
    $after = $cursor ? ", after: \"{$cursor}\"" : '';
    $q = <<<GQL
    query {
      products(first: 100{$after}) {
        pageInfo { hasNextPage endCursor }
        nodes { id productType seo { description } featuredMedia { alt } }
      }
    }
    GQL;
    $resp = $client->query(['query' => $q])->getDecodedBody();
    if (isset($resp['errors'])) {
        fwrite(STDERR, "GraphQL error: " . json_encode($resp['errors']) . "\n");
        exit(1);
    }
    $page = $resp['data']['products'];
    foreach ($page['nodes'] as $n) {
        $numeric = preg_replace('/\D/', '', (string) $n['id']);
        $live[$numeric] = [
            'productType' => trim((string) ($n['productType'] ?? '')),
            'seo'         => trim((string) ($n['seo']['description'] ?? '')),
            'alt'         => trim((string) ($n['featuredMedia']['alt'] ?? '')),
        ];
    }
    $cursor = $page['pageInfo']['hasNextPage'] ? $page['pageInfo']['endCursor'] : null;
} while ($cursor);

echo "Live products pulled: " . count($live) . "\n";
echo "Intended (phase2_output.json): " . count($intended) . "\n";
echo str_repeat('-', 70) . "\n";

// ---------------------------------------------------------------------------
// 2. Diff every intended product against live, field by field.
// ---------------------------------------------------------------------------

$descOk = 0; $descBad = 0;
$typeOk = 0; $typeBad = 0;
$altOk  = 0; $altBad  = 0; $altSkip = 0;
$missingLive = [];
$mismatches = [];
$csvRows = [];

foreach ($intended as $r) {
    $id        = (string) $r['numeric_id'];
    $wantDesc  = trim((string) ($r['new_meta_description'] ?? ''));
    $wantType  = trim((string) ($r['product_type_final'] ?? ''));
    $wantAlt   = trim((string) ($r['new_image_alt'] ?? ''));

    if (!isset($live[$id])) {
        $missingLive[] = $id;
        continue;
    }
    $gotDesc = $live[$id]['seo'];
    $gotType = $live[$id]['productType'];
    $gotAlt  = $live[$id]['alt'];

    // seo.description
    $dMatch = ($wantDesc === $gotDesc);
    $dMatch ? $descOk++ : $descBad++;
    if (!$dMatch) {
        $mismatches[] = "  [{$id}] seo.description\n      want: {$wantDesc}\n      got:  {$gotDesc}";
    }

    // productType
    $tMatch = ($wantType === $gotType);
    $tMatch ? $typeOk++ : $typeBad++;
    if (!$tMatch) {
        $mismatches[] = "  [{$id}] productType  want: '{$wantType}'  got: '{$gotType}'";
    }

    // image alt (only if we authored one)
    $aMatch = true;
    if ($wantAlt === '') {
        $altSkip++;
    } else {
        $aMatch = ($wantAlt === $gotAlt);
        $aMatch ? $altOk++ : $altBad++;
        if (!$aMatch) {
            $mismatches[] = "  [{$id}] image.alt\n      want: {$wantAlt}\n      got:  {$gotAlt}";
        }
    }

    $csvRows[] = [
        $id,
        $dMatch ? 'OK' : 'MISMATCH',
        $tMatch ? 'OK' : 'MISMATCH',
        ($wantAlt === '') ? 'n/a' : ($aMatch ? 'OK' : 'MISMATCH'),
    ];
}

// ---------------------------------------------------------------------------
// 3. Report.
// ---------------------------------------------------------------------------

$n = count($intended);
echo "FIELD-BY-FIELD MATCH vs phase2_output.json (exact string equality):\n";
printf("  seo.description : %d/%d match", $descOk, $n);
echo $descBad ? "   (** {$descBad} MISMATCH **)\n" : "   OK\n";
printf("  productType     : %d/%d match", $typeOk, $n);
echo $typeBad ? "   (** {$typeBad} MISMATCH **)\n" : "   OK\n";
printf("  image.alt       : %d/%d match (%d had no authored alt)", $altOk, $altOk + $altBad, $altSkip);
echo $altBad ? "   (** {$altBad} MISMATCH **)\n" : "   OK\n";

if (!empty($missingLive)) {
    echo "\n** " . count($missingLive) . " intended product(s) NOT FOUND in live catalog: "
        . implode(', ', $missingLive) . "\n";
}

if (!empty($mismatches)) {
    echo "\nMISMATCH DETAIL:\n" . implode("\n", $mismatches) . "\n";
}

echo str_repeat('-', 70) . "\n";
$allOk = ($descBad === 0 && $typeBad === 0 && $altBad === 0 && empty($missingLive));
echo $allOk
    ? "RESULT: PERFECT 1-to-1 — every live field matches the intended value.\n"
    : "RESULT: DISCREPANCIES FOUND — see detail above.\n";

if ($writeCsv) {
    $path = SHOPIFY_OUTPUT . '/phase3_verification.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, ['numeric_id', 'seo_description', 'product_type', 'image_alt']);
    foreach ($csvRows as $row) {
        fputcsv($fh, $row);
    }
    fclose($fh);
    echo "Per-product report: {$path}\n";
}

exit($allOk ? 0 : 1);
