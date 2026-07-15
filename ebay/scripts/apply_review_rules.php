<?php

declare(strict_types=1);

/**
 * apply_review_rules.php  —  Ethan's proposing rules, applied to review_sheet.csv.  DRY-RUN.
 *
 * The inventory lead gave four general rules for how PROPOSED values should be
 * chosen. This pass writes them into the `proposed_value` column (and records the
 * original value in `reviewer_notes` whenever it changes), so his review is
 * pre-populated. It NEVER touches `current_value` (the live eBay value) or
 * `approved_value` (his decision) — so nothing here can overwrite a live value;
 * the merge guard in build_apply_set.php still requires a human approval before any
 * live value changes. Re-runnable / idempotent.
 *
 * Rules (verbatim intent; numbered to match the `rule #N` markers this script writes
 * into reviewer_notes, so a note in review_sheet.csv always traces back to here):
 *  1. Always prefer an ALLOWED value when proposing, whenever an allowed list
 *     exists (even FREE_TEXT aspects carry eBay's recommended list). e.g. Blade
 *     Size "12 in" with [4" or Less|5"|6"|7"|8"|9"|10" or More] -> 10" or More.
 *     CONSERVATIVE: snap only on a confident match (exact, or numeric->bucket);
 *     otherwise leave the value untouched and flag it (never force a wrong value,
 *     e.g. "Fabric, Sewing" must NOT be snapped to [Left-handed|Right-handed]).
 *  2. California Prop 65 Warning -> POLICY CHANGE (2026-07): no longer proposed as an
 *     item specific at all. The owner moved this language into the product
 *     description as a generic badge image (see build_description_review.php's
 *     PROP65_BADGE_URL) instead of an aspect, for every listing regardless of
 *     chemical/brand. This rule now only leaves a reviewer_notes trail; actually
 *     removing the already-live values is handled by the separate
 *     mark_prop65_delete.php + delete_prop65_live.php pair (see
 *     ebay/docs/review-rules.md §3 for the full writeup).
 *  3. Country of Origin -> when blank/unknown, default to China (blank rows only;
 *     never overwrite an existing country).
 *  4. Manufacturer Warranty -> standard WARRANTY_TEXT, unless the aspect is
 *     SELECTION_ONLY (a fixed duration list) — flagged for review instead, since the
 *     standard text isn't a valid pick from that list.
 *  5. Applicability ("does this aspect even apply to this product?") IS mechanised
 *     here, via `ai_review.php --mode=blanks`'s pre-computed LLM judgments in
 *     blank_value_checks.csv — this script reads those and, where the judgment says
 *     N/A, writes the `blank_value` marker into proposed_value (see rule #5 below;
 *     build_apply_set.php treats a live blank_value as an explicit DELETE). Run
 *     `ai_review.php --mode=blanks` first or this rule silently has nothing to apply.
 *     Documented further in docs/review-rules.md.
 *
 * Usage: php ebay/scripts/apply_review_rules.php --account=dows [--dry]
 *   --dry  print what would change without writing the sheet.
 *
 * IMPORTANT: run this AFTER build_review_sheet.php (it regenerates the sheet and
 * would drop these). verify_and_merge.sh chains it as the final step.
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['account:', 'dry']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dryRun  = isset($opts['dry']);
$dir     = ebay_dir($account, 'output');
$path    = $dir . '/review_sheet.csv';

if (!is_file($path)) { fwrite(STDERR, "no review_sheet.csv for {$account}\n"); exit(1); }

require __DIR__ . '/lib/prop65.php';

function isCountry(string $aspect): bool
{
    $a = mb_strtolower($aspect);
    return strpos($a, 'country of origin') !== false || strpos($a, 'country/region of manufacture') !== false;
}
function isManufWarranty(string $aspect): bool
{
    return strpos(mb_strtolower($aspect), 'manufacturer warranty') !== false;
}
const WARRANTY_TEXT = 'Limited Manufacturer Direct';

// ---- numeric / range bucket snapping ---------------------------------------
function firstNum(string $s): ?float
{
    $s = trim($s);
    // fractions FIRST so "3/16 in" is 0.1875, not 3, and "1 1/2" is 1.5
    if (preg_match('#(\d+)\s+(\d+)\s*/\s*(\d+)#', $s, $m) && (float) $m[3] != 0.0) {
        return (float) $m[1] + (float) $m[2] / (float) $m[3];
    }
    if (preg_match('#(\d+)\s*/\s*(\d+)#', $s, $m) && (float) $m[2] != 0.0) {
        return (float) $m[1] / (float) $m[2];
    }
    if (preg_match('/(-?\d+(?:\.\d+)?)/', $s, $m)) { return (float) $m[1]; }
    return null;
}
// parse one allowed option into an inclusive interval [lo, hi], or null if not numeric
function optInterval(string $opt): ?array
{
    $o = mb_strtolower(trim($opt));
    if ($o === '') { return null; }
    if (preg_match('/(-?\d+(?:\.\d+)?)\s*(?:"|in|inch|inches|players?|holes?)?\s*(?:or\s+)?more/u', $o, $m)) {
        return [(float) $m[1], INF];
    }
    if (preg_match('/(?:less\s+than)\s*(-?\d+(?:\.\d+)?)/u', $o, $m)) { return [-INF, (float) $m[1]]; }
    if (preg_match('/(-?\d+(?:\.\d+)?)\s*(?:"|in|inch|inches|players?|holes?)?\s*(?:or\s+)?less/u', $o, $m)) {
        return [-INF, (float) $m[1]];
    }
    if (preg_match('/(-?\d+(?:\.\d+)?)\s*-\s*(-?\d+(?:\.\d+)?)/', $o, $m)) {
        return [(float) $m[1], (float) $m[2]];
    }
    if (preg_match('/^\D*(-?\d+(?:\.\d+)?)\D*$/', $o, $m)) { return [(float) $m[1], (float) $m[1]]; }
    return null;
}
// detect the unit family in a string (so we never snap "4 fl oz" into a "1-5 gal"
// bucket). Returns null when no unit is present (a bare number -> trust the aspect).
// Token allows a digit immediately before it (4in, 4lb) but not a letter (Stainless).
function unitFamily(string $s): ?string
{
    $s = mb_strtolower($s);
    $map = [
        'FLOZ' => 'fl\.?\s*oz|fluid\s*ounces?',
        'GAL'  => 'gal(lon)?s?',
        'QT'   => 'quarts?|qt',
        'PT'   => 'pints?|pt',
        'MIL'  => 'mils?',
        'ML'   => 'ml|milliliters?',
        'L'    => 'l|liters?|litres?',
        'LB'   => 'lbs?|pounds?',
        'OZWT' => 'oz|ounces?',
        'KG'   => 'kg|kilograms?',
        'G'    => 'g|grams?',
        'INCH' => 'in(ch(es)?)?|"',
        'FOOT' => 'ft|fee?t|foot',
        'CM'   => 'cm',
        'MM'   => 'mm',
    ];
    foreach ($map as $fam => $alt) {
        if (preg_match('/(?<![a-z])(' . $alt . ')(?![a-z])/u', $s)) { return $fam; }
    }
    return null;
}
// distinct unit families across the allowed options (so a bare "2" against a
// "qt | L" list is recognised as AMBIGUOUS and flagged instead of guessed).
function allowedFamilies(array $opts): array
{
    $f = [];
    foreach ($opts as $o) { $u = unitFamily($o); if ($u !== null) { $f[$u] = true; } }
    return array_keys($f);
}

// is this allowed list a numeric bucket list?
function isBucketList(array $opts): bool
{
    $num = 0; $tot = 0;
    foreach ($opts as $o) { if (trim($o) === '') { continue; } $tot++; if (optInterval($o) !== null) { $num++; } }
    return $tot > 0 && $num === $tot;        // every option is numeric/range/bound
}

/**
 * Snap a proposed value to the allowed list. Returns [value, status]:
 *   status = 'exact' | 'bucket' | 'bucket-approx' | 'none'(left as-is, flag)
 */
function snap(string $val, array $opts): array
{
    $vt = mb_strtolower(trim($val));
    foreach ($opts as $o) {                                    // exact (case-insensitive)
        if (mb_strtolower(trim($o)) === $vt) { return [trim($o), 'exact']; }
    }
    // light-normalised exact: drop quotes/inch words/spaces so 12" == 12 in == 12inch
    $norm = fn(string $s) => preg_replace('/[\s"]|inch(es)?|\bin\b/u', '', mb_strtolower(trim($s)));
    foreach ($opts as $o) { if ($norm($o) === $norm($val) && $norm($val) !== '') { return [trim($o), 'exact']; } }

    if (isBucketList($opts)) {
        // open-ended values ("3 Years & Up", "10+", "over 5") cannot be reduced to
        // one closed bucket — flag, don't snap.
        if (preg_match('/(?<![a-z])(up|older|over|plus)(?![a-z])|\+|&\s*up|and\s*up|or\s*(more|older|above)/i', $val)) {
            return [$val, 'none'];
        }
        // unit safety: never snap "4 fl oz" into a gallon bucket, "2mm" into "mil".
        $vfam  = unitFamily($val);
        $afams = allowedFamilies($opts);
        if ($vfam !== null) {
            if (!empty($afams) && !in_array($vfam, $afams, true)) { return [$val, 'none']; }  // unit mismatch
        } else {
            if (count($afams) > 1) { return [$val, 'none']; }   // bare number + ambiguous (qt|L, kg|lb) list
        }

        $v = firstNum($val);
        if ($v !== null) {
            foreach ($opts as $o) {                            // interval CONTAINING V (range / or-More / or-Less)
                $iv = optInterval($o);
                if ($iv && $v >= $iv[0] && $v <= $iv[1]) { return [trim($o), 'bucket']; }
            }
            // NO nearest-fallback: a count/size that falls in no bucket (e.g. pack of 1
            // where options start at 2, or 50 items where max is 12, or 18 in where max
            // is 16) is NOT silently clamped — that invents data. Flag it for review.
        }
    }
    return [$val, 'none'];                                       // leave it, caller flags
}

// ---- load -------------------------------------------------------------------
$fh = fopen($path, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);
foreach (['aspect', 'proposed_value', 'current_value', 'reviewer_notes', 'allowed_values', 'title'] as $c) {
    if (!isset($idx[$c])) { fwrite(STDERR, "missing column {$c}\n"); exit(1); }
}
$rows = [];
while (($r = fgetcsv($fh)) !== false) { $rows[] = $r; }
fclose($fh);

// rule #5: load the LLM "not applicable" determinations (item_id|normAspect => reason).
// Built by `ai_review.php --mode=blanks --merge`; absent until the judgment pass is merged.
$naBlank = [];
$bnorm = fn(string $s) => preg_replace('/\s+/', ' ', trim(mb_strtolower(rtrim(trim($s), ':'))));
if (is_file($dir . '/blank_value_checks.csv')) {
    $bf = fopen($dir . '/blank_value_checks.csv', 'r'); $bh = fgetcsv($bf);
    $bi = array_flip($bh);
    while (($br = fgetcsv($bf)) !== false) {
        $naBlank[$br[$bi['item_id']] . '|' . $bnorm($br[$bi['aspect']])] = $br[$bi['reason']] ?? '';
    }
    fclose($bf);
}

$stat = ['p65' => 0, 'country' => 0,
         'warranty' => 0, 'warranty_flag' => 0, 'blank_value' => 0,
         'snap_exact' => 0, 'snap_bucket' => 0, 'snap_approx' => 0, 'snap_flag' => 0];

foreach ($rows as &$r) {
    $aspect  = $r[$idx['aspect']];
    $title   = mb_strtolower($r[$idx['title']]);
    $cur     = trim($r[$idx['current_value']]);
    $prop    = trim($r[$idx['proposed_value']]);
    $mode    = $r[$idx['mode']];
    $allowed = $r[$idx['allowed_values']];
    $note    = trim($r[$idx['reviewer_notes']]);

    $newProp = $prop;
    $addNote = '';

    if (isProp65($aspect)) {
        // ----- rule #2: California Prop 65 — REMOVED FROM ITEM SPECIFICS (2026-07) -----
        // No longer proposed as a value here; the warning now lives in the description
        // as a badge (build_description_review.php). Already-live values are deleted by
        // mark_prop65_delete.php + delete_prop65_live.php, not by this dry-run script.
        $newProp = '';
        $addNote = 'rule #2: Prop65 policy change — removed from item specifics, moved to description badge';
        $stat['p65']++;
    } elseif (isCountry($aspect) && $cur === '' && $prop === '') {
        // ----- rule #3: Country of Origin default -----
        $newProp = 'China';
        $addNote = 'rule #3: Country default (China when in doubt)';
        $stat['country']++;
    } elseif (isManufWarranty($aspect)) {
        // ----- rule #4: Manufacturer Warranty -> standard text -----
        // SELECTION_ONLY warranty aspects use a fixed duration list (1 Year, etc.);
        // "Limited Manufacturer Direct" is not a legal value there, so flag instead.
        if ($mode === 'SELECTION_ONLY') {
            $addNote = 'rule #4: warranty is SELECTION_ONLY (duration list) — "' . WARRANTY_TEXT . '" not valid, review';
            $stat['warranty_flag']++;
        } elseif (mb_strtolower($cur) === mb_strtolower(WARRANTY_TEXT)) {
            $newProp = ''; $addNote = 'rule #4: warranty already standard';
        } else {
            $newProp = WARRANTY_TEXT;
            $addNote = 'rule #4: Manufacturer Warranty -> ' . WARRANTY_TEXT;
            $stat['warranty']++;
        }
    } elseif ($prop !== '' && trim($allowed) !== '' && strpos($allowed, '...') === false) {
        // ----- rule #1: snap proposed to an allowed value -----
        $opts = array_map('trim', explode('|', $allowed));
        [$snapped, $how] = snap($prop, $opts);
        if ($how === 'exact') {
            if ($snapped !== $prop) { $newProp = $snapped; $addNote = "rule #1: normalised to allowed"; }
            $stat['snap_exact']++;
        } elseif ($how === 'bucket' || $how === 'bucket-approx') {
            $newProp = $snapped;
            $addNote = "rule #1: snapped \"{$prop}\" -> \"{$snapped}\"" . ($how === 'bucket-approx' ? ' (approx, verify)' : '');
            $how === 'bucket' ? $stat['snap_bucket']++ : $stat['snap_approx']++;
        } else {
            $addNote = "rule #1: \"{$prop}\" not in allowed list — review";
            $stat['snap_flag']++;
        }
    } elseif ($cur === '' && $prop === '' && ($r[$idx['source']] ?? '') !== 'variation'
              && isset($naBlank[$r[$idx['item_id']] . '|' . $bnorm($aspect)])) {
        // ----- rule #5: aspect doesn't apply to this product -> mark blank_value -----
        $reason  = $naBlank[$r[$idx['item_id']] . '|' . $bnorm($aspect)];
        $newProp = 'blank_value';
        $addNote = 'rule #5: not applicable' . ($reason !== '' ? " ({$reason})" : '');
        $stat['blank_value']++;
    }

    if ($newProp !== $prop || $addNote !== '') {
        $r[$idx['proposed_value']] = $newProp;
        if ($addNote !== '') {
            $r[$idx['reviewer_notes']] = $note === '' ? $addNote : ($note . ' | ' . $addNote);
        }
    }
}
unset($r);

if ($dryRun) {
    echo "[DRY] {$account}: no file written\n";
} else {
    $tmp = $path . '.tmp';
    $out = fopen($tmp, 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) { fputcsv($out, $r); }
    fclose($out);
    rename($tmp, $path);
    echo "wrote {$path}\n";
}

printf("  Prop65: %d rows noted removed-from-specifics (see mark_prop65_delete.php for the live delete)\n",
    $stat['p65']);
printf("  Country default(China) on blanks: %d\n", $stat['country']);
printf("  Manufacturer Warranty -> '%s': %d (SELECTION_ONLY flagged: %d)\n",
    WARRANTY_TEXT, $stat['warranty'], $stat['warranty_flag']);
printf("  blank_value (rule #5 N/A markers applied): %d%s\n", $stat['blank_value'],
    $naBlank ? '' : '  [no blank_value_checks.csv yet — run ai_review.php --mode=blanks first]');
printf("  Allowed-snap: exact %d, bucket %d, approx %d, FLAGGED(left as-is) %d\n",
    $stat['snap_exact'], $stat['snap_bucket'], $stat['snap_approx'], $stat['snap_flag']);
