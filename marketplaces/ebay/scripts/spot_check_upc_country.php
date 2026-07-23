<?php

declare(strict_types=1);

/**
 * spot_check_upc_country.php — read-only live audit: confirm the "UPC" and
 * "Country/Region of Manufacture" custom ItemSpecifics are gone from a given
 * sample of item IDs. Uses Trading API GetItem (ReturnAll + IncludeItemSpecifics)
 * — the raw seller-side ItemSpecifics list — NOT the Browse API, because Browse
 * API's localizedAspects auto-derives a buyer-facing "UPC" display from the
 * item's `gtin` product-identifier field regardless of whether a redundant
 * custom ItemSpecific still exists, so it can't tell "removed" from "never
 * removed, just now correctly gtin-sourced" apart (confirmed by comparing
 * against Trading's real 19-entry ItemSpecifics list on a known item, which had
 * zero "UPC" entries yet Browse API showed one derived from gtin).
 *
 * Does NOT write items/*.json, enriched_summary.csv, or category_coverage.csv —
 * those are enrich_listings.php's rollups and are not touched here.
 *
 * Usage: php marketplaces/ebay/scripts/spot_check_upc_country.php --account=dows --file=path/to/ids.txt
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Trading\Types\GetItemRequestType;

$opts    = getopt('', ['account:', 'file:']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$file    = (string) ($opts['file'] ?? '');
if ($file === '' || !is_file($file)) { fwrite(STDERR, "need --file=path/to/ids.txt\n"); exit(1); }

$ids = array_values(array_filter(array_map('trim', file($file))));
$client = new EbayClient($account);
$outDir = ebay_dir($account, 'output');
$auditCsv = $outDir . '/upc_country_region_spot_check_2026-07-09.csv';

$fh = fopen($auditCsv, 'w');
fputcsv($fh, ['item_id', 'upc_present', 'country_region_present', 'status', 'note']);

$clean = 0; $flagged = 0; $errors = 0;
foreach ($ids as $i => $itemId) {
    $snap = fetchItem($client, $itemId);
    $aspects = $snap['aspects'];
    $upcPresent = array_key_exists('UPC', $aspects) ? 'YES' : 'no';
    $crPresent  = array_key_exists('Country/Region of Manufacture', $aspects) ? 'YES' : 'no';
    $status = $snap['status'];

    if ($status !== 'OK') {
        $errors++;
        fputcsv($fh, [$itemId, '?', '?', $status, 'could not fetch']);
        printf("%3d/%d  %-15s  %s\n", $i + 1, count($ids), $itemId, $status);
    } elseif ($upcPresent === 'YES' || $crPresent === 'YES') {
        $flagged++;
        fputcsv($fh, [$itemId, $upcPresent, $crPresent, $status, 'STILL PRESENT']);
        printf("%3d/%d  %-15s  FLAGGED  upc=%s  country_region=%s\n", $i + 1, count($ids), $itemId, $upcPresent, $crPresent);
    } else {
        $clean++;
        fputcsv($fh, [$itemId, $upcPresent, $crPresent, $status, '']);
    }
    usleep(200000); // ~5/sec, Trading API
}
fclose($fh);

printf("\n=== spot check (Trading GetItem, authoritative): %d ids ===\nclean: %d\nflagged (still present): %d\nfetch errors: %d\n-> %s\n",
    count($ids), $clean, $flagged, $errors, $auditCsv);

function fetchItem(EbayClient $client, string $itemId): array
{
    try {
        $req = new GetItemRequestType();
        $req->ItemID = $itemId;
        $req->DetailLevel = ['ReturnAll'];
        $req->IncludeItemSpecifics = true;
        $res = $client->trading()->getItem($req);
    } catch (\Throwable $e) {
        return ['aspects' => [], 'status' => 'HTTP_ERR: ' . $e->getMessage()];
    }
    $ack = (string) $res->Ack;
    if (!in_array($ack, ['Success', 'Warning'], true)) {
        $errs = [];
        foreach ($res->Errors ?? [] as $e) { $errs[] = $e->ShortMessage; }
        return ['aspects' => [], 'status' => $ack . ': ' . implode('; ', $errs)];
    }
    $aspects = [];
    if (isset($res->Item->ItemSpecifics)) {
        foreach ($res->Item->ItemSpecifics->NameValueList as $nv) {
            $vals = [];
            foreach ($nv->Value as $v) { $vals[] = $v; }
            $aspects[$nv->Name] = implode('; ', $vals);
        }
    }
    return ['aspects' => $aspects, 'status' => 'OK'];
}
