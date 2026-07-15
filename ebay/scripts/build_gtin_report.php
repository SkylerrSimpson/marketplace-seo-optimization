<?php

declare(strict_types=1);

/**
 * build_gtin_report.php — requested by Ethan 2026-07-09: a GTIN report for every
 * listing, to spot IDs with a missing UPC. Trading API has no single "GTIN" field;
 * it's ProductListingDetails.UPC / .EAN / .ISBN (whichever the seller supplied) —
 * gtin = the first non-blank of those, matching how eBay's own Browse API surfaces
 * it (confirmed against a known item: UPC=783152306410 on both sides). This is the
 * "essential" identifier field eBay's catalog-matching flow uses.
 *
 * Also checks for a DUPLICATE: a plain custom ItemSpecific literally named "UPC"
 * (the "optional" field — an ad hoc seller-added attribute, not part of most
 * categories' real Taxonomy schema) existing *alongside* the essential identifier
 * above. This is exactly the redundancy that was cleaned up for DOWS on 2026-07-09
 * (874 listings) — this field lets us see whether the same duplication exists on
 * an account that hasn't had that cleanup done yet.
 *
 * One row per listing (parent), plus one row per variation child when the listing
 * has variations — a variation's GTIN can differ per child SKU. The custom
 * ItemSpecific check is parent-level only (that's how the DOWS duplication existed
 * — UPC was never a per-variation vary-by dimension).
 *
 * Output: ebay/data/{acct}/output/gtin_report_{acct}.csv
 *   item_id, sku, varied_by, title, upc, ean, isbn, gtin, missing_gtin,
 *   custom_upc_specific, upc_duplicated
 *
 * Usage: php ebay/scripts/build_gtin_report.php --account=dows [--limit=N]
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Trading\Types\GetItemRequestType;

$opts    = getopt('', ['account:', 'limit:']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$limit   = isset($opts['limit']) ? (int) $opts['limit'] : null;
$dir     = ebay_dir($account, 'output');

$listings = json_decode((string) file_get_contents($dir . '/listings.json'), true) ?: [];
$ids = [];
foreach ($listings as $l) {
    $id = (string) ($l['item_id'] ?? '');
    if ($id !== '') { $ids[$id] = true; }
}
$ids = array_keys($ids);
if ($limit !== null) { $ids = array_slice($ids, 0, $limit); }

$client = new EbayClient($account);
$out = $dir . "/gtin_report_{$account}.csv";
$fh = fopen($out, 'w');
fputcsv($fh, [
    'item_id', 'sku', 'varied_by', 'title', 'upc', 'ean', 'isbn', 'gtin', 'missing_gtin',
    'custom_upc_specific', 'upc_duplicated',
]);

$done = 0; $errors = 0; $rows = 0; $missing = 0; $duplicated = 0;
foreach ($ids as $itemId) {
    $itemId = (string) $itemId;
    $done++;
    $snap = fetchItem($client, $itemId);
    if ($snap === null) {
        $errors++;
        if ($done % 50 === 0) { fwrite(STDOUT, "  {$done}/" . count($ids) . " (errors {$errors}, rows {$rows}, missing {$missing}, duplicated {$duplicated})\n"); }
        continue;
    }

    $gtin = $snap['upc'] ?: $snap['ean'] ?: $snap['isbn'];
    $isDup = $gtin !== '' && $snap['custom_upc'] !== '';
    fputcsv($fh, [
        $itemId, $snap['sku'], '', $snap['title'],
        $snap['upc'], $snap['ean'], $snap['isbn'], $gtin, $gtin === '' ? 'YES' : '',
        $snap['custom_upc'], $isDup ? 'YES' : '',
    ]);
    $rows++;
    if ($gtin === '') { $missing++; }
    if ($isDup) { $duplicated++; }

    foreach ($snap['children'] as $c) {
        $cGtin = $c['upc'] ?: $c['ean'] ?: $c['isbn'];
        fputcsv($fh, [
            $itemId, $c['sku'], $c['varied_by'], $snap['title'],
            $c['upc'], $c['ean'], $c['isbn'], $cGtin, $cGtin === '' ? 'YES' : '',
            '', '',
        ]);
        $rows++;
        if ($cGtin === '') { $missing++; }
    }

    if ($done % 50 === 0) { fwrite(STDOUT, "  {$done}/" . count($ids) . " (errors {$errors}, rows {$rows}, missing {$missing}, duplicated {$duplicated})\n"); }
    usleep(200000); // ~5/sec
}
fclose($fh);

printf("\ndone: %d listings, %d errors, %d rows, %d missing gtin, %d duplicated UPC -> %s\n", count($ids), $errors, $rows, $missing, $duplicated, $out);

/** @return array{sku:string,title:string,upc:string,ean:string,isbn:string,custom_upc:string,children:array<int,array{sku:string,varied_by:string,upc:string,ean:string,isbn:string}>}|null */
function fetchItem(EbayClient $client, string $itemId): ?array
{
    try {
        $req = new GetItemRequestType();
        $req->ItemID = $itemId;
        $req->DetailLevel = ['ReturnAll'];
        $req->IncludeItemSpecifics = true;
        $res = $client->trading()->getItem($req);
    } catch (\Throwable $e) {
        fwrite(STDERR, "  {$itemId}: EXCEPTION {$e->getMessage()}\n");
        return null;
    }
    $ack = (string) $res->Ack;
    if (!in_array($ack, ['Success', 'Warning'], true)) {
        $errs = [];
        foreach ($res->Errors ?? [] as $e) { $errs[] = $e->ShortMessage; }
        fwrite(STDERR, "  {$itemId}: {$ack} " . implode('; ', $errs) . "\n");
        return null;
    }
    $item = $res->Item;
    $pld = $item->ProductListingDetails ?? null;

    // the "optional" duplicate: a plain custom ItemSpecific literally named UPC
    $customUpc = '';
    if (isset($item->ItemSpecifics)) {
        foreach ($item->ItemSpecifics->NameValueList as $nv) {
            if (strcasecmp((string) $nv->Name, 'UPC') === 0) {
                $vals = [];
                foreach ($nv->Value as $v) { $vals[] = $v; }
                $customUpc = implode('; ', $vals);
                break;
            }
        }
    }

    $children = [];
    if (isset($item->Variations)) {
        // aspect names this listing varies by, for the varied_by column
        $variedNames = [];
        foreach ($item->Variations->Variation as $v) {
            foreach ($v->VariationSpecifics ?? [] as $block) {
                foreach ($block->NameValueList as $nv) { $variedNames[$nv->Name] = true; }
            }
        }
        $variedBy = implode('; ', array_keys($variedNames));

        foreach ($item->Variations->Variation as $v) {
            $vpld = $v->VariationProductListingDetails ?? null;
            $children[] = [
                'sku'       => (string) $v->SKU,
                'varied_by' => $variedBy,
                'upc'       => (string) ($vpld->UPC ?? ''),
                'ean'       => (string) ($vpld->EAN ?? ''),
                'isbn'      => (string) ($vpld->ISBN ?? ''),
            ];
        }
    }

    return [
        'sku'        => (string) ($item->SKU ?? ''),
        'title'      => (string) ($item->Title ?? ''),
        'upc'        => (string) ($pld->UPC ?? ''),
        'ean'        => (string) ($pld->EAN ?? ''),
        'isbn'       => (string) ($pld->ISBN ?? ''),
        'custom_upc' => $customUpc,
        'children'   => $children,
    ];
}
