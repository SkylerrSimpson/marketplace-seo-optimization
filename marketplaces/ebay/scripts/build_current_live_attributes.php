<?php

declare(strict_types=1);

/**
 * build_current_live_attributes.php — v3 of the "current live attributes" sheet
 * for review (eBay_{ACCT}_current-live-attributes_REVIEW_v3.csv). A fresh,
 * read-only pull of every listing's CURRENT live attributes, right now — every
 * schema-defined aspect for the listing's category (blank or filled), so the
 * real-vs-custom-attribute signal isn't lost (see review_v2 lesson), plus one
 * row per variation child x varied aspect.
 *
 * Uses Trading API GetItem (ReturnAll + IncludeItemSpecifics), NOT the Browse
 * API — Browse API's localizedAspects auto-derives a buyer-facing "UPC" aspect
 * from the item's `gtin` product-identifier field regardless of whether a
 * redundant custom ItemSpecific still exists, which would make every gtin-
 * bearing listing show a phantom "UPC" row here even after real cleanup
 * (spot-checking the UPC/Country-Region-of-Manufacture
 * write: Trading's raw ItemSpecifics list had zero UPC entries on a cleaned
 * listing that Browse API still showed one for). Trading GetItem is NOT
 * actually edge-blocked from this environment despite an older comment in
 * enrich_listings.php claiming so.
 *
 * Columns (same skeleton as v1/v2): item_id, sku, varied_by, name, category_id,
 * aspect, mode, cardinality, current_value, allowed_values, title
 *
 * Usage: php marketplaces/ebay/scripts/build_current_live_attributes.php --account=dows [--limit=N]
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Trading\Types\GetItemRequestType;

function nrm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }
function clip(string $s, int $n = 300): string { return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 3) . '...' : $s; }

$opts    = getopt('', ['account:', 'limit:']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$limit   = isset($opts['limit']) ? (int) $opts['limit'] : null;
$dir     = ebay_dir($account, 'output');
$schemaD = __DIR__ . '/../data/aspects';

$listings = json_decode((string) file_get_contents($dir . '/listings.json'), true) ?: [];
$ids = [];
foreach ($listings as $l) {
    $id = (string) ($l['item_id'] ?? '');
    if ($id !== '') { $ids[$id] = true; }
}
$ids = array_keys($ids);
if ($limit !== null) { $ids = array_slice($ids, 0, $limit); }

$schemaCache = [];
function schema(string $cat, string $dir, array &$cache): array
{
    if (isset($cache[$cat])) { return $cache[$cat]; }
    $map = []; $f = "$dir/$cat.json";
    if (is_file($f)) {
        $d = json_decode((string) file_get_contents($f), true);
        foreach ($d['aspects'] ?? [] as $a) {
            $vals = $a['values'] ?? [];
            $map[nrm($a['name'])] = [
                'name'    => $a['name'],
                'mode'    => $a['mode'] ?? '',
                'card'    => $a['cardinality'] ?? '',
                'allowed' => $vals ? implode(' | ', $vals) : '',
            ];
        }
    }
    return $cache[$dir . '|' . $cat] = $map;
}

$client = new EbayClient($account);
$out = $dir . "/current_live_attributes_v3_{$account}.csv";
$fh = fopen($out, 'w');
fputcsv($fh, ['item_id', 'sku', 'varied_by', 'name', 'category_id', 'aspect', 'mode', 'cardinality', 'current_value', 'allowed_values', 'title']);

$done = 0; $errors = 0; $rows = 0;
foreach ($ids as $itemId) {
    $itemId = (string) $itemId;   // numeric-string keys come back as ints
    $done++;
    $snap = fetchItem($client, $itemId);
    if ($snap === null) {
        $errors++;
        if ($done % 5 === 0) { fwrite(STDOUT, "  {$done}/" . count($ids) . " (errors {$errors}, rows {$rows})\n"); }
        continue;
    }

    $sku = $snap['sku']; $title = $snap['title']; $cat = $snap['category_id'];
    $sch = schema($cat, $schemaD, $schemaCache);
    $liveAspects = $snap['aspects'];              // Name(as-is) => value
    $liveNrm = [];
    foreach ($liveAspects as $k => $v) { $liveNrm[nrm($k)] = $k; }

    $variedNrm = [];
    foreach ($snap['children'] as $c) {
        foreach ($c['specifics'] as $k => $v) { $variedNrm[nrm($k)] = true; }
    }

    // A) every schema-defined aspect for this category (blank or filled), skip varied dims
    $emittedNrm = [];
    foreach ($sch as $an => $s) {
        if (isset($variedNrm[$an])) { continue; }
        $cv = $liveNrm[$an] ?? null;
        fputcsv($fh, [
            $itemId, $sku, '', $title, $cat, $s['name'], $s['mode'], $s['card'],
            $cv !== null ? $liveAspects[$cv] : '', clip($s['allowed']), $title,
        ]);
        $rows++;
        $emittedNrm[$an] = true;
    }

    // B) any live aspect NOT in the schema (custom/extra, not officially in the taxonomy)
    foreach ($liveAspects as $aname => $aval) {
        $an = nrm($aname);
        if (isset($variedNrm[$an]) || isset($emittedNrm[$an])) { continue; }
        fputcsv($fh, [$itemId, $sku, '', $title, $cat, $aname, '', '', $aval, '', $title]);
        $rows++;
    }

    // C) variation children: one row per child x varied aspect, live value
    foreach ($snap['children'] as $c) {
        foreach ($c['specifics'] as $ak => $av) {
            $an = nrm($ak); $s = $sch[$an] ?? null;
            fputcsv($fh, [
                $itemId, $c['sku'], $ak, $title, $cat, $ak,
                $s['mode'] ?? '', $s['card'] ?? '', $av, clip($s['allowed'] ?? ''), $title,
            ]);
            $rows++;
        }
    }

    if ($done % 5 === 0) { fwrite(STDOUT, "  {$done}/" . count($ids) . " (errors {$errors}, rows {$rows})\n"); }
    usleep(200000); // ~5/sec
}
fclose($fh);

printf("\ndone: %d listings, %d errors, %d rows -> %s\n", count($ids), $errors, $rows, $out);

/** @return array{sku:string,title:string,category_id:string,aspects:array<string,string>,children:array<int,array{sku:string,specifics:array<string,string>}>}|null */
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
    $aspects = [];
    if (isset($item->ItemSpecifics)) {
        foreach ($item->ItemSpecifics->NameValueList as $nv) {
            $vals = [];
            foreach ($nv->Value as $v) { $vals[] = $v; }
            $aspects[$nv->Name] = implode('; ', $vals);
        }
    }
    $children = [];
    if (isset($item->Variations)) {
        foreach ($item->Variations->Variation as $v) {
            $specifics = [];
            foreach ($v->VariationSpecifics ?? [] as $block) {
                foreach ($block->NameValueList as $nv) {
                    $vals = [];
                    foreach ($nv->Value as $x) { $vals[] = $x; }
                    $specifics[$nv->Name] = implode('; ', $vals);
                }
            }
            $children[] = ['sku' => (string) $v->SKU, 'specifics' => $specifics];
        }
    }
    return [
        'sku'         => (string) ($item->SKU ?? ''),
        'title'       => (string) ($item->Title ?? ''),
        'category_id' => (string) ($item->PrimaryCategory->CategoryID ?? ''),
        'aspects'     => $aspects,
        'children'    => $children,
    ];
}
