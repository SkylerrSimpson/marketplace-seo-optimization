<?php

declare(strict_types=1);

/**
 * merge_legacy_template.php — non-lossy port of legacy DOWS/IGE Bootstrap-template
 * descriptions onto the new standardized template, with ZERO content rewriting.
 *
 * For each listing: decomposes its old description into the same factual/sales/
 * bullets/image/mpn/upc fields the new template expects (ebay/scripts/lib/
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
 *   php merge_legacy_template.php --account=dows --dry-run
 *       Classify + decompose + render + audit every listing, write NOTHING. Prints
 *       a summary and a full per-item report CSV. Safe to run any time.
 *   php merge_legacy_template.php --account=dows --exclude=path/to/ids.txt
 *       Same, but for every ACCEPTED row, writes new_html/etc. into
 *       description_review.csv and descriptions/{id}.html. Still does not touch
 *       `approved` -- fill that yourself (or pass --mark-approved) before running
 *       apply_descriptions.php.
 *   php merge_legacy_template.php --account=dows --item=ID
 *       Single item, prints the full decomposition + rendered HTML + audit result.
 *   Add --mark-approved to also set approved=yes on every ACCEPTED row (opt-in;
 *   default leaves `approved` exactly as it already was).
 */

require_once __DIR__ . '/../../lib/bootstrap.php';
define('DESC_REVIEW_LIB_ONLY', true);
require_once __DIR__ . '/build_description_review.php'; // renderFull(), storeForAccount(), isGearAid(), PROP65_*, stripIdentifiers(), mobileSummary(), imageAltText()
require_once __DIR__ . '/lib/legacy_template.php';

$opts = getopt('', ['account:', 'dry-run', 'exclude:', 'item:', 'mark-approved', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php merge_legacy_template.php --account=dows|ige [--dry-run] [--exclude=file] [--item=ID] [--mark-approved]\n");
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

function buildRendered(string $id, string $account, array $store, array $titleByItem, array $aspectsByItem, string $mediaDir): array
{
    $media = json_decode((string) file_get_contents("{$mediaDir}/{$id}.json"), true) ?: [];
    $raw = (string) ($media['description'] ?? '');
    $title = $titleByItem[$id] ?? (string) ($media['title'] ?? '');

    $decomposed = decomposeLegacy($raw);

    $aspects = $aspectsByItem[$id] ?? [];
    $aspectKeysLower = array_map('mb_strtolower', array_keys($aspects));
    if ($decomposed['mpn'] !== '' && !in_array('mpn', $aspectKeysLower, true)) { $aspects['MPN'] = $decomposed['mpn']; }
    if ($decomposed['upc'] !== '' && !in_array('upc', $aspectKeysLower, true)) { $aspects['UPC'] = $decomposed['upc']; }

    $factual = stripIdentifiers($decomposed['factual']);
    $sales   = stripIdentifiers($decomposed['sales']);
    $bullets = array_values(array_filter(array_map('stripIdentifiers', $decomposed['bullets']), fn($b) => $b !== ''));
    $mobile  = mobileSummary($decomposed['mobile'] !== '' ? $decomposed['mobile'] : $factual);
    $altText = imageAltText($factual, $title);
    $showBadge = !isGearAid($title);

    $html = renderFull($store, $title, $factual, $sales, $mobile, $bullets, $aspects, $decomposed['image'], $altText, $showBadge);

    return [
        'raw' => $raw, 'html' => $html, 'title' => $title,
        'factual' => $factual, 'sales' => $sales, 'bullets' => $bullets,
        'chrome' => $decomposed['chrome'],
    ];
}

// --- single-item mode: print everything, write nothing ------------------------
if (isset($opts['item'])) {
    $id = (string) $opts['item'];
    $shape = classifyDescriptionShape((string) (json_decode((string) file_get_contents("{$mediaDir}/{$id}.json"), true)['description'] ?? ''));
    echo "shape: {$shape}\n\n";
    if ($shape !== SHAPE_LEGACY) { exit(0); }
    $r = buildRendered($id, $account, $store, $titleByItem, $aspectsByItem, $mediaDir);
    $missing = nonLossyCheck($r['raw'], $r['html'], $r['chrome']);
    echo "=== rendered html ===\n{$r['html']}\n\n";
    echo "=== non-lossy audit: " . (empty($missing) ? 'CLEAN' : 'MISSING WORDS FOUND') . " ===\n";
    echo implode(' ', $missing) . "\n";
    exit(0);
}

// --- full-catalog mode ----------------------------------------------------------
$counts = ['legacy_ok' => 0, 'legacy_needs_review' => 0, 'already_new' => 0, 'unrecognized' => 0, 'excluded' => 0, 'error' => 0];
$report = [];
$toWrite = []; // item_id => html, for the CSV-patch pass at the end

foreach (glob("{$mediaDir}/*.json") as $f) {
    $id = basename($f, '.json');
    if (isset($exclude[$id])) { $counts['excluded']++; continue; }

    $media = json_decode((string) file_get_contents($f), true) ?: [];
    $raw = (string) ($media['description'] ?? '');
    $shape = classifyDescriptionShape($raw);

    if ($shape === SHAPE_ALREADY_NEW) { $counts['already_new']++; continue; }
    if ($shape === SHAPE_UNRECOGNIZED) {
        $counts['unrecognized']++;
        $report[] = [$id, 'unrecognized', '', ''];
        continue;
    }

    try {
        $r = buildRendered($id, $account, $store, $titleByItem, $aspectsByItem, $mediaDir);
        $missing = nonLossyCheck($r['raw'], $r['html'], $r['chrome']);
        if (!empty($missing)) {
            $counts['legacy_needs_review']++;
            $report[] = [$id, 'needs_review', implode(' ', $missing), ''];
            continue;
        }
        $counts['legacy_ok']++;
        $report[] = [$id, 'ok', '', strlen($r['html'])];
        $toWrite[$id] = $r['html'];
    } catch (\Throwable $e) {
        $counts['error']++;
        $report[] = [$id, 'error', $e->getMessage(), ''];
    }
}

$reportPath = $outDir . '/merge_legacy_template_report.csv';
$rh = fopen($reportPath, 'w');
fputcsv($rh, ['item_id', 'status', 'notes', 'html_bytes']);
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
