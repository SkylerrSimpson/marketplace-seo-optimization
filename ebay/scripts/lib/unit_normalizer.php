<?php

declare(strict_types=1);

/**
 * Shared unit-normalization core for eBay aspect values — spelling-only, no cross-unit
 * math (oz is never converted to lb, L is never converted to fl oz):
 *   4 In / 4 inches / 4 Inches / 4" / 4 / 4.00  -> 4 in
 *   .25                                          -> 0.25 in   (leading zero added)
 *   35" x 71"                                    -> 35 in x 71 in
 *   8.5 x 5.5 inch  (single trailing unit word)   -> 8.5 in x 5.5 in
 *   8.5lbs / 10LB                                 -> 8.5 lb / 10 lb  (case + plural glued)
 * Values with no recognizable unit context (".22 Caliber", "1-3 Watches", "One Size",
 * "blank_value") are left untouched on purpose — Size and Capacity in particular mix real
 * measurements with non-measurement values, so no default unit is ever guessed for them.
 *
 * Used by both normalize_handoff_units.php (a returned handoff CSV) and
 * normalize_review_sheet_units.php (review_sheet.csv directly) — this logic used to be
 * copy-pasted verbatim into both scripts; extracted 2026-07-06 so a fix only has to
 * happen in one place. Both callers still separately implement their own vary-by guard
 * (they get `varied_by` from different places — a cross-referenced review_sheet.csv for
 * the handoff CSV, vs. review_sheet.csv's own inline column) — that guard logic is
 * intentionally NOT here, to keep it visible at each call site.
 */

/**
 * Aspect scope (confirmed 2026-07-03): only these aspects are touched —
 *   in-family (bare number -> "N in"): Length, Width, Height, Item Length, Item Width,
 *     Item Height, Thickness, Bag Height, Bag Width, Belt Width, Blade Length, Cable Length,
 *     Canopy Width, Closed Length, Drop Length, Extended Length, Guide Bar Length,
 *     Handle Length, Lash Length, Lead Length, Mirror Width, Overall Length, Seat Height,
 *     Sluice Box Length, Stretched Bungee Length, Tape Length
 *   lb-family (bare number -> "N lb"): Weight, Item Weight, Total Weight, Head Weight
 *   no-default-unit (only reformat values that already carry a unit token): Size, Capacity
 * Deliberately EXCLUDED: Features/Set Includes, Battery/Seating/Sleeping/Weight/Target
 * Capacity (different units), Total Carat Weight (carats, not lb), UV Blacklight Wave
 * Length (wavelength, not a physical dimension).
 *
 * @return array{inFamily:array,lbFamily:array,noDefault:array,allTargets:array}
 */
function unitNormalizerScope(): array
{
    $inFamily = array_flip(array_map('strtolower', [
        'Length', 'Width', 'Height', 'Item Length', 'Item Width', 'Item Height', 'Thickness',
        'Bag Height', 'Bag Width', 'Belt Width', 'Blade Length', 'Cable Length', 'Canopy Width',
        'Closed Length', 'Drop Length', 'Extended Length', 'Guide Bar Length', 'Handle Length',
        'Lash Length', 'Lead Length', 'Mirror Width', 'Overall Length', 'Seat Height',
        'Sluice Box Length', 'Stretched Bungee Length', 'Tape Length',
    ]));
    $lbFamily = array_flip(array_map('strtolower', ['Weight', 'Item Weight', 'Total Weight', 'Head Weight']));
    $noDefault = array_flip(array_map('strtolower', ['Size', 'Capacity']));

    return [
        'inFamily' => $inFamily,
        'lbFamily' => $lbFamily,
        'noDefault' => $noDefault,
        'allTargets' => $inFamily + $lbFamily + $noDefault,
    ];
}

function unitNormalizerFmtNum(string $n): string
{
    if ($n !== '' && $n[0] === '.') { $n = '0' . $n; }
    if (strpos($n, '.') !== false) { $n = rtrim($n, '0'); $n = rtrim($n, '.'); }
    return $n === '' ? '0' : $n;
}

const UNIT_NORMALIZER_NUM = '(?:\d+\.\d+|\.\d+|\d+)';

/**
 * @return array{0:string,1:bool} [normalized value, changed?]
 */
function unitNormalizerNormalize(string $aspect, string $orig, array $inFamily, array $lbFamily): array
{
    static $wordUnitMap, $gluedUnits, $gluedCanon, $trailingUnitWords, $skipValues;
    if ($wordUnitMap === null) {
        $wordUnitMap = [
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
        $gluedUnits = ['mm', 'cm', 'ml', 'kg', 'gal', 'qt', 'yd', 'in', 'ft', 'lbs', 'lb', 'ozs', 'oz', 'g', 'L'];
        $gluedCanon = ['lbs' => 'lb', 'ozs' => 'oz'];
        $trailingUnitWords = [
            'inches' => 'in', 'inch' => 'in', 'in.' => 'in', 'in' => 'in',
            'feet' => 'ft', 'foot' => 'ft', 'ft.' => 'ft', 'ft' => 'ft',
            'pounds' => 'lb', 'pound' => 'lb', 'lbs' => 'lb', 'lb.' => 'lb', 'lb' => 'lb',
            'ounces' => 'oz', 'ounce' => 'oz', 'oz.' => 'oz', 'oz' => 'oz',
        ];
        $skipValues = array_flip(['blank_value', 'assorted', 'n/a', 'na', 'none', 'multi', 'multi-color',
            'multicolor', 'various', 'see description', 'one size', 'enter numeric value.']);
    }

    $v = trim($orig);
    if ($v === '') { return [$orig, false]; }
    if (isset($skipValues[strtolower($v)])) { return [$orig, false]; }

    $al  = strtolower($aspect);
    $fam = isset($inFamily[$al]) ? 'in' : (isset($lbFamily[$al]) ? 'lb' : null);
    $num = UNIT_NORMALIZER_NUM;

    // dimension with ONE trailing unit word covering multiple x-separated numbers:
    // "8.5 x 5.5 inch" -> "8.5 in x 5.5 in"
    if (preg_match('/^(' . $num . '(?:\s*[xX]\s*' . $num . ')+)\s*(inches|inch|in\.|feet|foot|ft\.|pounds|pound|lbs|lb\.|ounces|ounce|oz\.)\s*$/i', $v, $m)) {
        preg_match_all('/' . $num . '/', $m[1], $nm);
        $unit = $trailingUnitWords[strtolower($m[2])];
        $parts = array_map(fn($n) => unitNormalizerFmtNum($n) . ' ' . $unit, $nm[0]);
        $s = implode(' x ', $parts);
        return [$s, $s !== $v];
    }

    $s = ' ' . $v . ' ';
    // fluid ounce phrasing first
    $s = preg_replace('/\bfl(?:uid)?\.?\s*(?:oz|ounces?)\b/i', ' flozUNIT ', $s);
    // symbols glued to a number
    $s = preg_replace_callback('/(' . $num . ')\s*"/', fn($m) => unitNormalizerFmtNum($m[1]) . ' inUNIT ', $s);
    $s = preg_replace_callback('/(' . $num . ")\\s*'(?!\\w)/", fn($m) => unitNormalizerFmtNum($m[1]) . ' ftUNIT ', $s);
    // 14-inches -> 14 in
    $s = preg_replace_callback('/(' . $num . ')\s*-\s*(?:inches|inch)\b/i', fn($m) => unitNormalizerFmtNum($m[1]) . ' inUNIT ', $s);
    // word units (guard "per inch" / "/inch" rate wording for inch & foot)
    $s = preg_replace('#(?<!/)(?<!per )\b(?:inches|inch|in\.)\b#i', ' inUNIT ', $s);
    $s = preg_replace('#(?<!/)(?<!per )\b(?:feet|foot|ft\.)\b#i', ' ftUNIT ', $s);
    $s = preg_replace(array_keys($wordUnitMap), array_map(fn($u) => " {$u}UNIT ", array_values($wordUnitMap)), $s);
    // glued abbreviations directly after a number (case-insensitive; handles plural lbs/ozs
    // and bare "in"/"IN")
    foreach ($gluedUnits as $u) {
        $canon = $gluedCanon[$u] ?? $u;
        $s = preg_replace_callback('/(' . $num . ')\s*' . preg_quote($u, '/') . '\b/i',
            fn($m) => unitNormalizerFmtNum($m[1]) . ' ' . $canon . 'UNIT ', $s);
    }
    // canonicalize temp tokens
    $s = strtr($s, ['flozUNIT' => 'fl oz', 'inUNIT' => 'in', 'ftUNIT' => 'ft', 'lbUNIT' => 'lb', 'ozUNIT' => 'oz',
        'kgUNIT' => 'kg', 'gUNIT' => 'g', 'mlUNIT' => 'ml', 'LUNIT' => 'L', 'qtUNIT' => 'qt', 'galUNIT' => 'gal',
        'mmUNIT' => 'mm', 'cmUNIT' => 'cm', 'ydUNIT' => 'yd']);
    $s = preg_replace('/\s+([,.);\]])/', '$1', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));

    // bare full-value number -> aspect default unit (only for unambiguous in/lb families)
    if ($fam !== null && preg_match('/^' . $num . '$/', $s)) {
        $s = unitNormalizerFmtNum($s) . ' ' . $fam;
    }
    // bare "N x N (x N)" with no unit anywhere -> default unit per number (in-family only)
    elseif ($fam === 'in' && preg_match('/^' . $num . '(?:\s*[xX]\s*' . $num . ')+$/', $s)) {
        preg_match_all('/' . $num . '/', $s, $nm);
        $s = implode(' x ', array_map(fn($n) => unitNormalizerFmtNum($n) . ' in', $nm[0]));
    }

    return [$s, $s !== $v];
}
