<?php

declare(strict_types=1);

/**
 * PHASE 1 (enrichment half) — attach Item Specifics + leaf category + title to
 * each enumerated listing, via the Browse API getItem (REST, reachable here;
 * Trading GetItem is edge-blocked). Reads the roster from export_listings.php.
 *
 *   GET https://api.ebay.com/buy/browse/v1/item/v1|{itemId}|0
 *       header X-EBAY-C-MARKETPLACE-ID: EBAY_US
 *   -> title, categoryId, categoryPath, localizedAspects[] (name/value pairs)
 *
 * Output (under ebay/data/{account}/output/):
 *   items/{itemId}.json   per-listing snapshot {item_id, title, category_id,
 *                         category_path, aspects:{name:value}, status}
 *   enriched_summary.csv  item_id, title, category_id, category_path, aspect_count, status
 *   category_coverage.csv category_id, category_path, listing_count   (feeds P4)
 *
 * Read-only against eBay. Idempotent: skips ItemIDs already fetched unless
 * --refresh. Throttle/backoff on 429. --limit / --ids for canary runs.
 *
 * Usage:
 *   php ebay/scripts/enrich_listings.php --account=dows --limit=5     # canary
 *   php ebay/scripts/enrich_listings.php --account=dows               # full
 *   php ebay/scripts/enrich_listings.php --account=ige --refresh
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

const BROWSE_BASE = 'https://api.ebay.com/buy/browse/v1/item/';
const MARKETPLACE = 'EBAY_US';

$opts = getopt('', ['account:', 'limit:', 'ids:', 'refresh', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php enrich_listings.php --account=dows|ige [--limit=N] [--ids=ID,ID] [--refresh]\n");
    exit(0);
}
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$limit   = isset($opts['limit']) ? (int) $opts['limit'] : null;
$refresh = isset($opts['refresh']);
$onlyIds = isset($opts['ids']) ? array_filter(array_map('trim', explode(',', (string) $opts['ids']))) : null;


$client = new EbayClient($account);
$outDir = ebay_dir($account, 'output');
$itemDir = $outDir . '/items';
if (!is_dir($itemDir)) { mkdir($itemDir, 0775, true); }

$rosterPath = $outDir . '/listings.json';
if (!is_file($rosterPath)) {
    fwrite(STDERR, "No roster at {$rosterPath}. Run export_listings.php --account={$account} first.\n");
    exit(1);
}
$roster = json_decode((string) file_get_contents($rosterPath), true) ?: [];

// Distinct ItemIDs (variations share one ItemID — enrich once per listing).
$ids = [];
foreach ($roster as $l) {
    $id = (string) ($l['item_id'] ?? '');
    if ($id !== '') { $ids[$id] = true; }
}
$ids = array_keys($ids);
if ($onlyIds !== null) { $ids = array_values(array_intersect($ids, $onlyIds)); }
if ($limit !== null)   { $ids = array_slice($ids, 0, $limit); }

echo "=== Phase 1 enrich: {$account} (" . $client->env() . ") ===\n";
echo "listings to enrich: " . count($ids) . ($refresh ? " (refresh)" : " (resumable)") . "\n";

$done = 0; $fetched = 0; $skipped = 0; $errors = 0;
$summary = [];     // item_id => [title, cat_id, cat_path, aspect_count, status]
$catCount = [];    // cat_id => [path, count]

foreach ($ids as $itemId) {
    $itemId = (string) $itemId;   // numeric-string keys come back as ints
    $done++;
    $itemPath = $itemDir . "/{$itemId}.json";

    if (!$refresh && is_file($itemPath)) {
        $snap = json_decode((string) file_get_contents($itemPath), true) ?: [];
        $skipped++;
    } else {
        $snap = fetchItem($client, $itemId);
        if ($snap['status'] === 'RATE_LIMIT') {
            fwrite(STDERR, "  [429] backing off 30s at {$itemId}...\n");
            sleep(30);
            $snap = fetchItem($client, $itemId);
        }
        file_put_contents($itemPath, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if ($snap['status'] === 'OK') { $fetched++; } else { $errors++; }
        usleep(120000); // ~8/sec, well under Browse limits
    }

    $summary[$itemId] = [
        'title'       => $snap['title'] ?? '',
        'category_id' => $snap['category_id'] ?? '',
        'category_path' => $snap['category_path'] ?? '',
        'aspect_count' => is_array($snap['aspects'] ?? null) ? count($snap['aspects']) : 0,
        'status'      => $snap['status'] ?? '?',
    ];
    if (($snap['category_id'] ?? '') !== '') {
        $cid = $snap['category_id'];
        $catCount[$cid] ??= ['path' => $snap['category_path'] ?? '', 'count' => 0];
        $catCount[$cid]['count']++;
    }

    if ($done % 5 === 0) { echo "  {$done}/" . count($ids) . " (fetched {$fetched}, skipped {$skipped}, err {$errors})\n"; }
}

// --- write roll-ups ------------------------------------------------------------
$csv = $outDir . '/enriched_summary.csv';
$fh = fopen($csv, 'w');
fputcsv($fh, ['item_id', 'title', 'category_id', 'category_path', 'aspect_count', 'status']);
foreach ($summary as $id => $s) {
    fputcsv($fh, [$id, $s['title'], $s['category_id'], $s['category_path'], $s['aspect_count'], $s['status']]);
}
fclose($fh);

$catCsv = $outDir . '/category_coverage.csv';
uasort($catCount, fn($a, $b) => $b['count'] <=> $a['count']);
$fh = fopen($catCsv, 'w');
fputcsv($fh, ['category_id', 'category_path', 'listing_count']);
foreach ($catCount as $cid => $c) { fputcsv($fh, [$cid, $c['path'], $c['count']]); }
fclose($fh);

echo "\n========================================\n";
echo "enriched: fetched {$fetched}, skipped(cached) {$skipped}, errors {$errors}\n";
echo "distinct leaf categories: " . count($catCount) . "\n";
echo "  {$csv}\n  {$catCsv}\n  {$itemDir}/{itemId}.json\n";

exit($errors > 0 ? 1 : 0);

// --- helpers -------------------------------------------------------------------

/**
 * Fetch one listing's specifics + category. Single-variation listings come from
 * getItem; multi-variation listings 404 there and are fetched via
 * get_items_by_item_group (aspects unioned across variations, category from the
 * first item). Returns is_group + variation_count so the audit can tell them apart.
 *
 * @return array{item_id:string,title:string,category_id:string,category_path:string,aspects:array<string,string>,condition:string,is_group:bool,variation_count:int,status:string}
 */
function fetchItem(EbayClient $client, string $itemId): array
{
    $url = BROWSE_BASE . 'v1%7C' . rawurlencode($itemId) . '%7C0';
    try {
        $res = $client->userSend('GET', $url, null, ['X-EBAY-C-MARKETPLACE-ID' => MARKETPLACE]);
    } catch (\Throwable $e) {
        return base($itemId, 'HTTP_ERR');
    }
    if ($res['status'] === 429) { return base($itemId, 'RATE_LIMIT'); }
    if ($res['status'] === 404) { return fetchGroup($client, $itemId); }   // likely a variation group
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
        return base($itemId, 'ERR_' . $res['status']);
    }

    $j = $res['json'];
    return [
        'item_id'         => $itemId,
        'title'           => (string) ($j['title'] ?? ''),
        'category_id'     => (string) ($j['categoryId'] ?? ''),
        'category_path'   => (string) ($j['categoryPath'] ?? ''),
        'aspects'         => extractAspects($j['localizedAspects'] ?? []),
        'condition'       => (string) ($j['condition'] ?? ''),
        'is_group'        => false,
        'variation_count' => 0,
        'status'          => 'OK',
    ];
}

/** Multi-variation listing: get_items_by_item_group, union aspects across variations. */
function fetchGroup(EbayClient $client, string $itemId): array
{
    $url = BROWSE_BASE . 'get_items_by_item_group?item_group_id=' . rawurlencode($itemId);
    try {
        $res = $client->userSend('GET', $url, null, ['X-EBAY-C-MARKETPLACE-ID' => MARKETPLACE]);
    } catch (\Throwable $e) {
        return base($itemId, 'HTTP_ERR');
    }
    if ($res['status'] === 429) { return base($itemId, 'RATE_LIMIT'); }
    if ($res['status'] === 404) { return base($itemId, 'NOT_FOUND'); }
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
        return base($itemId, 'ERR_' . $res['status']);
    }

    $items = $res['json']['items'] ?? [];
    if ($items === []) { return base($itemId, 'EMPTY_GROUP'); }

    $first   = $items[0];
    $aspects = [];
    foreach ($items as $it) {                       // union; first value seen wins
        foreach (extractAspects($it['localizedAspects'] ?? []) as $k => $v) {
            $aspects[$k] ??= $v;
        }
    }
    return [
        'item_id'         => $itemId,
        'title'           => (string) ($first['title'] ?? ''),
        'category_id'     => (string) ($first['categoryId'] ?? ''),
        'category_path'   => (string) ($first['categoryPath'] ?? ''),
        'aspects'         => $aspects,
        'condition'       => (string) ($first['condition'] ?? ''),
        'is_group'        => true,
        'variation_count' => count($items),
        'status'          => 'OK',
    ];
}

/** @param array<int,array{name?:string,value?:string}> $list @return array<string,string> */
function extractAspects(array $list): array
{
    $aspects = [];
    foreach ($list as $a) {
        $name = (string) ($a['name'] ?? '');
        if ($name !== '') { $aspects[$name] = (string) ($a['value'] ?? ''); }
    }
    return $aspects;
}

/** @return array{item_id:string,title:string,category_id:string,category_path:string,aspects:array<string,string>,condition:string,is_group:bool,variation_count:int,status:string} */
function base(string $itemId, string $status): array
{
    return ['item_id' => $itemId, 'title' => '', 'category_id' => '', 'category_path' => '', 'aspects' => [], 'condition' => '', 'is_group' => false, 'variation_count' => 0, 'status' => $status];
}
