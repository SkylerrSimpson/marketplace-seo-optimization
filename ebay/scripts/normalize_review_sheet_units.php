<?php
declare(strict_types=1);
/**
 * Normalize unit measurements in review_sheet.csv's `proposed_value` column — for
 * accounts (e.g. ige) where Ethan hasn't done a review round-trip yet, so there is no
 * separate "returned" handoff CSV. This lets our own AI-proposed values go out already
 * unit-clean, instead of waiting on a round-trip like normalize_handoff_units.php does.
 *
 * Same aspect scope + normalization rules as normalize_handoff_units.php (see that file
 * for the full rule list): in-family length aspects get a bare number -> "N in", lb-family
 * weight aspects get a bare number -> "N lb", Size/Capacity only get reformatted if they
 * already carry a unit token (no unit is ever guessed for them).
 *
 * VARY-BY GUARD: review_sheet.csv carries `varied_by` inline on every row (no separate
 * lookup file needed, unlike the handoff-CSV case). Any row where `aspect` matches that
 * row's own `varied_by` (case-insensitive) is left untouched — that aspect is the
 * variation-defining attribute for the listing, and rewriting it would change which
 * variation a child sku maps to on eBay and orphan its sales history.
 *
 * Usage:
 *   php ebay/scripts/normalize_review_sheet_units.php --account=ige [--dry-run]
 *   php ebay/scripts/normalize_review_sheet_units.php --input=path/to/review_sheet.csv --out=path/to/out.csv
 */

$opts    = getopt('', ['account:', 'input:', 'out:', 'dry-run']);
$base    = dirname(__DIR__);
$account = $opts['account'] ?? '';
$dryRun  = isset($opts['dry-run']);

$input = $opts['input'] ?? ($account !== '' ? "$base/data/$account/output/review_sheet.csv" : '');
if ($input === '') { fwrite(STDERR, "--account=dows|ige or --input=path is required\n"); exit(1); }
if (!is_file($input)) { fwrite(STDERR, "input not found: $input\n"); exit(1); }

$inPlace = !isset($opts['out']);
$out = $opts['out'] ?? $input;
$backup = $input . '.preunitnorm.bak';

/* ---------- aspect scope (identical to normalize_handoff_units.php) ---------- */
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
foreach (['item_id', 'sku', 'varied_by', 'aspect', 'proposed_value'] as $r) {
    if (!isset($idx[$r])) { fwrite(STDERR, "missing column: $r\n"); exit(1); }
}

$rowsOut = [$header];
$rows = 0; $targetRows = 0; $changed = 0; $touched = []; $samples = []; $varyBySkipped = 0;
while (($row = fgetcsv($fh)) !== false) {
    $rows++;
    $varyBy = strtolower(trim($row[$idx['varied_by']] ?? ''));
    $aspect = $row[$idx['aspect']] ?? '';
    $value  = $row[$idx['proposed_value']] ?? '';
    $al = strtolower($aspect);

    $isVaryBy = $varyBy !== '' && $varyBy === $al;
    if ($isVaryBy) { $varyBySkipped++; }

    if (!$isVaryBy && isset($ALL_TARGETS[$al])) {
        $targetRows++;
        [$norm, $chg] = normalizeUnit($aspect, $value, $IN_FAMILY, $LB_FAMILY, $WORD_UNIT_MAP,
            $GLUED_UNITS, $GLUED_CANON, $TRAILING_UNIT_WORDS, $SKIP_VALUES);
        if ($chg) {
            $changed++;
            $touched[$aspect] = ($touched[$aspect] ?? 0) + 1;
            if (count($samples) < 40) { $samples[] = [$aspect, $value, $norm]; }
            $row[$idx['proposed_value']] = $norm;
            if (isset($idx['reviewer_notes'])) {
                $note = 'Unit-normalized (formatting only: "' . $value . '" -> "' . $norm . '"; no value change).';
                $existing = trim($row[$idx['reviewer_notes']] ?? '');
                $row[$idx['reviewer_notes']] = $existing === '' ? $note : $existing . ' ' . $note;
            }
        }
    }

    $rowsOut[] = $row;
}
fclose($fh);

if (!$dryRun) {
    if ($inPlace && !is_file($backup)) {
        copy($input, $backup);
    }
    $ofh = fopen($out, 'w');
    foreach ($rowsOut as $row) { fputcsv($ofh, $row); }
    fclose($ofh);
}

echo "input:  $input\n";
echo "out:    " . ($dryRun ? '(dry-run, not written)' : $out) . "\n";
if ($inPlace && !$dryRun) { echo "backup: $backup\n"; }
echo "rows: $rows   in-scope aspect rows: $targetRows   proposed_value changed: $changed   vary-by skipped: $varyBySkipped\n\n";
echo "--- changed by aspect ---\n";
arsort($touched);
foreach ($touched as $a => $c) { printf("  %-24s %d\n", $a, $c); }
echo "\n--- sample changes (aspect | before -> after) ---\n";
foreach ($samples as $s) { printf("  %-20s %-24s -> %s\n", substr($s[0], 0, 20), $s[1], $s[2]); }
