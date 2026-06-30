<?php

declare(strict_types=1);

/**
 * audit_media.php — collect MEDIA + LISTING-CONTENT facts per eBay listing.
 * READ-ONLY against eBay (Browse getItem, the same reachable surface
 * enrich_listings.php uses). NO writes.
 *
 * For every ACTIVE listing this captures, straight from the Browse payload:
 *   1. image_count    — primary image + additionalImages
 *   2. description    — the FULL HTML item description (not just the text)
 *   3. price          — value + currency (the displayed Buy-It-Now price)
 *   4. image urls     — every gallery image URL, each tagged:
 *        - host + is_eps : EPS/eBay-Media hosted (i.ebayimg.com / *.ebayimg.com)
 *          vs self-hosted (any other domain — a quality/risk signal),
 *        - width x height : pixel dimensions eBay already returns per image
 *          (no extra HTTP needed), so we can flag images below the 800px
 *          zoom threshold / 1600px ideal.
 * (5) SEO improvement of the descriptions is a downstream step that consumes
 *     media/{itemId}.json — this script only gathers the raw material.
 *
 * Why a NEW snapshot dir (media/) instead of reusing items/: the Phase-1
 * enrich snapshots deliberately kept only title/category/aspects. Re-fetching
 * here would overwrite those with a different schema; keeping media/ separate
 * preserves both and lets this run independently / resumably.
 *
 * Output (under ebay/data/{account}/output/):
 *   media/{itemId}.json   per-listing: price, images[], image_count, eps stats,
 *                         description (HTML), short_description, revision, status
 *   media_summary.csv     one row per listing (the eyeball/triage sheet)
 *   media_images.csv      one row per image (item_id, pos, url, w, h, host, is_eps)
 *
 * Resumable: skips itemIds already in media/ unless --refresh. Backs off on 429.
 *
 * Usage:
 *   php ebay/scripts/audit_media.php --account=dows --limit=5     # canary
 *   php ebay/scripts/audit_media.php --account=dows               # full roster
 *   php ebay/scripts/audit_media.php --account=ige --refresh
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

const BROWSE_BASE = 'https://api.ebay.com/buy/browse/v1/item/';
const MARKETPLACE = 'EBAY_US';
const ZOOM_MIN    = 800;    // eBay: longest side < 800px is not zoom-eligible
const IDEAL_MIN   = 1600;   // eBay recommended longest side for best quality/zoom

$opts = getopt('', ['account:', 'limit:', 'ids:', 'refresh', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php audit_media.php --account=dows|ige [--limit=N] [--ids=ID,ID] [--refresh]\n");
    exit(0);
}
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$limit   = isset($opts['limit']) ? (int) $opts['limit'] : null;
$refresh = isset($opts['refresh']);
$onlyIds = isset($opts['ids']) ? array_filter(array_map('trim', explode(',', (string) $opts['ids']))) : null;

$client   = new EbayClient($account);
$outDir   = ebay_dir($account, 'output');
$mediaDir = $outDir . '/media';
if (!is_dir($mediaDir)) { mkdir($mediaDir, 0775, true); }

$rosterPath = $outDir . '/listings.json';
if (!is_file($rosterPath)) {
    fwrite(STDERR, "No roster at {$rosterPath}. Run export_listings.php --account={$account} first.\n");
    exit(1);
}
$roster = json_decode((string) file_get_contents($rosterPath), true) ?: [];

// item_id -> parent sku (for the summary). Variations share one ItemID.
$skuOf = [];
$ids   = [];
foreach ($roster as $l) {
    $id = (string) ($l['item_id'] ?? '');
    if ($id === '') { continue; }
    $ids[$id]   = true;
    $skuOf[$id] = (string) ($l['sku'] ?? '');
}
$ids = array_keys($ids);
if ($onlyIds !== null) { $ids = array_values(array_intersect($ids, $onlyIds)); }
if ($limit !== null)   { $ids = array_slice($ids, 0, $limit); }

echo "=== media audit: {$account} (" . $client->env() . ") ===\n";
echo "listings to audit: " . count($ids) . ($refresh ? " (refresh)" : " (resumable)") . "\n";

$done = 0; $fetched = 0; $skipped = 0; $errors = 0;
$rows = [];          // summary rows keyed by item_id
$imgRows = [];       // flat image rows

foreach ($ids as $itemId) {
    $itemId = (string) $itemId;
    $done++;
    $path = $mediaDir . "/{$itemId}.json";

    if (!$refresh && is_file($path)) {
        $snap = json_decode((string) file_get_contents($path), true) ?: [];
        $skipped++;
    } else {
        $snap = fetchMedia($client, $itemId);
        if ($snap['status'] === 'RATE_LIMIT') {
            fwrite(STDERR, "  [429] backing off 30s at {$itemId}...\n");
            sleep(30);
            $snap = fetchMedia($client, $itemId);
        }
        file_put_contents($path, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        if ($snap['status'] === 'OK') { $fetched++; } else { $errors++; }
        usleep(120000); // ~8/sec, within Browse limits
    }

    // --- roll up for the CSVs --------------------------------------------------
    $images   = $snap['images'] ?? [];
    $epsCount = 0; $nonEps = 0; $minDim = null; $maxDim = null;
    $urls = [];
    foreach ($images as $pos => $im) {
        $is_eps = $im['is_eps'] ? 'yes' : 'no';
        $is_eps === 'yes' ? $epsCount++ : $nonEps++;
        $long = max((int) $im['width'], (int) $im['height']);   // longest side
        if ($long > 0) {
            $minDim = $minDim === null ? $long : min($minDim, $long);
            $maxDim = $maxDim === null ? $long : max($maxDim, $long);
        }
        $urls[] = $im['url'];
        $imgRows[] = [$itemId, $pos, $im['url'], $im['width'], $im['height'], $im['host'], $is_eps];
    }
    $descHtml = (string) ($snap['description'] ?? '');
    $descText = trim(preg_replace('/\s+/', ' ', strip_tags($descHtml)));

    $rows[$itemId] = [
        'item_id'        => $itemId,
        'sku'            => $skuOf[$itemId] ?? '',
        'title'          => $snap['title'] ?? '',
        'price'          => $snap['price'] ?? '',
        'currency'       => $snap['currency'] ?? '',
        'image_url'      => implode(", \n", $urls),
        'image_count'    => count($images),
        'eps_images'     => $epsCount,
        'non_eps_images' => $nonEps,
        'all_eps'        => ($images !== [] && $nonEps === 0) ? 'yes' : 'no',
        'min_long_px'    => $minDim ?? '',
        'max_long_px'    => $maxDim ?? '',
        'below_zoom_800' => ($minDim !== null && $minDim < ZOOM_MIN) ? 'yes' : 'no',
        'below_ideal'    => ($minDim !== null && $minDim < IDEAL_MIN) ? 'yes' : 'no',
        'desc_html_len'  => strlen($descHtml),
        'desc_text_len'  => strlen($descText),
        'has_desc'       => $descHtml !== '' ? 'yes' : 'no',
        'is_group'       => !empty($snap['is_group']) ? 'yes' : 'no',
        'status'         => $snap['status'] ?? '?',
    ];

    if ($done % 50 === 0) { echo "  {$done}/" . count($ids) . " (fetched {$fetched}, skipped {$skipped}, err {$errors})\n"; }
}

// --- write roll-ups ------------------------------------------------------------
$sumPath = $outDir . '/media_summary.csv';
$fh = fopen($sumPath, 'w');
fputcsv($fh, array_keys(reset($rows) ?: ['item_id' => '']));
foreach ($rows as $r) { fputcsv($fh, $r); }
fclose($fh);

$imgPath = $outDir . '/media_images.csv';
$fh = fopen($imgPath, 'w');
fputcsv($fh, ['item_id', 'position', 'url', 'width', 'height', 'host', 'is_eps']);
foreach ($imgRows as $r) { fputcsv($fh, $r); }
fclose($fh);

// --- aggregate report ----------------------------------------------------------
$tot       = count($rows);
$noImg     = count(array_filter($rows, fn($r) => $r['image_count'] === 0));
$anyNonEps = count(array_filter($rows, fn($r) => $r['non_eps_images'] > 0));
$belowZoom = count(array_filter($rows, fn($r) => $r['below_zoom_800'] === 'yes'));
$noDesc    = count(array_filter($rows, fn($r) => $r['has_desc'] === 'no'));
$totalImgs = array_sum(array_map(fn($r) => $r['image_count'], $rows));

echo "\n========================================\n";
echo "audited: fetched {$fetched}, skipped(cached) {$skipped}, errors {$errors}\n";
printf("listings: %d | total images: %d | avg %.1f img/listing\n", $tot, $totalImgs, $tot ? $totalImgs / $tot : 0);
printf("  listings with NO image:        %d\n", $noImg);
printf("  listings with a non-EPS image: %d\n", $anyNonEps);
printf("  listings below 800px zoom min: %d\n", $belowZoom);
printf("  listings with NO description:  %d\n", $noDesc);
echo "  {$sumPath}\n  {$imgPath}\n  {$mediaDir}/{itemId}.json\n";

// --- helpers -------------------------------------------------------------------

/**
 * Fetch one listing's media + content. Single listings via getItem; multi-variation
 * listings 404 there and are fetched via get_items_by_item_group (images unioned
 * across variations by URL, content from the first item).
 *
 * @return array{item_id:string,title:string,price:string,currency:string,images:list<array{url:string,width:int,height:int,host:string,is_eps:bool}>,description:string,short_description:string,revision:string,is_group:bool,status:string}
 */
function fetchMedia(EbayClient $client, string $itemId): array
{
    $url = BROWSE_BASE . 'v1%7C' . rawurlencode($itemId) . '%7C0';
    try {
        $res = $client->userSend('GET', $url, null, ['X-EBAY-C-MARKETPLACE-ID' => MARKETPLACE]);
    } catch (\Throwable $e) {
        return base($itemId, 'HTTP_ERR');
    }
    if ($res['status'] === 429) { return base($itemId, 'RATE_LIMIT'); }
    if ($res['status'] === 404) { return fetchGroup($client, $itemId); }
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
        return base($itemId, 'ERR_' . $res['status']);
    }

    $j = $res['json'];
    return [
        'item_id'           => $itemId,
        'title'             => (string) ($j['title'] ?? ''),
        'price'             => (string) ($j['price']['value'] ?? ''),
        'currency'          => (string) ($j['price']['currency'] ?? ''),
        'images'            => collectImages([$j]),
        'description'       => (string) ($j['description'] ?? ''),
        'short_description' => (string) ($j['shortDescription'] ?? ''),
        'revision'          => (string) ($j['sellerItemRevision'] ?? ''),
        'is_group'          => false,
        'status'            => 'OK',
    ];
}

/** Multi-variation listing: get_items_by_item_group; union images across variations. */
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

    $first = $items[0];
    // The group endpoint omits per-variation `description`. Backfill it with ONE
    // extra getItem on the first child (its full v1|group|var itemId), since all
    // variations of a listing share the same HTML description.
    $desc = (string) ($first['description'] ?? '');
    if ($desc === '' && ($childId = (string) ($first['itemId'] ?? '')) !== '') {
        $desc = fetchChildDescription($client, $childId);
    }
    return [
        'item_id'           => $itemId,
        'title'             => (string) ($first['title'] ?? ''),
        'price'             => (string) ($first['price']['value'] ?? ''),
        'currency'          => (string) ($first['price']['currency'] ?? ''),
        'images'            => collectImages($items),
        'description'       => $desc,
        'short_description' => (string) ($first['shortDescription'] ?? ''),
        'revision'          => (string) ($first['sellerItemRevision'] ?? ''),
        'is_group'          => true,
        'status'            => 'OK',
    ];
}

/** Fetch just the HTML description for a single child item (full v1|group|var id). */
function fetchChildDescription(EbayClient $client, string $childItemId): string
{
    $url = BROWSE_BASE . rawurlencode($childItemId);
    try {
        $res = $client->userSend('GET', $url, null, ['X-EBAY-C-MARKETPLACE-ID' => MARKETPLACE]);
    } catch (\Throwable $e) {
        return '';
    }
    return ($res['status'] >= 200 && $res['status'] < 300 && is_array($res['json']))
        ? (string) ($res['json']['description'] ?? '')
        : '';
}

/**
 * Flatten image/additionalImages from one or more Browse item payloads into a
 * de-duplicated, ordered list. Primary `image` first, then `additionalImages`.
 *
 * @param array<int,array<string,mixed>> $items
 * @return list<array{url:string,width:int,height:int,host:string,is_eps:bool}>
 */
function collectImages(array $items): array
{
    $out = []; $seen = [];
    foreach ($items as $it) {
        $candidates = [];
        if (isset($it['image']) && is_array($it['image'])) { $candidates[] = $it['image']; }
        foreach (($it['additionalImages'] ?? []) as $a) {
            if (is_array($a)) { $candidates[] = $a; }
        }
        foreach ($candidates as $im) {
            $u = (string) ($im['imageUrl'] ?? '');
            if ($u === '' || isset($seen[$u])) { continue; }
            $seen[$u] = true;
            $host = (string) (parse_url($u, PHP_URL_HOST) ?? '');
            $out[] = [
                'url'    => $u,
                'width'  => (int) ($im['width'] ?? 0),
                'height' => (int) ($im['height'] ?? 0),
                'host'   => $host,
                // EPS / eBay Media: every eBay-hosted image lives on *.ebayimg.com.
                // Anything else is seller self-hosted (a quality/availability risk).
                'is_eps' => $host !== '' && (bool) preg_match('/(^|\.)ebayimg\.com$/i', $host),
            ];
        }
    }
    return $out;
}

/** @return array{item_id:string,title:string,price:string,currency:string,images:list<array{url:string,width:int,height:int,host:string,is_eps:bool}>,description:string,short_description:string,revision:string,is_group:bool,status:string} */
function base(string $itemId, string $status): array
{
    return [
        'item_id' => $itemId, 'title' => '', 'price' => '', 'currency' => '',
        'images' => [], 'description' => '', 'short_description' => '',
        'revision' => '', 'is_group' => false, 'status' => $status,
    ];
}
