<?php

declare(strict_types=1);

/**
 * analyze_descriptions.php — score the CURRENT eBay description of each listing
 * for SEO quality, so the rewrite step knows what to fix and we can prioritise.
 * Pure analysis: reads the media/ snapshots (audit_media.php) + items/ aspect
 * snapshots (enrich_listings.php). NO eBay calls, NO writes to eBay.
 *
 * Signals scored per listing (transparent, deterministic):
 *   - word_count        : stripped-text length (thin copy ranks poorly)
 *   - title_coverage    : % of meaningful title words present in the body
 *   - aspect_coverage   : how many key aspect VALUES (brand/type/material/size/
 *                         mpn/color) are mentioned in the body
 *   - has_bullets       : <li>/<ul> structure (scannable = better)
 *   - has_heading       : <h1-3> or bold lead-in
 *   - has_schema        : schema.org / itemprop / typeof=Product block present
 *   - keyword_in_intro  : a title keyword in the first ~120 chars
 *   - is_duplicate      : body text shared verbatim with other listings
 *                         (duplicate content is an SEO liability)
 * -> a 0–100 score + an issues[] list + a suggested treatment
 *    (copy_rewrite = keep template, improve copy; restructure = thin/no real
 *    body, rebuild; ok = already strong, low priority).
 *
 * Output (ebay/data/{account}/output/):
 *   description_audit.csv     one row/listing: score, every signal, issues, treatment
 *   desc_rewrite_tasks.jsonl  one line/listing feeding the rewrite step
 *                             (title, category, key aspects, current copy, issues)
 *
 * Usage: php ebay/scripts/analyze_descriptions.php --account=dows|ige
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['account:', 'help']);
if (isset($opts['help'])) { fwrite(STDOUT, "Usage: php analyze_descriptions.php --account=dows|ige\n"); exit(0); }
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$outDir  = ebay_dir($account, 'output');
$mediaDir = $outDir . '/media';
$itemDir  = $outDir . '/items';

if (!is_dir($mediaDir)) {
    fwrite(STDERR, "No media snapshots at {$mediaDir}. Run audit_media.php --account={$account} first.\n");
    exit(1);
}

// stopwords for title-keyword extraction
$STOP = array_flip(['the','a','an','and','or','for','with','of','to','in','on','set','new','pcs','pc','pack','x','by','from','your','you','&']);

// --- first pass: load every listing's stripped body + meta ---------------------
$listings = [];          // item_id => [...]
$textHashCount = [];      // normalized-text hash => count (duplicate detection)

foreach (glob($mediaDir . '/*.json') as $f) {
    $m = json_decode((string) file_get_contents($f), true);
    if (!is_array($m) || ($m['status'] ?? '') !== 'OK') { continue; }
    $id   = (string) $m['item_id'];
    $html = (string) ($m['description'] ?? '');
    $text = normaliseText($html);

    // aspects (for keyword coverage) from the Phase-1 snapshot if present
    $aspects = [];
    $ip = $itemDir . "/{$id}.json";
    if (is_file($ip)) {
        $it = json_decode((string) file_get_contents($ip), true);
        if (is_array($it) && is_array($it['aspects'] ?? null)) { $aspects = $it['aspects']; }
    }

    $hash = $text === '' ? '' : md5($text);
    if ($hash !== '') { $textHashCount[$hash] = ($textHashCount[$hash] ?? 0) + 1; }

    $listings[$id] = [
        'item_id'   => $id,
        'sku'       => '',
        'title'     => (string) ($m['title'] ?? ''),
        'price'     => (string) ($m['price'] ?? ''),
        'html'      => $html,
        'text'      => $text,
        'hash'      => $hash,
        'aspects'   => $aspects,
        'image_count' => count($m['images'] ?? []),
    ];
}

// pull sku from media_summary if we have it
$sumPath = $outDir . '/media_summary.csv';
if (is_file($sumPath)) {
    $fh = fopen($sumPath, 'r'); $h = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        $row = array_combine($h, $r);
        if (isset($listings[$row['item_id']])) { $listings[$row['item_id']]['sku'] = $row['sku']; }
    }
    fclose($fh);
}

// --- second pass: score --------------------------------------------------------
$audit = fopen($outDir . '/description_audit.csv', 'w');
fputcsv($audit, ['item_id','sku','title','price','score','treatment','word_count','title_coverage_pct',
    'aspect_hits','aspect_total','has_bullets','has_heading','has_schema','keyword_in_intro','is_duplicate','dup_group_size','issues']);

$tasks = fopen($outDir . '/desc_rewrite_tasks.jsonl', 'w');

$stat = ['ok' => 0, 'copy_rewrite' => 0, 'restructure' => 0, 'tier2_tweak' => 0];
$scoreSum = 0; $n = 0;

foreach ($listings as $id => $L) {
    $text = $L['text'];
    $words = $text === '' ? 0 : str_word_count($text);

    // title keyword coverage
    $kw = titleKeywords($L['title'], $GLOBALS['STOP']);
    $hits = 0;
    $lcText = mb_strtolower($text);
    foreach ($kw as $w) { if ($w !== '' && mb_strpos($lcText, $w) !== false) { $hits++; } }
    $titleCov = $kw === [] ? 100 : (int) round(100 * $hits / count($kw));

    // aspect value coverage (key aspects only)
    $keyAsp = ['Brand','Type','Material','Size','MPN','Color','Model','Department','Style'];
    $aspVals = [];
    foreach ($keyAsp as $k) {
        foreach ($L['aspects'] as $an => $av) {
            if (mb_stripos($an, $k) !== false && trim((string) $av) !== '') { $aspVals[mb_strtolower(trim((string) $av))] = true; }
        }
    }
    $aspTotal = count($aspVals); $aspHits = 0;
    foreach (array_keys($aspVals) as $av) { $av = (string) $av; if ($av !== '' && mb_strpos($lcText, $av) !== false) { $aspHits++; } }

    $html = $L['html'];
    $hasBullets = (bool) preg_match('/<(li|ul)\b/i', $html);
    $hasHeading = (bool) preg_match('/<(h[1-3]|b|strong)\b/i', $html);
    $hasSchema  = (bool) preg_match('/schema\.org|itemprop=|typeof=["\']?Product/i', $html);
    $intro      = mb_substr($text, 0, 120);
    $kwInIntro  = false;
    foreach ($kw as $w) { if ($w !== '' && mb_stripos($intro, $w) !== false) { $kwInIntro = true; break; } }
    $dupGroup   = $L['hash'] === '' ? 0 : ($textHashCount[$L['hash']] ?? 1);
    $isDup      = $dupGroup > 1;

    // --- composite score (0–100) ---
    $score = 0;
    $score += min(30, (int) round($words / 200 * 30));          // up to 30 for ~200+ words
    $score += (int) round($titleCov * 0.20);                     // up to 20 for title coverage
    $score += $aspTotal ? (int) round(($aspHits / $aspTotal) * 15) : 8; // up to 15 aspect coverage
    $score += $hasBullets ? 12 : 0;
    $score += $hasHeading ? 6 : 0;
    $score += $hasSchema ? 7 : 0;
    $score += $kwInIntro ? 5 : 0;
    $score += $isDup ? 0 : 5;                                    // unique body bonus
    $score = max(0, min(100, $score));

    // --- issues + treatment ---
    $issues = [];
    if ($words < 80)            { $issues[] = 'very thin (<80 words)'; }
    elseif ($words < 200)       { $issues[] = 'thin (<200 words)'; }
    if ($titleCov < 60)         { $issues[] = "low title-keyword coverage ({$titleCov}%)"; }
    if ($aspTotal && $aspHits === 0) { $issues[] = 'no key aspects mentioned'; }
    if (!$hasBullets)           { $issues[] = 'no bullet structure'; }
    if (!$kwInIntro)            { $issues[] = 'no keyword in intro'; }
    if (!$hasSchema)            { $issues[] = 'no schema markup'; }
    if ($isDup)                 { $issues[] = "duplicate body (shared by {$dupGroup} listings)"; }

    $treatment = $words < 80 ? 'restructure' : ($score >= 78 && !$isDup ? 'ok' : 'copy_rewrite');
    // tier-2: an otherwise-good listing that still misses an intro keyword or
    // never names a key aspect — surgical tweak rather than a full rewrite.
    if ($treatment === 'ok' && (!$kwInIntro || ($aspTotal && $aspHits === 0))) {
        $treatment = 'tier2_tweak';
    }
    $stat[$treatment] = ($stat[$treatment] ?? 0) + 1;
    $scoreSum += $score; $n++;

    fputcsv($audit, [$id, $L['sku'], $L['title'], $L['price'], $score, $treatment, $words, $titleCov,
        $aspHits, $aspTotal, $hasBullets ? 'yes':'no', $hasHeading ? 'yes':'no', $hasSchema ? 'yes':'no',
        $kwInIntro ? 'yes':'no', $isDup ? 'yes':'no', $dupGroup, implode('; ', $issues)]);

    if ($treatment !== 'ok') {
        // compact aspect map for the rewrite prompt
        $aspectsOut = [];
        foreach ($L['aspects'] as $an => $av) { if (trim((string) $av) !== '') { $aspectsOut[$an] = $av; } }
        fwrite($tasks, json_encode([
            'item_id'     => $id,
            'sku'         => $L['sku'],
            'title'       => $L['title'],
            'price'       => $L['price'],
            'treatment'   => $treatment,
            'aspects'     => $aspectsOut,
            'current_text' => mb_substr($text, 0, 4000),
            'issues'      => $issues,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
fclose($audit);
fclose($tasks);

echo "=== description audit: {$account} ===\n";
printf("listings scored: %d | avg score %.1f/100\n", $n, $n ? $scoreSum / $n : 0);
printf("  ok (leave alone):         %d\n", $stat['ok']);
printf("  copy_rewrite (tier-1):    %d\n", $stat['copy_rewrite']);
printf("  restructure (tier-1):     %d\n", $stat['restructure']);
printf("  tier2_tweak (tier-2):     %d\n", $stat['tier2_tweak']);
printf("rewrite tasks queued: %d\n", $stat['copy_rewrite'] + $stat['restructure'] + $stat['tier2_tweak']);
echo "  {$outDir}/description_audit.csv\n  {$outDir}/desc_rewrite_tasks.jsonl\n";

// --- helpers -------------------------------------------------------------------

/** Strip HTML/scripts/hidden blocks to comparable plain text. */
function normaliseText(string $html): string
{
    if ($html === '') { return ''; }
    // drop script/style and HTML comments (mobile blocks are often commented markers)
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = preg_replace('/<!--.*?-->/s', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string) $text);
}

/** Meaningful lowercased title words (no stopwords, length>2, dedup, keep order). */
function titleKeywords(string $title, array $stop): array
{
    $title = mb_strtolower($title);
    $title = preg_replace('/[^a-z0-9\s\-]/u', ' ', $title);
    $out = [];
    foreach (preg_split('/[\s\-]+/', $title) as $w) {
        $w = trim($w);
        if ($w === '' || mb_strlen($w) < 3 || isset($stop[$w]) || ctype_digit($w)) { continue; }
        $out[$w] = true;
    }
    return array_keys($out);
}
