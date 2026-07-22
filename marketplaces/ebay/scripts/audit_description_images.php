<?php

declare(strict_types=1);

/**
 * audit_description_images.php — DRY-RUN audit of <img> tags embedded directly
 * in each listing's description HTML (mostly old AWS-hosted product photos).
 * Separate concern from the gallery/EPS image audit (audit_media.php /
 * image_review.csv): this checks images hard-coded into the description body
 * itself:
 *   1. broken links -- HTTP HEAD (falls back to a ranged GET) on every
 *      DISTINCT src URL, concurrently via curl_multi
 *   2. alt text -- missing (no attribute / empty) vs a generic placeholder
 *      (e.g. the literal template default "Responsive image") vs a real value
 *
 * No eBay API calls here -- reads description HTML already captured in
 * media/{itemId}.json by audit_media.php. Run that first.
 *
 * Output: description_image_audit.csv, one row per <img> tag found.
 * Usage: php ebay/scripts/audit_description_images.php --account=dows [--limit=N] [--concurrency=20]
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts        = getopt('', ['account:', 'limit::', 'concurrency::']);
$account     = strtolower((string) ($opts['account'] ?? 'dows'));
$limit       = isset($opts['limit']) ? (int) $opts['limit'] : null;
$concurrency = isset($opts['concurrency']) ? (int) $opts['concurrency'] : 20;

$dir      = ebay_dir($account, 'output');
$mediaDir = $dir . '/media';
if (!is_dir($mediaDir)) {
    fwrite(STDERR, "No media/ dir at {$mediaDir}. Run audit_media.php --account={$account} first.\n");
    exit(1);
}

// generic/placeholder alt text that's technically "present" but not descriptive
$GENERIC_ALTS = ['responsive image', 'image', 'photo', 'picture', 'img', 'product image', 'product photo'];

// --- Pass 1: parse every listing's description HTML for <img> tags ------------
$files = glob($mediaDir . '/*.json');
sort($files);
if ($limit !== null) { $files = array_slice($files, 0, $limit); }

$rows = [];        // item_id, position, src, alt
$distinctUrls = []; // url -> true

foreach ($files as $f) {
    $itemId = basename($f, '.json');
    $snap = json_decode((string) file_get_contents($f), true) ?: [];
    if (($snap['status'] ?? '') !== 'OK') { continue; }
    $html = (string) ($snap['description'] ?? '');
    if ($html === '') { continue; }

    foreach (extractImgTags($html) as $pos => [$src, $alt]) {
        $rows[] = ['item_id' => $itemId, 'position' => $pos, 'src' => $src, 'alt' => $alt];
        $distinctUrls[$src] = true;
    }
}

echo "=== description image audit: {$account} ===\n";
printf("listings scanned: %d | <img> tags found: %d | distinct URLs: %d\n", count($files), count($rows), count($distinctUrls));

// --- Pass 2: HTTP-check every DISTINCT url, concurrently -----------------------
$statusOf = checkUrlsConcurrently(array_keys($distinctUrls), $concurrency);

// --- write output ---------------------------------------------------------------
$outPath = $dir . '/description_image_audit.csv';
$fh = fopen($outPath, 'w');
fputcsv($fh, ['item_id', 'position', 'src', 'alt', 'alt_status', 'http_status', 'broken']);

$brokenCount = 0; $missingAlt = 0; $genericAlt = 0;
foreach ($rows as $r) {
    $altTrim = trim($r['alt']);
    if ($altTrim === '') {
        $altStatus = 'missing'; $missingAlt++;
    } elseif (in_array(mb_strtolower($altTrim), $GENERIC_ALTS, true)) {
        $altStatus = 'generic'; $genericAlt++;
    } else {
        $altStatus = 'ok';
    }

    $http = $statusOf[$r['src']] ?? 0;
    $broken = ($http < 200 || $http >= 400) ? 'yes' : 'no';
    if ($broken === 'yes') { $brokenCount++; }

    fputcsv($fh, [$r['item_id'], $r['position'], $r['src'], $r['alt'], $altStatus, $http, $broken]);
}
fclose($fh);

printf("broken images: %d | missing alt: %d | generic/placeholder alt: %d\n", $brokenCount, $missingAlt, $genericAlt);
echo "  {$outPath}\n";

// --- helpers -----------------------------------------------------------------------

/** @return list<array{0:string,1:string}> [src, alt] pairs, in document order */
function extractImgTags(string $html): array
{
    $out = [];
    if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags)) { return $out; }
    foreach ($tags[0] as $tag) {
        $src = '';
        if (preg_match('/\bsrc\s*=\s*"([^"]*)"/i', $tag, $m) || preg_match("/\\bsrc\\s*=\\s*'([^']*)'/i", $tag, $m)) {
            $src = html_entity_decode($m[1], ENT_QUOTES);
        }
        if ($src === '') { continue; } // nothing to check without a src
        $alt = '';
        if (preg_match('/\balt\s*=\s*"([^"]*)"/i', $tag, $m) || preg_match("/\\balt\\s*=\\s*'([^']*)'/i", $tag, $m)) {
            $alt = html_entity_decode($m[1], ENT_QUOTES);
        }
        $out[] = [$src, $alt];
    }
    return $out;
}

/**
 * HEAD-check a list of URLs concurrently via curl_multi (rolling window of
 * $concurrency in flight at once). Falls back to a ranged GET (0-0 bytes) if a
 * host rejects HEAD (some S3 buckets return 403/405 on HEAD but allow GET).
 *
 * @param list<string> $urls
 * @return array<string,int> url -> HTTP status (0 = unreachable/timeout)
 */
function checkUrlsConcurrently(array $urls, int $concurrency): array
{
    $status  = [];
    $queue   = $urls;
    $total   = count($urls);
    $done    = 0;
    $mh      = curl_multi_init();
    $urlOf   = []; // curl handle id -> url

    $spawn = function (string $url) use ($mh, &$urlOf) {
        // authored HTML sometimes has literal, unescaped spaces in the src (seen
        // in real data) -- curl rejects those as a malformed URL (exit 3) even
        // though the resource itself is fine once %-encoded. Encode just the
        // space; leave already-valid %XX / query syntax alone.
        $ch = curl_init(str_replace(' ', '%20', $url));
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ImageAudit/1.0)',
        ]);
        curl_multi_add_handle($mh, $ch);
        $urlOf[(int) $ch] = $url;
    };

    for ($i = 0; $i < $concurrency && $queue !== []; $i++) {
        $spawn(array_shift($queue));
    }

    $active = null;
    do {
        do { $mrc = curl_multi_exec($mh, $active); } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        if ($active && curl_multi_select($mh) === -1) { usleep(10000); }

        while (($info = curl_multi_info_read($mh)) !== false) {
            $ch   = $info['handle'];
            $url  = $urlOf[(int) $ch] ?? '';
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 0 || $code === 405 || $code === 403) {
                $code2 = rangedGetStatus($url);
                if ($code2 > 0) { $code = $code2; }
            }
            $status[$url] = $code;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($urlOf[(int) $ch]);
            $done++;
            if ($done % 200 === 0) { echo "  checked {$done}/{$total} urls\n"; }

            if ($queue !== []) { $spawn(array_shift($queue)); $active = 1; }
        }
    } while ($active > 0 || $queue !== []);

    curl_multi_close($mh);
    return $status;
}

/** Ranged GET (0-0 bytes) fallback for hosts that reject/misreport HEAD. */
function rangedGetStatus(string $url): int
{
    $ch = curl_init(str_replace(' ', '%20', $url));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_RANGE          => '0-0',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ImageAudit/1.0)',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}
