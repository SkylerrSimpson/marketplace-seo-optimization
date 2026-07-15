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
 *                         description (HTML), short_description, revision, status,
 *                         children[] (variation listings only -- see below)
 *   media_summary.csv     ONE merged sheet, one PARENT row per listing (the
 *                         eyeball/triage columns: image_count, eps stats, zoom
 *                         thresholds, desc presence, parent_image_count /
 *                         child_image_count_total) immediately followed by one
 *                         CHILD row per variation SKU (Patrick's ask, 2026-07-08:
 *                         each child's own image count vs how many of those are
 *                         shared with every other child ("parent" images) vs
 *                         unique to that child). row_type distinguishes the two;
 *                         parent-only columns are blank on child rows and vice
 *                         versa -- same shape as gtin_report's parent+child rows.
 *                         Was two separate files (media_summary.csv +
 *                         media_variation_images.csv) until 2026-07-10; merged
 *                         after Ethan was sent the parent-only file and couldn't
 *                         see per-variation images at all.
 *   media_images.csv      one row per image (item_id, pos, url, w, h, host, is_eps)
 *
 * Parent vs child SKU images: Browse API's get_items_by_item_group returns each
 * variation with its OWN primary image + additionalImages (no shared "parent
 * gallery" concept exists on eBay's side). We treat any image URL common to
 * EVERY child as a "parent" image (the shared default gallery); anything only
 * on some children is "child"/variation-specific. The Browse payload has no
 * SKU field per child, so we match each child back to its real SKU by its
 * varying-aspect value(s) (localizedAspects) against listings.json's
 * variations[].specifics for that item -- falls back to the Browse child
 * itemId (sku='') with match_method=unmatched if no exact match is found.
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
// item_id -> [variedNames set, specificsKey -> sku] for matching Browse API
// group children (no SKU field) back to their real SKU (see collectChildren()).
$skuOf    = [];
$varMatch = [];
$ids      = [];
foreach ($roster as $l) {
    $id = (string) ($l['item_id'] ?? '');
    if ($id === '') { continue; }
    $ids[$id]   = true;
    $skuOf[$id] = (string) ($l['sku'] ?? '');

    if (!empty($l['variations'])) {
        $variedNames = [];
        $bySpecKey   = [];
        $variantList = [];
        foreach ($l['variations'] as $v) {
            $pairs = parseSpecifics((string) ($v['specifics'] ?? ''));
            foreach ($pairs as [$k, $_]) { $variedNames[nrm($k)] = true; }
            $sku = (string) ($v['sku'] ?? '');
            $bySpecKey[specKey($pairs)] = $sku;
            $variantList[] = ['sku' => $sku, 'pairs' => $pairs];
        }
        $varMatch[$id] = ['names' => $variedNames, 'bySpecKey' => $bySpecKey, 'variants' => $variantList];
    }
}
$ids = array_keys($ids);
if ($onlyIds !== null) { $ids = array_values(array_intersect($ids, $onlyIds)); }
if ($limit !== null)   { $ids = array_slice($ids, 0, $limit); }

echo "=== media audit: {$account} (" . $client->env() . ") ===\n";
echo "listings to audit: " . count($ids) . ($refresh ? " (refresh)" : " (resumable)") . "\n";

$done = 0; $fetched = 0; $skipped = 0; $errors = 0;
$rows = [];          // summary rows keyed by item_id (used for the aggregate report)
$imgRows = [];       // flat image rows
$varRows = [];       // per-variation-child image rows (used for the aggregate report)
$allRows = [];       // merged parent+child rows, in listing order -- what gets written to media_summary.csv

const SUMMARY_COLUMNS = [
    'item_id', 'row_type', 'sku', 'child_item_id', 'match_method', 'title',
    'image_url', 'image_count', 'unique_images', 'shared_with_parent_images',
    'eps_images', 'non_eps_images', 'all_eps', 'min_long_px', 'max_long_px',
    'below_zoom_800', 'below_ideal', 'desc_html_len', 'desc_text_len', 'has_desc',
    'is_group', 'variation_count', 'parent_image_count', 'child_image_count_total',
];

foreach ($ids as $itemId) {
    $itemId = (string) $itemId;
    $done++;
    $path = $mediaDir . "/{$itemId}.json";
    $varInfo = $varMatch[$itemId] ?? null;

    if (!$refresh && is_file($path)) {
        $snap = json_decode((string) file_get_contents($path), true) ?: [];
        $skipped++;
    } else {
        $snap = fetchMedia($client, $itemId, $varInfo);
        if ($snap['status'] === 'RATE_LIMIT') {
            fwrite(STDERR, "  [429] backing off 30s at {$itemId}...\n");
            sleep(30);
            $snap = fetchMedia($client, $itemId, $varInfo);
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

    // --- parent vs child SKU image counts (variation listings only) -----------
    // A "parent" image = URL present on every child (the shared default gallery).
    // A "child" image = URL unique to that one child (a variation-specific photo).
    $children = $snap['children'] ?? [];
    $parentImageCount = count($images);   // default for solo listings: no split
    $childImageCountTotal = 0;
    $childSummaryRows = [];
    if ($children !== []) {
        $commonUrls = null;
        foreach ($children as $c) {
            $u = array_column($c['images'], 'url');
            $commonUrls = $commonUrls === null ? array_flip($u) : array_intersect_key($commonUrls, array_flip($u));
        }
        $commonUrls = $commonUrls ?? [];
        $parentImageCount = count($commonUrls);
        foreach ($children as $c) {
            $total  = count($c['images']);
            $unique = count(array_filter($c['images'], fn($im) => !isset($commonUrls[$im['url']])));
            $childImageCountTotal += $unique;
            $varRows[] = [
                $itemId, $c['sku'], $c['child_item_id'], $c['match_method'],
                $total, $unique, $total - $unique,
            ];
            $childSummaryRows[] = [
                'item_id' => $itemId, 'row_type' => 'child', 'sku' => $c['sku'],
                'child_item_id' => $c['child_item_id'], 'match_method' => $c['match_method'],
                'title' => '', 'image_url' => '', 'image_count' => $total,
                'unique_images' => $unique, 'shared_with_parent_images' => $total - $unique,
                'eps_images' => '', 'non_eps_images' => '', 'all_eps' => '',
                'min_long_px' => '', 'max_long_px' => '', 'below_zoom_800' => '',
                'below_ideal' => '', 'desc_html_len' => '', 'desc_text_len' => '',
                'has_desc' => '', 'is_group' => '', 'variation_count' => '',
                'parent_image_count' => '', 'child_image_count_total' => '',
            ];
        }
    }

    $rows[$itemId] = [
        'item_id'                => $itemId,
        'sku'                    => $skuOf[$itemId] ?? '',
        'title'                  => $snap['title'] ?? '',
        'image_url'              => implode(", \n", $urls),
        'image_count'            => count($images),
        'eps_images'             => $epsCount,
        'non_eps_images'         => $nonEps,
        'all_eps'                => ($images !== [] && $nonEps === 0) ? 'yes' : 'no',
        'min_long_px'            => $minDim ?? '',
        'max_long_px'            => $maxDim ?? '',
        'below_zoom_800'         => ($minDim !== null && $minDim < ZOOM_MIN) ? 'yes' : 'no',
        'below_ideal'            => ($minDim !== null && $minDim < IDEAL_MIN) ? 'yes' : 'no',
        'desc_html_len'          => strlen($descHtml),
        'desc_text_len'          => strlen($descText),
        'has_desc'               => $descHtml !== '' ? 'yes' : 'no',
        'is_group'               => !empty($snap['is_group']) ? 'yes' : 'no',
        'variation_count'        => count($children),
        'parent_image_count'     => $parentImageCount,
        'child_image_count_total' => $childImageCountTotal,
    ];

    // --- merged sheet: parent row immediately followed by its child rows ------
    $r = $rows[$itemId];
    $allRows[] = [
        'item_id' => $itemId, 'row_type' => 'parent', 'sku' => $r['sku'],
        'child_item_id' => '', 'match_method' => '', 'title' => $r['title'],
        'image_url' => $r['image_url'], 'image_count' => $r['image_count'],
        'unique_images' => '', 'shared_with_parent_images' => '',
        'eps_images' => $r['eps_images'], 'non_eps_images' => $r['non_eps_images'],
        'all_eps' => $r['all_eps'], 'min_long_px' => $r['min_long_px'],
        'max_long_px' => $r['max_long_px'], 'below_zoom_800' => $r['below_zoom_800'],
        'below_ideal' => $r['below_ideal'], 'desc_html_len' => $r['desc_html_len'],
        'desc_text_len' => $r['desc_text_len'], 'has_desc' => $r['has_desc'],
        'is_group' => $r['is_group'], 'variation_count' => $r['variation_count'],
        'parent_image_count' => $r['parent_image_count'],
        'child_image_count_total' => $r['child_image_count_total'],
    ];
    foreach ($childSummaryRows as $cr) {
        $cr['title'] = $r['title']; // filled in now that the parent title is known
        $allRows[] = $cr;
    }

    if ($done % 50 === 0) { echo "  {$done}/" . count($ids) . " (fetched {$fetched}, skipped {$skipped}, err {$errors})\n"; }
}

// --- write roll-ups ------------------------------------------------------------
// Merged sheet: one parent row per listing immediately followed by its child rows
// (if any). row_type distinguishes the two; parent-only columns are blank on child
// rows and vice versa. See SUMMARY_COLUMNS / the file header comment.
$sumPath = $outDir . '/media_summary.csv';
$fh = fopen($sumPath, 'w');
fputcsv($fh, SUMMARY_COLUMNS);
foreach ($allRows as $r) { fputcsv($fh, $r); }
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
$totVarListings = count(array_filter($rows, fn($r) => $r['variation_count'] > 0));
$unmatchedSkus  = count(array_filter($varRows, fn($r) => $r[3] === 'unmatched'));

echo "\n========================================\n";
echo "audited: fetched {$fetched}, skipped(cached) {$skipped}, errors {$errors}\n";
printf("listings: %d | total images: %d | avg %.1f img/listing\n", $tot, $totalImgs, $tot ? $totalImgs / $tot : 0);
printf("  listings with NO image:        %d\n", $noImg);
printf("  listings with a non-EPS image: %d\n", $anyNonEps);
printf("  listings below 800px zoom min: %d\n", $belowZoom);
printf("  listings with NO description:  %d\n", $noDesc);
printf("  variation listings: %d | variation children: %d (%d unmatched to a SKU)\n", $totVarListings, count($varRows), $unmatchedSkus);
echo "  {$sumPath}\n  {$imgPath}\n  {$mediaDir}/{itemId}.json\n";

// --- helpers -------------------------------------------------------------------

/**
 * Fetch one listing's media + content. Single listings via getItem; multi-variation
 * listings 404 there and are fetched via get_items_by_item_group (images unioned
 * across variations by URL, content from the first item).
 *
 * @param ?array{names:array<string,bool>,bySpecKey:array<string,string>} $varInfo
 * @return array{item_id:string,title:string,price:string,currency:string,images:list<array{url:string,width:int,height:int,host:string,is_eps:bool}>,description:string,short_description:string,revision:string,is_group:bool,status:string}
 */
function fetchMedia(EbayClient $client, string $itemId, ?array $varInfo = null): array
{
    $url = BROWSE_BASE . 'v1%7C' . rawurlencode($itemId) . '%7C0';
    try {
        $res = $client->userSend('GET', $url, null, ['X-EBAY-C-MARKETPLACE-ID' => MARKETPLACE]);
    } catch (\Throwable $e) {
        return base($itemId, 'HTTP_ERR');
    }
    if ($res['status'] === 429) { return base($itemId, 'RATE_LIMIT'); }
    if ($res['status'] === 404) { return fetchGroup($client, $itemId, $varInfo); }
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
function fetchGroup(EbayClient $client, string $itemId, ?array $varInfo = null): array
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
        'children'          => collectChildren($items, $varInfo),
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

function nrm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }

/** "Color=Black; Size=25 ft" -> [['Color','Black'],['Size','25 ft']] (matches build_review_sheet.php) */
function parseSpecifics(string $s): array
{
    $out = [];
    foreach (explode(';', $s) as $pair) {
        if (strpos($pair, '=') === false) { continue; }
        [$k, $v] = explode('=', $pair, 2);
        $k = trim($k); $v = trim($v);
        if ($k !== '') { $out[] = [$k, $v]; }
    }
    return $out;
}

/** Order-independent key for a set of (name, value) pairs, normalized for matching. */
function specKey(array $pairs): string
{
    $norm = [];
    foreach ($pairs as [$k, $v]) { $norm[] = nrm($k) . '=' . nrm($v); }
    sort($norm);
    return implode('|', $norm);
}

/**
 * Per-variation-child image breakdown for a group listing. Browse API's
 * get_items_by_item_group has no SKU field per child, so each child is matched
 * back to its real listings.json SKU by its varying-aspect value(s)
 * (localizedAspects) against $varInfo['bySpecKey'].
 *
 * Browse API's localizedAspects doesn't always surface every varying
 * dimension -- e.g. a listing can vary by Color+MPN in Trading API/
 * listings.json (MPN being a "hidden" per-child dimension, same phenomenon as
 * the aspects write-back's hidden-MPN case) while Browse only reports Color
 * per child. An exact full-key match would then always miss. So on a miss we
 * fall back to a partial match: find listings.json variations whose specifics
 * agree on every dimension Browse DID report; if exactly one candidate
 * matches, use it (match_method=partial_aspect_match) -- ambiguous (>1) or
 * empty (0) candidates stay unmatched rather than guessing.
 *
 * @param array<int,array<string,mixed>> $items
 * @param ?array{names:array<string,bool>,bySpecKey:array<string,string>,variants:list<array{sku:string,pairs:list<array{0:string,1:string}>}>} $varInfo
 * @return list<array{sku:string,child_item_id:string,match_method:string,images:list<array{url:string,width:int,height:int,host:string,is_eps:bool}>}>
 */
function collectChildren(array $items, ?array $varInfo): array
{
    $variedNames = $varInfo['names'] ?? [];
    $bySpecKey   = $varInfo['bySpecKey'] ?? [];
    $variants    = $varInfo['variants'] ?? [];

    $out = [];
    foreach ($items as $it) {
        $childId = (string) ($it['itemId'] ?? '');

        $pairs = [];
        foreach (($it['localizedAspects'] ?? []) as $a) {
            $an = nrm((string) ($a['name'] ?? ''));
            if (isset($variedNames[$an])) {
                $pairs[] = [(string) ($a['name'] ?? ''), (string) ($a['value'] ?? '')];
            }
        }
        $sku = ''; $matchMethod = 'unmatched';
        if ($pairs !== []) {
            $key = specKey($pairs);
            if (isset($bySpecKey[$key])) {
                $sku = $bySpecKey[$key]; $matchMethod = 'aspect_match';
            } else {
                // partial match: Browse only reported a subset of the varying
                // dimensions (e.g. Color but not a hidden per-child MPN).
                $childVals = [];
                foreach ($pairs as [$k, $v]) { $childVals[nrm($k)] = nrm($v); }
                $candidates = [];
                foreach ($variants as $variant) {
                    $vVals = [];
                    foreach ($variant['pairs'] as [$k, $v]) { $vVals[nrm($k)] = nrm($v); }
                    $agree = true;
                    foreach ($childVals as $k => $v) {
                        if (!isset($vVals[$k]) || $vVals[$k] !== $v) { $agree = false; break; }
                    }
                    if ($agree) { $candidates[] = $variant['sku']; }
                }
                if (count(array_unique($candidates)) === 1) {
                    $sku = $candidates[0]; $matchMethod = 'partial_aspect_match';
                }
            }
        }
        if ($sku === '') { $sku = $childId; }

        $candidates = [];
        if (isset($it['image']) && is_array($it['image'])) { $candidates[] = $it['image']; }
        foreach (($it['additionalImages'] ?? []) as $a) {
            if (is_array($a)) { $candidates[] = $a; }
        }
        $images = [];
        foreach ($candidates as $im) {
            $u = (string) ($im['imageUrl'] ?? '');
            if ($u === '') { continue; }
            $host = (string) (parse_url($u, PHP_URL_HOST) ?? '');
            $images[] = [
                'url'    => $u,
                'width'  => (int) ($im['width'] ?? 0),
                'height' => (int) ($im['height'] ?? 0),
                'host'   => $host,
                'is_eps' => $host !== '' && (bool) preg_match('/(^|\.)ebayimg\.com$/i', $host),
            ];
        }
        $out[] = ['sku' => $sku, 'child_item_id' => $childId, 'match_method' => $matchMethod, 'images' => $images];
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
