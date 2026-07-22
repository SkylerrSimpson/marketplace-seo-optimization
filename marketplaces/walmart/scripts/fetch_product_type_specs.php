<?php

declare(strict_types=1);

/**
 * fetch_product_type_specs.php — cache, per product type, the exact set of
 * attributes Walmart's real Get Spec API says applies to it. Fixes the false-positive
 * problem in find_missing_aspects.py: the Seller Center bulk export's hidden
 * Requirement Level table is scoped to the whole BATCH (up to 10 mixed product
 * types), so an attribute required for one type in a batch (e.g. "Keychain Type")
 * gets marked Required for every row in that file, including totally unrelated
 * product types (e.g. a gold panning kit) — confirmed against real data.
 *
 * This calls POST /v3/items/spec (feedType=MP_MAINTENANCE, version pulled from the
 * xlsx export's own header cell — the SDK's generated method for this endpoint
 * doesn't work, same class of gap as includeDetails; using WalmartClient::rawPost).
 * One product type's response lists exactly the attributes applicable to it under
 * schema.properties.MPItem.items.properties.Visible.properties.{ProductType}.properties
 * -- that per-type applicability, intersected with the Required/Recommended LABELS
 * already extracted from the xlsx (which are correct, just wrongly scoped), gives an
 * accurate per-item missing-aspects check.
 *
 * Rate limit: 3 requests/minute (Walmart-documented). Batches up to 20 product types
 * per request. ~438 product types / 20 per call ≈ 22 calls ≈ ~8 minutes.
 *
 * Output: walmart/data/{country}/output/product_type_specs_{country}.json
 *   { "Kitchen Shears": {"color": "Color", "material": "Material", ...}, ... }
 *   (xml attribute name -> human-readable title, straight from the schema's own
 *   "title" field -- this is the canonical, complete label source for the unified
 *   aspects review sheet: covers every attribute applicable to a product type,
 *   not just whatever happened to be included in one mixed-batch xlsx export.)
 *
 * Usage: php walmart/scripts/fetch_product_type_specs.php --country=us
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/WalmartClient.php';

const SPEC_VERSION = '5.0.20260501-19_21_29'; // pulled from a real export's header cell; re-check if this script starts 4xx-ing

$opts    = getopt('', ['country:']);
$country = strtolower((string) ($opts['country'] ?? 'us'));

$listingsPath = walmart_dir($country, 'input') . '/listings.json';
$listings = json_decode((string) file_get_contents($listingsPath), true) ?: [];

$types = [];
foreach ($listings as $l) {
    $t = (string) ($l['productType'] ?? '');
    if ($t !== '') { $types[$t] = true; }
}
$types = array_keys($types);
echo "distinct product types: " . count($types) . "\n";

$client = new WalmartClient($country);
$batches = array_chunk($types, 20);

$result = [];
$done = 0;
foreach ($batches as $batch) {
    $done++;
    try {
        $resp = $client->rawPost('/v3/items/spec', [
            'feedType'     => 'MP_MAINTENANCE',
            'version'      => SPEC_VERSION,
            'productTypes' => $batch,
        ]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "batch {$done}/" . count($batches) . " FAILED: {$e->getMessage()}\n");
        sleep(21);
        continue;
    }

    $visible = $resp['schema']['properties']['MPItem']['items']['properties']['Visible']['properties'] ?? [];
    foreach ($batch as $t) {
        $attrs = [];
        foreach (($visible[$t]['properties'] ?? []) as $xmlName => $def) {
            $attrs[$xmlName] = (string) ($def['title'] ?? $xmlName);
        }
        $result[$t] = $attrs;
        if ($attrs === []) {
            fwrite(STDERR, "  WARNING: no attributes returned for product type '{$t}'\n");
        }
    }
    echo "batch {$done}/" . count($batches) . ": " . implode(', ', $batch) . "\n";

    if ($done < count($batches)) { sleep(21); } // 3/min => >=20s between calls
}

$out = walmart_dir($country, 'output') . "/product_type_specs_{$country}.json";
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("\ndone: %d product types cached -> %s\n", count($result), $out);
