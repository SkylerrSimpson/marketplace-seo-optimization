<?php

declare(strict_types=1);

/**
 * merge_legacy_template.php — non-lossy port of legacy DOWS/IGE Bootstrap-template
 * descriptions onto the new standardized template, with ZERO content rewriting.
 *
 * For each listing: decomposes its old description into the same factual/sales/
 * bullets/image/mpn/upc fields the new template expects (marketplaces/ebay/scripts/lib/
 * legacy_template.php — see that file's docblock for the three content-loss bugs
 * this design fixes), renders it through the REAL renderFull() (the exact function
 * every other listing's description goes through — required from
 * build_description_review.php in library mode, not a duplicated copy), then runs a
 * word-level non-lossy audit before accepting the result.
 *
 * Every listing is classified before being touched:
 *   LEGACY        confidently the known Bootstrap shape -> decomposed + rendered.
 *                 Only ACCEPTED if the non-lossy audit passes; otherwise downgraded
 *                 to NEEDS_REVIEW and left untouched.
 *   ALREADY_NEW   already has the new template's own header marker (e.g. the 5
 *                 items already pushed live in earlier sessions) -> left alone,
 *                 nothing to do.
 *   UNRECOGNIZED  matches neither known shape -> left alone, logged for manual
 *                 review. This script NEVER guesses on a shape it hasn't verified.
 *
 * This script does NOT push anything to eBay and does NOT touch the `approved`
 * column (or any other column besides new_first_description/new_sales_description/
 * new_key_features/new_title/new_mobile_text/new_description/new_html/
 * what_changed) in description_review.csv — same surgical-patch discipline used
 * all night: preserves whatever's already in `approved` for every row, touched or
 * not. Actually publishing is apply_descriptions.php's job, unchanged, with its own
 * approval-gate + verify/live/confirm=WRITE safety model.
 *
 * Usage:
 *   php marketplaces/ebay/scripts/merge_legacy_template.php --account=dows --dry-run
 *       Classify + decompose + render + audit every listing, write NOTHING. Prints
 *       a summary and a full per-item report CSV. Safe to run any time.
 *   php marketplaces/ebay/scripts/merge_legacy_template.php --account=dows --exclude=path/to/ids.txt
 *       Same, but for every ACCEPTED row, writes new_html/etc. into
 *       description_review.csv and descriptions/{id}.html. Still does not touch
 *       `approved` -- fill that yourself (or pass --mark-approved) before running
 *       apply_descriptions.php.
 *   php marketplaces/ebay/scripts/merge_legacy_template.php --account=dows --item=ID
 *       Single item, prints the full decomposition + rendered HTML + audit result.
 *   Add --mark-approved to also set approved=yes on every ACCEPTED row (opt-in;
 *   default leaves `approved` exactly as it already was).
 */

require_once __DIR__ . '/../../lib/bootstrap.php';
define('DESC_REVIEW_LIB_ONLY', true);
require_once __DIR__ . '/build_description_review.php'; // renderFull(), storeForAccount(), isGearAid(), PROP65_*, stripIdentifiers(), mobileSummary(), imageAltText()
require_once __DIR__ . '/lib/legacy_template.php';

$opts = getopt('', ['account:', 'dry-run', 'exclude:', 'item:', 'items:', 'mark-approved', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php merge_legacy_template.php --account=dows|ige [--dry-run] [--exclude=file] [--item=ID] [--items=ID1,ID2] [--mark-approved]\n");
    exit(0);
}
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dryRun  = isset($opts['dry-run']);
$markApproved = isset($opts['mark-approved']);
$outDir  = ebay_dir($account, 'output');
$mediaDir = $outDir . '/media';
$descDir  = $outDir . '/descriptions';
$store = storeForAccount($account);

$exclude = [];
if (isset($opts['exclude'])) {
    $path = (string) $opts['exclude'];
    if (!is_file($path)) { fwrite(STDERR, "exclude file not found: {$path}\n"); exit(1); }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $id = trim($line);
        if ($id !== '') { $exclude[$id] = true; }
    }
}

// aspects + real eBay title, per item -- loaded once, not re-read per listing
$aspectsByItem = [];
$packPath = $outDir . '/desc_source_pack.jsonl';
if (is_file($packPath)) {
    foreach (file($packPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $p = json_decode($line, true);
        if (is_array($p) && ($p['item_id'] ?? '') !== '') {
            $aspectsByItem[(string) $p['item_id']] = is_array($p['aspects'] ?? null) ? $p['aspects'] : [];
        }
    }
}
$titleByItem = [];
$reviewPath = $outDir . '/description_review.csv';
if (is_file($reviewPath)) {
    $fh = fopen($reviewPath, 'r');
    $header = fgetcsv($fh);
    $idx = array_flip($header);
    while (($r = fgetcsv($fh)) !== false) {
        $id = $r[$idx['item_id']] ?? '';
        if ($id !== '') { $titleByItem[$id] = $r[$idx['old_title']] ?? ''; }
    }
    fclose($fh);
}

/**
 * Rule: limit the Product Specifications section to
 * MPN, UPC, Size, Color -- only whichever of those actually exist -- and never show
 * a Size of "Standard" (not a meaningful value to a buyer). This is scoped to the
 * legacy-template merge only; it does not change renderFull()'s normal behavior for
 * freshly-authored descriptions elsewhere in the pipeline.
 */
function filterSpecsForMerge(array $aspects): array
{
    $allowed = ['mpn', 'upc', 'size', 'color'];
    $out = [];
    foreach ($aspects as $k => $v) {
        $kLower = mb_strtolower(trim((string) $k));
        if (!in_array($kLower, $allowed, true)) { continue; }
        if ($kLower === 'size' && mb_strtolower(trim((string) $v)) === 'standard') { continue; }
        $out[$k] = $v;
    }
    return $out;
}

function buildRendered(string $id, string $account, array $store, array $titleByItem, array $aspectsByItem, string $mediaDir): array
{
    $media = json_decode((string) file_get_contents("{$mediaDir}/{$id}.json"), true) ?: [];
    $raw = (string) ($media['description'] ?? '');
    $title = $titleByItem[$id] ?? (string) ($media['title'] ?? '');

    $shape = classifyDescriptionShape($raw);
    $decomposed = $shape === SHAPE_INTERMEDIATE ? decomposeIntermediate($raw) : decomposeLegacy($raw);
    $chrome = $decomposed['chrome'];
    $flags = $decomposed['flags'];

    // MPN/UPC: the OLD DESCRIPTION is the source of truth, no matter what --
    // Rule (replacing the earlier "live aspect wins" policy)
    // below (kept struck through in spirit, not in code, so the reasoning for the
    // reversal is on record). That earlier policy assumed eBay's live aspect was
    // the more trustworthy value when the two disagreed -- 234090739207 proved
    // that's not reliably true: its UPC differs because the listing is linked
    // into an eBay product-catalog entry (ebay.com/p/15021877377), which can
    // overwrite the seller's own aspect value with the catalog's, independent of
    // what's actually correct for this specific listing. So: never silently
    // resolve a conflict either direction -- always render what the old
    // description said, and only flag when it disagrees with the live aspect, so
    // a real, deliberate correction later is possible but never automatic.
    $aspects = $aspectsByItem[$id] ?? [];
    $currentMpn = null;
    $currentUpc = null;
    foreach ($aspects as $k => $v) {
        $kLower = mb_strtolower(rtrim((string) $k, ':'));
        if ($kLower === 'mpn') { $currentMpn = (string) $v; }
        if ($kLower === 'upc') { $currentUpc = (string) $v; }
    }
    if ($decomposed['mpn'] !== '') {
        $aspects['MPN'] = $decomposed['mpn'];
        if ($currentMpn !== null && mb_strtolower(trim($currentMpn)) !== mb_strtolower(trim($decomposed['mpn']))) {
            $flags[] = "MPN differs from the live eBay aspect: description says \"{$decomposed['mpn']}\", live aspect says \"{$currentMpn}\" -- kept the description's value (old description is source of truth).";
        }
    }
    if ($decomposed['upc'] !== '') {
        $aspects['UPC'] = $decomposed['upc'];
        if ($currentUpc !== null && mb_strtolower(trim($currentUpc)) !== mb_strtolower(trim($decomposed['upc']))) {
            $flags[] = "UPC differs from the live eBay aspect: description says \"{$decomposed['upc']}\", live aspect says \"{$currentUpc}\" -- kept the description's value (old description is source of truth).";
        }
    }
    $aspects = filterSpecsForMerge($aspects);
    // Custom specs the old description itself already carried (e.g. "Blade
    // Material: Zirconia Ceramic") bypass the mpn/upc/size/color-only filter
    // above -- that filter exists to cap how much of the *live* aspect set gets
    // pulled in, not to second-guess content already authored and put in
    // the description himself. Applied after filterSpecsForMerge() so it can
    // never get stripped by it. Rendered in Specifications, not Key Features
    // (2026-07-16 fix -- see decomposeIntermediate()'s docblock).
    foreach ($decomposed['extraSpecs'] ?? [] as $label => $value) {
        $aspects[$label] = $value;
    }

    // Rule: never change the main image
    // means never change it -- for a NON-variation listing that means keeping
    // whatever image is CURRENTLY in the description (decomposed from the old HTML),
    // not swapping in the live API's picture, which is usually but not always the
    // same image. Only fall back to the live picture if the old description had no
    // image at all to parse.
    //
    // Variation listings are the one case that motivated switching to a live source
    // in the first place (365879935283 had a stale, wrong-variant photo baked into
    // the shared description). But media/{id}.json's `images[0]` is NOT a reliable
    // stand-in for "the listing's actual main/backend image" -- confirmed on
    // 365816245798, where images[0] turned out to be literally the BLK child SKU's
    // own picture (Browse API's get_items_by_item_group has no real parent-gallery
    // concept; images[0] is just whichever child came back first), while the true
    // backend default (visible in Seller Hub) is a neutral 3-colors-
    // together shot we have no API access to (Trading GetItem is edge-blocked here;
    // Sell Inventory API's inventory_item_group is reachable but has no data for
    // these legacy Trading-API-created listings -- confirmed via a live 404 test).
    // Until there's a real source for that, keep the same "never touch it" behavior
    // for variation listings too, rather than substituting a guess that's already
    // been shown to be wrong at least once.
    $image = $decomposed['image'];
    if ($image === '') {
        $liveImages = $media['images'] ?? [];
        if (is_array($liveImages) && isset($liveImages[0]['url']) && $liveImages[0]['url'] !== '') {
            $image = (string) $liveImages[0]['url'];
            $flags[] = 'old description had no <img> tag to parse; fell back to the live picture list';
        }
    }

    $factual = stripIdentifiers($decomposed['factual']);
    $sales   = stripIdentifiers($decomposed['sales']);
    $bullets = array_values(array_filter(array_map('stripIdentifiers', $decomposed['bullets']), fn($b) => $b !== ''));
    $mobile  = mobileSummary($decomposed['mobile'] !== '' ? $decomposed['mobile'] : $factual);
    $altText = imageAltText($factual, $title);
    $showBadge = !isGearAid($title);

    $html = renderFull($store, $title, $factual, $sales, $mobile, $bullets, $aspects, $image, $altText, $showBadge);

    return [
        'raw' => $raw, 'html' => $html, 'title' => $title,
        'factual' => $factual, 'sales' => $sales, 'bullets' => $bullets,
        'chrome' => $chrome, 'flags' => $flags,
    ];
}

// --- single-item mode: print everything, write nothing ------------------------
if (isset($opts['item'])) {
    $id = (string) $opts['item'];
    $shape = classifyDescriptionShape((string) (json_decode((string) file_get_contents("{$mediaDir}/{$id}.json"), true)['description'] ?? ''));
    echo "shape: {$shape}\n\n";
    if ($shape !== SHAPE_LEGACY && $shape !== SHAPE_INTERMEDIATE) { exit(0); }
    $r = buildRendered($id, $account, $store, $titleByItem, $aspectsByItem, $mediaDir);
    $missing = nonLossyCheck($r['raw'], $r['html'], $r['chrome']);
    echo "=== rendered html ===\n{$r['html']}\n\n";
    echo "=== non-lossy audit: " . (empty($missing) ? 'CLEAN' : 'MISSING WORDS FOUND') . " ===\n";
    echo implode(' ', $missing) . "\n";
    echo "\n=== flags ===\n" . ($r['flags'] === [] ? '(none)' : implode("\n", $r['flags'])) . "\n";
    exit(0);
}

// --- full-catalog mode (or --items=ID1,ID2 for a small named subset, e.g. pushing
// a couple of test IDs through the same classify+audit+write path without touching
// the rest of the catalog) --------------------------------------------------------
$counts = [
    'legacy_ok' => 0, 'legacy_needs_review' => 0,
    'intermediate_ok' => 0, 'intermediate_needs_review' => 0,
    'already_new' => 0, 'unrecognized' => 0, 'excluded' => 0, 'error' => 0,
];
$report = [];
$toWrite = []; // item_id => html, for the CSV-patch pass at the end

if (isset($opts['items'])) {
    $files = array_map(
        fn(string $id) => "{$mediaDir}/{$id}.json",
        array_filter(array_map('trim', explode(',', (string) $opts['items'])))
    );
} else {
    $files = glob("{$mediaDir}/*.json");
}

foreach ($files as $f) {
    $id = basename($f, '.json');
    if (isset($exclude[$id])) { $counts['excluded']++; continue; }

    $media = json_decode((string) file_get_contents($f), true) ?: [];
    $raw = (string) ($media['description'] ?? '');
    $shape = classifyDescriptionShape($raw);

    if ($shape === SHAPE_ALREADY_NEW) { $counts['already_new']++; continue; }
    if ($shape === SHAPE_UNRECOGNIZED) {
        $counts['unrecognized']++;
        $report[] = [$id, 'unrecognized', $shape, '', '', ''];
        continue;
    }

    try {
        $r = buildRendered($id, $account, $store, $titleByItem, $aspectsByItem, $mediaDir);
        $missing = nonLossyCheck($r['raw'], $r['html'], $r['chrome']);
        $flagsStr = implode(' | ', $r['flags']);
        $okKey = $shape === SHAPE_INTERMEDIATE ? 'intermediate_ok' : 'legacy_ok';
        $reviewKey = $shape === SHAPE_INTERMEDIATE ? 'intermediate_needs_review' : 'legacy_needs_review';
        if (!empty($missing)) {
            $counts[$reviewKey]++;
            $report[] = [$id, 'needs_review', $shape, implode(' ', $missing), '', $flagsStr];
            continue;
        }
        $counts[$okKey]++;
        // 'flags' here is the "what got auto-handled" QA column
        // (2026-07-14): non-empty even on an 'ok' row means something unusual was
        // detected and worked around (malformed HTML, a dropped duplicate, an MPN/UPC
        // conflict resolved in favor of live data, etc.) -- not a reason to distrust
        // the row, just a pointer for spot-checking the full-catalog rollout.
        $report[] = [$id, 'ok', $shape, '', strlen($r['html']), $flagsStr];
        $toWrite[$id] = $r['html'];
    } catch (\Throwable $e) {
        $counts['error']++;
        $report[] = [$id, 'error', $shape, $e->getMessage(), '', ''];
    }
}

$reportPath = $outDir . '/merge_legacy_template_report.csv';
$rh = fopen($reportPath, 'w');
fputcsv($rh, ['item_id', 'status', 'shape', 'notes', 'html_bytes', 'flags']);
foreach ($report as $row) { fputcsv($rh, $row); }
fclose($rh);

echo "=== merge_legacy_template: {$account} " . ($dryRun ? '(DRY RUN -- no writes)' : '') . " ===\n";
foreach ($counts as $k => $v) { printf("%-22s %d\n", $k, $v); }
echo "\nreport: {$reportPath}\n";

if ($dryRun) {
    echo "\n(dry run -- nothing written. Re-run without --dry-run to write description_review.csv + descriptions/{id}.html for the " . $counts['legacy_ok'] . " clean rows.)\n";
    exit(0);
}

if (!$toWrite) { echo "\nnothing to write.\n"; exit(0); }

// surgical CSV patch: only new_first_description/new_sales_description/
// new_key_features/new_title/new_mobile_text/new_description/new_html/
// what_changed for the ACCEPTED rows -- `approved` and every other column and every
// other row are left byte-identical, same discipline as every live push tonight.
$fh = fopen($reviewPath, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);
$rows = [];
$written = 0;
while (($r = fgetcsv($fh)) !== false) {
    $id = $r[$idx['item_id']] ?? '';
    if (isset($toWrite[$id])) {
        $html = $toWrite[$id];
        $r[$idx['new_html']] = $html;
        $r[$idx['new_title']] = ''; // never rewritten by this script
        $r[$idx['what_changed']] = 'Non-lossy legacy-template merge (merge_legacy_template.php): '
            . 'decomposed old body into factual/sales/bullets/mpn/upc, rendered through the real '
            . 'template, zero content rewriting, word-level audit passed clean';
        if ($markApproved) { $r[$idx['approved']] = 'yes'; }
        file_put_contents("{$descDir}/{$id}.html", $html);
        $written++;
    }
    $rows[] = $r;
}
fclose($fh);
$fh = fopen($reviewPath, 'w');
fputcsv($fh, $header);
foreach ($rows as $r) { fputcsv($fh, $r); }
fclose($fh);

echo "\nwrote {$written} rows into description_review.csv + descriptions/{id}.html\n";
echo $markApproved
    ? "approved=yes set on all {$written} rows (--mark-approved was passed).\n"
    : "approved column left untouched -- fill it (or re-run with --mark-approved) before apply_descriptions.php.\n";
