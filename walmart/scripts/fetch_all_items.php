<?php

declare(strict_types=1);

/**
 * fetch_all_items.php — enumerate every item in a Walmart seller account. Uses the
 * raw REST call (WalmartClient::rawGet) rather than the SDK's generated getAllItems()
 * method, because that method doesn't expose includeDetails — a real, required query
 * param (confirmed in Walmart's actual docs) that adds condition/availability/shelf/
 * unpublishedReasons/variantGroupInfo to the response. The SDK was generated in 2023
 * and has gaps; this is one of them.
 *
 * Verified against live ASR account data: even with includeDetails=true, this
 * endpoint does NOT return full aspects/attributes (additionalAttributes was empty on
 * every one of 50 real active items tested) — see walmart/README.md "Aspects/
 * attributes have NO read-back endpoint". The one exception is the variant-defining
 * dimension itself, via variantGroupInfo.groupingAttributes on items with a
 * variantGroupId — that's what we capture as "aspects" here. SAME RULE AS EBAY: never
 * rewrite a variant-defining aspect value anywhere downstream in this pipeline.
 *
 * Paginates via nextCursor. limit=50 is the API's max per page.
 *
 * Output: walmart/data/{country}/input/listings.json
 *   [{ sku, wpid, upc, gtin, title, productType, shelf, condition, availability,
 *      publishedStatus, lifecycleStatus, variantGroupId,
 *      aspects: [{ name, value, isVariant: true }, ...] }, ...]
 *   (aspects here is ONLY the variant-grouping dimension, not a full attribute set —
 *   see README.)
 *
 * Usage: php walmart/scripts/fetch_all_items.php --country=us [--limit=N]
 *        [--lifecycleStatus=ACTIVE] [--publishedStatus=PUBLISHED]
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/WalmartClient.php';

$opts    = getopt('', ['country:', 'limit:', 'lifecycleStatus:', 'publishedStatus:']);
$country = strtolower((string) ($opts['country'] ?? 'us'));
$capLimit = isset($opts['limit']) ? (int) $opts['limit'] : null;

$client = new WalmartClient($country);

$rows = [];
$cursor = '*';
$page = 0;
do {
    $page++;
    $query = ['nextCursor' => $cursor, 'limit' => '50', 'includeDetails' => 'true'];
    if (!empty($opts['lifecycleStatus'])) { $query['lifecycleStatus'] = (string) $opts['lifecycleStatus']; }
    if (!empty($opts['publishedStatus'])) { $query['publishedStatus'] = (string) $opts['publishedStatus']; }

    try {
        $response = $client->rawGet('/v3/items', $query);
    } catch (\Throwable $e) {
        fwrite(STDERR, "page {$page} (cursor {$cursor}): EXCEPTION {$e->getMessage()}\n");
        break;
    }

    $pageItems = $response['ItemResponse'] ?? [];
    foreach ($pageItems as $item) {
        $aspects = [];
        foreach ($item['variantGroupInfo']['groupingAttributes'] ?? [] as $attr) {
            $aspects[] = [
                'name'      => (string) ($attr['name'] ?? ''),
                'value'     => (string) ($attr['value'] ?? ''),
                'isVariant' => true,
            ];
        }

        $rows[] = [
            'sku'              => (string) ($item['sku'] ?? ''),
            'wpid'             => (string) ($item['wpid'] ?? ''),
            'upc'              => (string) ($item['upc'] ?? ''),
            'gtin'             => (string) ($item['gtin'] ?? ''),
            'title'            => (string) ($item['productName'] ?? ''),
            'productType'      => (string) ($item['productType'] ?? ''),
            'shelf'            => (string) ($item['shelf'] ?? ''),
            'condition'        => (string) ($item['condition'] ?? ''),
            'availability'     => (string) ($item['availability'] ?? ''),
            'publishedStatus'  => (string) ($item['publishedStatus'] ?? ''),
            'lifecycleStatus'  => (string) ($item['lifecycleStatus'] ?? ''),
            'variantGroupId'   => (string) ($item['variantGroupId'] ?? ''),
            'aspects'          => $aspects,
        ];
    }

    $next = $response['nextCursor'] ?? null;
    $totalItems = $response['totalItems'] ?? null;
    fwrite(STDOUT, sprintf("  page %d: +%d items (total so far %d, totalItems=%s)\n", $page, count($pageItems), count($rows), (string) ($totalItems ?? '?')));

    // NOTE: Walmart's nextCursor token can stay IDENTICAL across pages while still
    // returning fresh items (verified: 0% SKU overlap between pages with the same
    // returned cursor) — do NOT treat an unchanged cursor as "done". Only stop on an
    // empty page, a missing cursor, or having collected the documented totalItems.
    $done = $next === null || $next === '' || count($pageItems) === 0
        || (is_numeric($totalItems) && count($rows) >= (int) $totalItems);
    $cursor = $next;

    if ($capLimit !== null && count($rows) >= $capLimit) {
        $rows = array_slice($rows, 0, $capLimit);
        break;
    }
} while (!$done);

$dir = walmart_dir($country, 'input');
$out = $dir . '/listings.json';
file_put_contents($out, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf("done: %d items -> %s\n", count($rows), $out);
