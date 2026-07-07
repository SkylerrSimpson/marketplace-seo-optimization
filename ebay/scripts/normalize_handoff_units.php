<?php
declare(strict_types=1);
/**
 * Normalize unit measurements in an inventory-reviewed handoff CSV (item_id,sku,aspect,
 * final_value shape — e.g. Ethan's "no features no set includes" round), so the batch can
 * go back out for a second review pass.
 *
 * Scope (confirmed 2026-07-03): only these aspects are touched —
 *   in-family (bare number -> "N in"): Length, Width, Height, Item Length, Item Width,
 *     Item Height, Thickness, Bag Height, Bag Width, Belt Width, Blade Length, Cable Length,
 *     Canopy Width, Closed Length, Drop Length, Extended Length, Guide Bar Length,
 *     Handle Length, Lash Length, Lead Length, Mirror Width, Overall Length, Seat Height,
 *     Sluice Box Length, Stretched Bungee Length, Tape Length
 *   lb-family (bare number -> "N lb"): Weight, Item Weight, Total Weight, Head Weight
 *   no-default-unit (only reformat values that already carry a unit token): Size, Capacity
 * Deliberately EXCLUDED: Features/Set Includes (not in this handoff round at all),
 * Battery/Seating/Sleeping/Weight/Target Capacity (different units), Total Carat Weight
 * (carats, not lb), UV Blacklight Wave Length (wavelength, not a physical dimension).
 *
 * Rules (same spelling-only normalization as normalize_units.php — no cross-unit math,
 * e.g. oz is never converted to lb, L is never converted to fl oz):
 *   4 In / 4 inches / 4 Inches / 4" / 4 / 4.00  -> 4 in
 *   .25                                          -> 0.25 in   (leading zero added)
 *   35" x 71"                                    -> 35 in x 71 in
 *   8.5 x 5.5 inch  (single trailing unit word)   -> 8.5 in x 5.5 in
 *   8.5lbs / 10LB                                 -> 8.5 lb / 10 lb  (case + plural glued)
 * Values with no recognizable unit context (".22 Caliber", "1-3 Watches", "One Size",
 * "blank_value") are left untouched on purpose — Size and Capacity in particular mix real
 * measurements with non-measurement values, so no default unit is ever guessed for them.
 *
 * VARY-BY GUARD (added 2026-07-06, per Ethan's review): an aspect that is the
 * variation-defining attribute for a listing (review_sheet.csv's `varied_by` column,
 * e.g. Size/Color/Style) must NEVER have its value rewritten on a child sku. eBay ties
 * sales history to the exact variation value; changing "4" -> "4 in" on a child's Size
 * effectively creates a different variation and orphans that history. So for every
 * (item_id, aspect) pair where aspect == that item's varied_by aspect, the row is left
 * completely untouched regardless of aspect scope above.
 *
 * Usage:
 *   php ebay/scripts/normalize_handoff_units.php --input="ebay/handoff/returned/ebay dows first round updates, no features no set includes.csv" --account=dows
 *   php ebay/scripts/normalize_handoff_units.php --input=... --account=dows --out=path/to/round2.csv
 */

$opts    = getopt('', ['input:', 'out:', 'account:']);
$base    = dirname(__DIR__);
$input   = $opts['input'] ?? '';
$account = $opts['account'] ?? '';
if ($input === '') { fwrite(STDERR, "--input is required\n"); exit(1); }
if (!is_file($input)) { fwrite(STDERR, "input not found: $input\n"); exit(1); }
if ($account === '') { fwrite(STDERR, "--account is required (dows|ige) to load the varied_by guard from review_sheet.csv\n"); exit(1); }
$out = $opts['out'] ?? preg_replace('/\.csv$/i', '', $input) . '.unit_normalized.csv';

/* ---------- vary-by guard: item_id -> set of aspect names (lowercased) that are the
   variation-defining aspect for that listing, per review_sheet.csv ---------- */
$reviewSheetPath = "$base/data/$account/output/review_sheet.csv";
if (!is_file($reviewSheetPath)) { fwrite(STDERR, "review_sheet.csv not found for account $account: $reviewSheetPath\n"); exit(1); }
$VARY_BY = [];
$rsfh = fopen($reviewSheetPath, 'r');
$rsHeader = fgetcsv($rsfh);
$rsIdx = array_flip($rsHeader);
foreach (['item_id', 'varied_by'] as $r) {
    if (!isset($rsIdx[$r])) { fwrite(STDERR, "review_sheet.csv missing column: $r\n"); exit(1); }
}
while (($rsRow = fgetcsv($rsfh)) !== false) {
    $vb = trim($rsRow[$rsIdx['varied_by']] ?? '');
    if ($vb === '') { continue; }
    $iid = $rsRow[$rsIdx['item_id']] ?? '';
    $VARY_BY[$iid][strtolower($vb)] = true;
}
fclose($rsfh);

/* ---------- aspect scope ---------- */
$IN_FAMILY = array_flip(array_map('strtolower', [
    'Length','Width','Height','Item Length','Item Width','Item Height','Thickness',
    'Bag Height','Bag Width','Belt Width','Blade Length','Cable Length','Canopy Width',
    'Closed Length','Drop Length','Extended Length','Guide Bar Length','Handle Length',
    'Lash Length','Lead Length','Mirror Width','Overall Length','Seat Height',
    'Sluice Box Length','Stretched Bungee Length','Tape Length',
]));
$LB_FAMILY = array_flip(array_map('strtolower', ['Weight','Item Weight','Total Weight','Head Weight']));
$NO_DEFAULT = array_flip(array_map('strtolower', ['Size','Capacity']));
$ALL_TARGETS = $IN_FAMILY + $LB_FAMILY + $NO_DEFAULT;

$SKIP_VALUES = array_flip(['blank_value','assorted','n/a','na','none','multi','multi-color',
    'multicolor','various','see description','one size','enter numeric value.']);

const NUM = '(?:\d+\.\d+|\.\d+|\d+)';

function fmtNum(string $n): string {
    if ($n !== '' && $n[0] === '.') { $n = '0' . $n; }
    if (strpos($n, '.') !== false) { $n = rtrim($n, '0'); $n = rtrim($n, '.'); }
    return $n === '' ? '0' : $n;
}

$WORD_UNIT_MAP = [
    '/\b(?:kilograms|kilogram)\b/i'                 => 'kg',
    '/\b(?:grams|gram)\b/i'                         => 'g',
    '/\b(?:milliliters|milliliter|millilitre)\b/i'  => 'ml',
    '/\b(?:liters|liter|litre)\b/i'                 => 'L',
    '/\b(?:quarts|quart)\b/i'                       => 'qt',
    '/\b(?:gallons|gallon)\b/i'                     => 'gal',
    '/\b(?:millimeters|millimeter|millimetre)\b/i'  => 'mm',
    '/\b(?:centimeters|centimeter|centimetre)\b/i'  => 'cm',
    '/\b(?:yards|yard)\b/i'                         => 'yd',
    '/\b(?:pounds|pound|lbs|lb\.)\b/i'              => 'lb',
    '/\b(?:ounces|ounce|oz\.)\b/i'                  => 'oz',
];
$GLUED_UNITS = ['mm','cm','ml','kg','gal','qt','yd','in','ft','lbs','lb','ozs','oz','g','L'];
$GLUED_CANON = ['lbs' => 'lb', 'ozs' => 'oz'];
$TRAILING_UNIT_WORDS = [
    'inches'=>'in','inch'=>'in','in.'=>'in','in'=>'in',
    'feet'=>'ft','foot'=>'ft','ft.'=>'ft','ft'=>'ft',
    'pounds'=>'lb','pound'=>'lb','lbs'=>'lb','lb.'=>'lb','lb'=>'lb',
    'ounces'=>'oz','ounce'=>'oz','oz.'=>'oz','oz'=>'oz',
];

/**
 * @return array{0:string,1:bool} [normalized value, changed?]
 */
function normalizeUnit(string $aspect, string $orig, array $IN_FAMILY, array $LB_FAMILY,
    array $WORD_UNIT_MAP, array $GLUED_UNITS, array $GLUED_CANON, array $TRAILING_UNIT_WORDS,
    array $SKIP_VALUES): array
{
    $v = trim($orig);
    if ($v === '') { return [$orig, false]; }
    if (isset($SKIP_VALUES[strtolower($v)])) { return [$orig, false]; }

    $al  = strtolower($aspect);
    $fam = isset($IN_FAMILY[$al]) ? 'in' : (isset($LB_FAMILY[$al]) ? 'lb' : null);

    // dimension with ONE trailing unit word covering multiple x-separated numbers:
    // "8.5 x 5.5 inch" -> "8.5 in x 5.5 in"
    if (preg_match('/^(' . NUM . '(?:\s*[xX]\s*' . NUM . ')+)\s*(inches|inch|in\.|feet|foot|ft\.|pounds|pound|lbs|lb\.|ounces|ounce|oz\.)\s*$/i', $v, $m)) {
        preg_match_all('/' . NUM . '/', $m[1], $nm);
        $unit = $TRAILING_UNIT_WORDS[strtolower($m[2])];
        $parts = array_map(fn($n) => fmtNum($n) . ' ' . $unit, $nm[0]);
        $s = implode(' x ', $parts);
        return [$s, $s !== $v];
    }

    $s = ' ' . $v . ' ';
    // fluid ounce phrasing first
    $s = preg_replace('/\bfl(?:uid)?\.?\s*(?:oz|ounces?)\b/i', ' flozUNIT ', $s);
    // symbols glued to a number
    $s = preg_replace_callback('/(' . NUM . ')\s*"/', fn($m) => fmtNum($m[1]) . ' inUNIT ', $s);
    $s = preg_replace_callback('/(' . NUM . ")\\s*'(?!\\w)/", fn($m) => fmtNum($m[1]) . ' ftUNIT ', $s);
    // 14-inches -> 14 in
    $s = preg_replace_callback('/(' . NUM . ')\s*-\s*(?:inches|inch)\b/i', fn($m) => fmtNum($m[1]) . ' inUNIT ', $s);
    // word units (guard "per inch" / "/inch" rate wording for inch & foot)
    $s = preg_replace('#(?<!/)(?<!per )\b(?:inches|inch|in\.)\b#i', ' inUNIT ', $s);
    $s = preg_replace('#(?<!/)(?<!per )\b(?:feet|foot|ft\.)\b#i', ' ftUNIT ', $s);
    $s = preg_replace(array_keys($WORD_UNIT_MAP), array_map(fn($u) => " {$u}UNIT ", array_values($WORD_UNIT_MAP)), $s);
    // glued abbreviations directly after a number (case-insensitive; handles plural lbs/ozs
    // and bare "in"/"IN")
    foreach ($GLUED_UNITS as $u) {
        $canon = $GLUED_CANON[$u] ?? $u;
        $s = preg_replace_callback('/(' . NUM . ')\s*' . preg_quote($u, '/') . '\b/i',
            fn($m) => fmtNum($m[1]) . ' ' . $canon . 'UNIT ', $s);
    }
    // canonicalize temp tokens
    $s = strtr($s, ['flozUNIT'=>'fl oz','inUNIT'=>'in','ftUNIT'=>'ft','lbUNIT'=>'lb','ozUNIT'=>'oz',
        'kgUNIT'=>'kg','gUNIT'=>'g','mlUNIT'=>'ml','LUNIT'=>'L','qtUNIT'=>'qt','galUNIT'=>'gal',
        'mmUNIT'=>'mm','cmUNIT'=>'cm','ydUNIT'=>'yd']);
    $s = preg_replace('/\s+([,.);\]])/', '$1', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));

    // bare full-value number -> aspect default unit (only for unambiguous in/lb families)
    if ($fam !== null && preg_match('/^' . NUM . '$/', $s)) {
        $s = fmtNum($s) . ' ' . $fam;
    }
    // bare "N x N (x N)" with no unit anywhere -> default unit per number (in-family only)
    elseif ($fam === 'in' && preg_match('/^' . NUM . '(?:\s*[xX]\s*' . NUM . ')+$/', $s)) {
        preg_match_all('/' . NUM . '/', $s, $nm);
        $s = implode(' x ', array_map(fn($n) => fmtNum($n) . ' in', $nm[0]));
    }

    return [$s, $s !== $v];
}

/* ---------- drive the file ---------- */
$fh = fopen($input, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);
foreach (['item_id', 'sku', 'aspect', 'final_value'] as $r) {
    if (!isset($idx[$r])) { fwrite(STDERR, "missing column: $r\n"); exit(1); }
}

$ofh = fopen($out, 'w');
fputcsv($ofh, array_merge($header, ['unit_normalized']));

$rows = 0; $targetRows = 0; $changed = 0; $touched = []; $samples = []; $varyBySkipped = 0;
while (($row = fgetcsv($fh)) !== false) {
    $rows++;
    $itemId = $row[$idx['item_id']] ?? '';
    $aspect = $row[$idx['aspect']] ?? '';
    $value  = $row[$idx['final_value']] ?? '';
    $flag = '';

    $isVaryBy = isset($VARY_BY[$itemId][strtolower($aspect)]);
    if ($isVaryBy) { $varyBySkipped++; }

    if (!$isVaryBy && isset($ALL_TARGETS[strtolower($aspect)])) {
        $targetRows++;
        [$norm, $chg] = normalizeUnit($aspect, $value, $IN_FAMILY, $LB_FAMILY, $WORD_UNIT_MAP,
            $GLUED_UNITS, $GLUED_CANON, $TRAILING_UNIT_WORDS, $SKIP_VALUES);
        if ($chg) {
            $flag = 'Yes'; $changed++;
            $touched[$aspect] = ($touched[$aspect] ?? 0) + 1;
            if (count($samples) < 40) { $samples[] = [$aspect, $value, $norm]; }
            $row[$idx['final_value']] = $norm;
        }
    }

    fputcsv($ofh, array_merge($row, [$flag]));
}
fclose($fh); fclose($ofh);

echo "input:  $input\n";
echo "out:    $out\n";
echo "rows: $rows   in-scope aspect rows: $targetRows   unit_normalized=Yes: $changed   vary-by skipped: $varyBySkipped\n\n";
echo "--- changed by aspect ---\n";
arsort($touched);
foreach ($touched as $a => $c) { printf("  %-24s %d\n", $a, $c); }
echo "\n--- sample changes (aspect | before -> after) ---\n";
foreach ($samples as $s) { printf("  %-20s %-24s -> %s\n", substr($s[0], 0, 20), $s[1], $s[2]); }
