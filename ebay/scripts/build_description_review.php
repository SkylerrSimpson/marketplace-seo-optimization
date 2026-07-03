<?php

declare(strict_types=1);

/**
 * build_description_review.php — assemble the DRY-RUN description review sheet for
 * Ethan (v2, 21-column schema, 2026-07-03). NO eBay writes.
 *
 * Two-description model (matches ebay/tools/description-generator.html):
 *   FIRST  description = factual paragraph  (size / color / brand / material / what's
 *                        included) -> the intro <p> after the H2.
 *   SECOND description = sales pitch        (why you should buy it) -> the <p> at the
 *                        bottom, just above the footer.
 *   KEY FEATURES        = the factual LABEL: detail bullets.
 *   PRODUCT SPECS       = auto-rendered from the listing's own aspects.
 *   MOBILE summary      = the hidden, eBay-required <=800-char summary (human-readable,
 *                        never an MPN/part number).
 *   TITLE               = touched ONLY when the authoring pass flagged the current
 *                        title as inaccurate/deficient (title_issue=true); otherwise
 *                        left as-is (new_title blank).
 *
 * Inputs:
 *   desc_source_pack.jsonl   per-listing GROUNDING content (extract_description_source.py):
 *     {item_id, title, price, aspects, short_description, narrative[], feature_bullets[], image}
 *     aspects here are the MERGED apply_set.json set when available (see that script).
 *   desc_authored.jsonl      the LLM re-author answers, grounded in the pack:
 *     {item_id, factual, sales, bullets:[...], mobile[, title_issue, new_title]}
 *   media/{itemId}.json      current description HTML (the BEFORE column)
 *   listings.json            item_id -> sku (the authoritative sku source)
 *
 * Where a listing has no authored answer it falls back to its own source pack so the
 * sheet still covers every listing without inventing copy (title is never touched in
 * that case — title_issue only ever comes from an authored answer).
 *
 * Writes:
 *   description_review.csv        one row/listing, 21 columns (see column list below)
 *   descriptions/{itemId}.html    the full proposed HTML (easy to eyeball)
 *
 * Usage: php ebay/scripts/build_description_review.php --account=dows|ige
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['account:', 'help']);
if (isset($opts['help'])) { fwrite(STDOUT, "Usage: php build_description_review.php --account=dows|ige\n"); exit(0); }
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$outDir  = ebay_dir($account, 'output');
$mediaDir = $outDir . '/media';
$descDir  = $outDir . '/descriptions';
if (!is_dir($descDir)) { mkdir($descDir, 0775, true); }

// Store directory — drives the header brand + Our Store / Contact Us links and footer,
// exactly like the generator's dropdown. Slugs verified from the live listing HTML.
$STORES = [
    'dows' => ['brand' => 'Deals Only Webstore',  'slug' => 'dealsonlywebstore'],
    'ige'  => ['brand' => 'Irongate Enterprises', 'slug' => 'irongateamericansupply'],
];
$store = $STORES[$account] ?? $STORES['dows'];

$packPath = $outDir . '/desc_source_pack.jsonl';
if (!is_file($packPath)) {
    fwrite(STDERR, "No source packs at {$packPath}. Run extract_description_source.py first.\n");
    exit(1);
}

// authored answers (the LLM re-author pass), keyed by item_id
$authored = [];
$authPath = $outDir . '/desc_authored.jsonl';
if (is_file($authPath)) {
    foreach (file($authPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $a = json_decode($line, true);
        if (is_array($a) && ($a['item_id'] ?? '') !== '') { $authored[(string) $a['item_id']] = $a; }
    }
}

// item_id -> sku, from listings.json (the authoritative source — media/items snapshots
// don't reliably carry sku)
$skuMap = sourceListingSkuMap($outDir . '/listings.json');

$oneLine = fn(string $s): string => trim(preg_replace('/\s+/u', ' ', $s));

$rev = fopen($outDir . '/description_review.csv', 'w');
fputcsv($rev, [
    'item_id', 'sku', 'old_first_description', 'new_first_description',
    'old_title', 'new_title', 'price', 'old_key_features', 'new_key_features',
    'old_mobile_text', 'new_mobile_text', 'old_description', 'new_description',
    'prev_html', 'new_html', 'what_changed', 'approved', 'reviewer_notes',
    'mpn', 'upc', 'specs/values',
]);

$total = 0; $authoredCount = 0; $fallbackCount = 0; $titleChanged = 0; $titleRejected = 0;
foreach (file($packPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $pack = json_decode($line, true);
    if (!is_array($pack)) { continue; }
    $id      = (string) ($pack['item_id'] ?? '');
    if ($id === '') { continue; }

    $title    = (string) ($pack['title'] ?? '');
    $price    = (string) ($pack['price'] ?? '');
    $aspects  = is_array($pack['aspects'] ?? null) ? $pack['aspects'] : [];
    $imageUrl = (string) ($pack['image'] ?? '');
    $sku      = $skuMap[$id] ?? '';

    $media       = readJson($mediaDir . "/{$id}.json");
    $currentHtml = (string) ($media['description'] ?? '');

    // --- OLD/current columns (always derived the same way, authored answer or not) ---
    $oldTitle = stripIdentifiers($oneLine($title));
    $oldFirst = stripIdentifiers($oneLine((string) ($pack['short_description'] ?? '')));
    $oldBullets = cleanBullets((array) ($pack['feature_bullets'] ?? []));
    $oldMobile  = extractMobileSpan($currentHtml);

    $a = $authored[$id] ?? null;
    $newTitle = '';
    if ($a !== null && trim((string) ($a['factual'] ?? '')) !== '') {
        // LLM re-author answer, grounded in the source pack
        $factual = stripIdentifiers($oneLine((string) ($a['factual'] ?? '')));
        $sales   = stripIdentifiers($oneLine((string) ($a['sales'] ?? '')));
        $bullets = cleanBullets((array) ($a['bullets'] ?? []));
        $mobile  = stripIdentifiers($oneLine((string) ($a['mobile'] ?? '')));
        if ($mobile === '') { $mobile = $factual; }
        $titleNote = '';
        if (!empty($a['title_issue'])) {
            $cand = stripIdentifiers($oneLine((string) ($a['new_title'] ?? '')));
            if ($cand === '') {
                // flagged but no replacement given — nothing to apply
            } elseif (mb_strlen($cand) > 80) {
                fwrite(STDERR, "warn: item {$id} new_title exceeds 80 chars ({$cand}) — dropped, title left as-is\n");
                $titleRejected++;
            } else {
                $newTitle = $cand;
                $titleChanged++;
            }
        }
        $change  = 'Re-authored (factual + sales) + standardized' . ($newTitle !== '' ? '; title updated' : '');
        $summary = 'Re-authored into the two-paragraph standard: a factual first paragraph '
            . '(size/brand/material/contents) and a sales-pitch second paragraph, with the '
            . 'factual Key Features restored from the original. Grounded in the listing; nothing invented.'
            . ($newTitle !== '' ? ' Title flagged inaccurate/deficient and replaced.' : '');
        $authoredCount++;
    } else {
        // fallback: reuse the listing's own content (no authoring), still standardized.
        // Title is NEVER touched in the fallback path — title_issue only comes from an
        // authored answer.
        $factual = $oldFirst;
        $narr    = array_map('strval', (array) ($pack['narrative'] ?? []));
        $sales   = stripIdentifiers($oneLine($narr[0] ?? ''));
        // don't duplicate: if the sales prose is contained in the factual lead, drop it
        if ($sales !== '' && $factual !== '' && mb_stripos($factual, mb_substr($sales, 0, 40)) !== false) {
            $sales = stripIdentifiers($oneLine($narr[1] ?? ''));
        }
        if ($factual === '') { $factual = $sales; $sales = stripIdentifiers($oneLine($narr[1] ?? '')); }
        if ($factual === '') { $factual = $title; }
        $bullets = $oldBullets;
        $mobile  = $factual;
        $change  = 'Standardized (copy kept; pending re-author)';
        $summary = 'No authored answer yet — reused the listing\'s own factual summary, narrative '
            . 'and feature bullets, re-rendered in the standard two-paragraph template.';
        $fallbackCount++;
    }

    $mobile   = mobileSummary($mobile);
    $newTitleFinal = $newTitle !== '' ? $newTitle : $title;
    $proposed = renderFull($store, $newTitleFinal, $factual, $sales, $mobile, $bullets, $aspects, $imageUrl);
    file_put_contents($descDir . "/{$id}.html", $proposed);

    $newKeyFeatures = implode(' | ', $bullets);
    $newDescText    = $oneLine($factual . ($sales !== '' ? ' ' . $sales : '')
                    . ($bullets !== [] ? ' Key Features: ' . $newKeyFeatures : ''));

    fputcsv($rev, [
        $id, $sku,
        $oldFirst, $factual,
        $oldTitle, $newTitle,
        $price,
        implode(' | ', $oldBullets), $newKeyFeatures,
        $oldMobile, extractMobileSpan($proposed),
        $oneLine(strip_tags($currentHtml)), $newDescText,
        $oneLine($currentHtml), $oneLine($proposed),
        $change, '', '',
        findAspectCI($aspects, ['mpn']), findAspectCI($aspects, ['upc']),
        renderSpecsPlain($aspects),
    ]);
    $total++;
}
fclose($rev);

echo "=== description review built: {$account} ===\n";
printf("total listings: %d  (authored %d, fallback %d)\n", $total, $authoredCount, $fallbackCount);
printf("titles changed: %d  (rejected for >80 chars: %d)\n", $titleChanged, $titleRejected);
echo "  {$outDir}/description_review.csv\n  {$descDir}/{itemId}.html\n";

// --- renderers -----------------------------------------------------------------

/**
 * THE canonical description template — byte-for-byte what ebay/tools/description-
 * generator.html emits. One schema.org/Product block:
 *   hidden mobile description (eBay-required mobile summary, display:none)
 *   store header (brand + Our Store / Contact Us links)
 *   keyword H2
 *   -> FIRST/factual intro <p>
 *   -> Key Features (Label: detail bolds the label)
 *   -> main image
 *   -> Product Specifications (from aspects)
 *   -> SECOND/sales <p>
 *   -> store footer.
 */
function renderFull(array $store, string $title, string $factual, string $sales,
    string $mobile, array $bullets, array $aspects, string $imageUrl): string
{
    $h      = esc($title);
    $mob    = esc(trim(preg_replace('/\s+/u', ' ', $mobile)));
    $introP = $factual !== '' ? '  <p>' . esc($factual) . "</p>\n" : '';
    $salesP = $sales   !== '' ? '  <p>' . esc($sales)   . "</p>\n" : '';

    // Key Features — "Label: detail" bolds the label (matches the generator).
    $features = '';
    if ($bullets !== []) {
        $li = '';
        foreach ($bullets as $b) {
            $b = trim(preg_replace('/\s+/u', ' ', (string) $b));
            if ($b === '') { continue; }
            $pos = mb_strpos($b, ':');
            if ($pos !== false && $pos > 0) {
                $li .= '    <li><strong>' . esc(mb_substr($b, 0, $pos)) . ':</strong> '
                    . esc(trim(mb_substr($b, $pos + 1))) . "</li>\n";
            } else {
                $li .= '    <li>' . esc($b) . "</li>\n";
            }
        }
        if ($li !== '') {
            $features = "  <h3 style=\"font-size:18px;margin:18px 0 8px\">Key Features</h3>\n"
                . "  <ul style=\"padding-left:20px;margin:0 0 16px\">\n{$li}  </ul>\n";
        }
    }

    $imageHtml = $imageUrl !== ''
        ? '  <p style="text-align:center;margin:0 0 16px"><img src="' . esc($imageUrl)
            . '" alt="' . $h . '" style="max-width:100%;width:600px;height:auto"></p>' . "\n"
        : '';
    $specs   = renderSpecs($aspects);
    $brand   = esc($store['brand']);
    $storeUrl   = esc('https://www.ebay.com/str/' . $store['slug']);
    $contactUrl = esc('https://www.ebay.com/cnt/intermediatedFAQ?requested=' . $store['slug']);
    $year    = date('Y');

    return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:800px;margin:0 auto" vocab="https://schema.org/" typeof="Product">
  <div style="display:none"><span property="description">{$mob}</span></div>
  <div style="background:#f5f5f5;padding:12px 15px;border:1px solid #e5e5e5;margin:0 0 16px">
    <div style="font-size:18px;font-weight:bold;margin-bottom:6px">{$brand}</div>
    <div style="font-size:13px">
      <a href="{$storeUrl}" style="margin-right:15px;text-decoration:none;color:#333">Our Store</a>
      <a href="{$contactUrl}" style="text-decoration:none;color:#333">Contact Us</a>
    </div>
  </div>
  <h2 style="font-size:22px;margin:0 0 12px">{$h}</h2>
{$introP}{$features}{$imageHtml}{$specs}{$salesP}  <p style="margin:18px 0 0;padding-top:12px;border-top:1px solid #e5e5e5;font-size:13px;color:#777;text-align:center">&copy; {$year} {$brand}</p>
</div>
HTML;
}

/**
 * The eBay mobile summary: tag-free plain text. eBay counts the characters in the span
 * SOURCE — i.e. AFTER HTML-escaping (&#039; is six characters) — caps at 800, and drops
 * the remainder. Trim whole words until the ESCAPED text fits 800.
 */
function mobileSummary(string $intro): string
{
    $t = stripIdentifiers(trim(preg_replace('/\s+/u', ' ', $intro)));
    if (escLen($t) <= 800) { return $t; }
    $words = explode(' ', $t);
    while ($words !== [] && escLen(implode(' ', $words)) > 800) { array_pop($words); }
    return rtrim(implode(' ', $words));
}

/** Length of a string once HTML-escaped — what eBay actually counts in the span. */
function escLen(string $s): int { return mb_strlen(esc($s)); }

/** A "Product Specifications" list rendered from the listing's own aspects. */
function renderSpecs(array $aspects): string
{
    $skip = ['california prop 65 warning', 'unit type', 'unit quantity', 'sku'];
    $rows = '';
    foreach ($aspects as $name => $val) {
        $val = trim((string) (is_array($val) ? implode(', ', $val) : $val));
        if ($val === '' || in_array(mb_strtolower((string) $name), $skip, true)) { continue; }
        $rows .= '    <li><strong>' . esc((string) $name) . ':</strong> ' . esc($val) . "</li>\n";
    }
    if ($rows === '') { return ''; }
    return "  <h3 style=\"font-size:18px;margin:18px 0 8px\">Product Specifications</h3>\n"
        . "  <ul style=\"padding-left:20px;margin:0 0 16px\">\n{$rows}  </ul>\n";
}

/** Plain-text sibling of renderSpecs(), for the CSV's specs/values column. mpn/upc are
 *  skipped — they already get dedicated columns. */
function renderSpecsPlain(array $aspects): string
{
    $skip = ['california prop 65 warning', 'unit type', 'unit quantity', 'sku', 'mpn', 'upc'];
    $parts = [];
    foreach ($aspects as $name => $val) {
        $val = trim((string) (is_array($val) ? implode(', ', $val) : $val));
        if ($val === '' || in_array(mb_strtolower((string) $name), $skip, true)) { continue; }
        $parts[] = trim((string) $name) . ': ' . $val;
    }
    return implode(' | ', $parts);
}

/** Case-insensitive aspect lookup — used for mpn/upc. */
function findAspectCI(array $aspects, array $keys): string
{
    $lower = [];
    foreach ($aspects as $k => $v) { $lower[mb_strtolower(trim((string) $k))] = $v; }
    foreach ($keys as $k) {
        if (isset($lower[$k]) && trim((string) $lower[$k]) !== '') {
            $v = $lower[$k];
            return trim((string) (is_array($v) ? implode(', ', $v) : $v));
        }
    }
    return '';
}

/** item_id -> sku, from listings.json — the authoritative sku source (media/items
 *  snapshots don't reliably carry it). Mirrors build_review_sheet.php's $pSku load. */
function sourceListingSkuMap(string $listingsPath): array
{
    $map = [];
    if (!is_file($listingsPath)) { return $map; }
    $list = json_decode((string) file_get_contents($listingsPath), true);
    foreach ((array) $list as $l) {
        $id = (string) ($l['item_id'] ?? '');
        if ($id !== '') { $map[$id] = (string) ($l['sku'] ?? ''); }
    }
    return $map;
}

/** The hidden eBay mobile summary: the schema.org <span property="description"> inside
 *  the display:none block, tag-stripped to plain text. Same regex as
 *  find_mobile_desc_mismatch.py's MOBILE_SPAN, ported here so old_mobile_text/
 *  new_mobile_text can be pulled straight from raw HTML (current or newly rendered)
 *  without a separate extraction pass. */
function extractMobileSpan(string $html): string
{
    if ($html === '' || !preg_match('/property="description"[^>]*>(.*?)<\/span>/si', $html, $m)) {
        return '';
    }
    $t = strip_tags($m[1]);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
}

/** Clean + join a raw feature_bullets list the same way for old and new columns. */
function cleanBullets(array $raw): array
{
    return array_values(array_filter(array_map(
        fn($b) => stripIdentifiers(trim(preg_replace('/\s+/u', ' ', (string) $b))), $raw),
        fn($b) => $b !== ''));
}

/**
 * Remove identifier labels + their values (MPN/UPC/SKU/EAN/GTIN/ISBN/Part No) from
 * human-readable copy — machine codes are gibberish in a description and never wanted
 * in the mobile summary (Ethan, 2026-06-23). They remain in Product Specifications.
 */
function stripIdentifiers(string $t): string
{
    $t = preg_replace(
        '/\b(MPN|UPC|EAN|GTIN|SKU|ISBN|Part\s*(?:No\.?|Number|#))\b\s*[:#]?\s*'
        . '[A-Za-z0-9][A-Za-z0-9._\/-]*\.?/i', ' ', $t);
    return trim(preg_replace('/\s+/u', ' ', (string) $t));
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function readJson(string $p): array { return is_file($p) ? (json_decode((string) file_get_contents($p), true) ?: []) : []; }
