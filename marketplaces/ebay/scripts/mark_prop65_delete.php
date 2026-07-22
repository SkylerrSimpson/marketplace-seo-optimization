<?php

declare(strict_types=1);

/**
 * mark_prop65_delete.php — owner-directed removal of "California Prop 65 Warning"
 * from eBay item specifics (2026-07): the warning now lives in the description as a
 * badge image (build_description_review.php's PROP65_BADGE_URL) instead of an
 * aspect. This writes `approved_value = 'DELETE'` on every review_sheet.csv row
 * where the aspect is live today (source=current) — the one exception to
 * apply_review_rules.php's own rule that dry-run script never touches
 * approved_value, because this isn't a content judgment call for a reviewer to weigh in
 * on, it's a blanket policy decision already made by the owner. See
 * ebay/docs/review-rules.md §3 for the full writeup.
 *
 * IMPORTANT: this only sets an audit-trail marker in review_sheet.csv. The actual
 * live eBay write is a SEPARATE script, delete_prop65_live.php — it deliberately
 * does NOT read approved_value (see that script's docblock for why: many DOWS items
 * have OTHER unrelated pending approved_value entries from the still-in-progress UPC
 * handoff, and eBay's ReviseItem replaces an item's entire ItemSpecifics in one
 * call, so sourcing the live write from approved_value here would risk pushing those
 * unrelated, not-yet-finished approvals live too).
 *
 * Must run AFTER the last build_review_sheet.php call for the account (which always
 * resets approved_value to '' on every regenerate) and BEFORE delete_prop65_live.php
 * — nothing in between may rebuild the sheet, or this marker is silently wiped.
 * Re-runnable / idempotent (only touches rows currently missing a DELETE marker).
 *
 * Usage: php ebay/scripts/mark_prop65_delete.php --account=dows [--dry]
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/prop65.php';

$opts    = getopt('', ['account:', 'dry']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dryRun  = isset($opts['dry']);
$dir     = ebay_dir($account, 'output');
$path    = $dir . '/review_sheet.csv';

if (!is_file($path)) { fwrite(STDERR, "no review_sheet.csv for {$account}\n"); exit(1); }

$fh = fopen($path, 'r');
$header = fgetcsv($fh);
$rows = [];
while (($r = fgetcsv($fh)) !== false) { $rows[] = array_combine($header, $r); }
fclose($fh);

$marked = 0; $alreadyMarked = 0;
foreach ($rows as &$r) {
    if (!isProp65($r['aspect']) || $r['source'] !== 'current') { continue; }
    if (strtoupper(trim($r['approved_value'])) === 'DELETE') { $alreadyMarked++; continue; }
    $r['approved_value']  = 'DELETE';
    $note = 'owner-directed removal 2026-07 — moved to description Prop65 badge';
    $r['reviewer_notes'] = trim($r['reviewer_notes']) === '' ? $note : (trim($r['reviewer_notes']) . ' | ' . $note);
    $marked++;
}
unset($r);

if ($dryRun) {
    echo "[DRY] {$account}: no file written\n";
} else {
    $tmp = $path . '.tmp';
    $out = fopen($tmp, 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) { fputcsv($out, array_values($r)); }
    fclose($out);
    rename($tmp, $path);
    echo "wrote {$path}\n";
}

printf("  Prop65 rows marked approved_value=DELETE: %d (already marked: %d)\n", $marked, $alreadyMarked);
printf("  NOTE: this is the audit-trail marker only. Run delete_prop65_live.php to actually remove it from eBay.\n");
