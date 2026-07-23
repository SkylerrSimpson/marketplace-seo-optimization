<?php

declare(strict_types=1);

/**
 * Triage every still-blank aspect (source=none in review_sheet.csv) into an action
 * bucket so the team knows WHY it is blank and what (if anything) can fill it:
 *
 *   already_default   - has a safe constant default (shouldn't be blank; sanity check)
 *   usurper_no_value  - field-map column WAS exported but Usurper had no value here
 *                       (re-export won't help; LLM already declined -> leave or hand-fill)
 *   supplier_spec     - exact spec/dimension/regulatory an LLM cannot guess and Usurper
 *                       does not track (MPN, dimensions, warranty, EPA#, expiration...) -> LEAVE OUT
 *   collector_na      - collectible/authentication aspect that doesn't apply to new retail -> LEAVE OUT
 *   personalization   - conditional on Personalize=Yes (we default No) -> LEAVE OUT
 *   candidate_export  - plausibly has a Usurper column we never mapped/pulled -> CHECK PICKER
 *
 * Output: marketplaces/ebay/data/{acct}/output/blank_triage.csv (aspect, gap_count, category,
 *         action, candidate_column). Read-only. Usage: --account=dows
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['account:']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');

function nrm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }

$map = json_decode((string) file_get_contents(__DIR__ . '/../data/aspect_field_map.json'), true);
$U = $map['usurper']; $D = $map['defaults'];

// columns already exported across every input pass for this account
$exp = [];
foreach (glob(ebay_dir($account, 'input') . '/*.csv') as $p) {
    $fh = fopen($p, 'r'); $h = fgetcsv($fh); fclose($fh);
    foreach ($h ?: [] as $c) { $exp[strtolower(trim($c))] = true; }
}

// blank aspect -> gap count
$blank = [];
$f = fopen($dir . '/review_sheet.csv', 'r'); $h = fgetcsv($f); $ix = array_flip($h);
while (($r = fgetcsv($f)) !== false) {
    if ($r[$ix['source']] !== 'none') { continue; }
    $blank[$r[$ix['aspect']]] = ($blank[$r[$ix['aspect']]] ?? 0) + 1;
}
fclose($f);
arsort($blank);

// regex buckets for the un-guessable / not-applicable aspects
$specRe      = '/\b(mpn|diameter|depth|width|height|length|volume|weight|gauge|caliber|thickness|lumens|operating (time|range)|tensile|spf|sun protection|pao|period after opening|ip rating|r-value|tog|gsm|grams per square|girth|loft|spin|torque|kickpoint|lie angle|launch|bounce|head mass|horsepower|engine size|amp hours|breaking strength|working load|hardness|magnification|diopter|water resistance|waterproof rating|fire rating|security rating|decibel|cycle time|throwing distance|flow rate|tank capacity|thread count|metal purity|max wrist|case size|wattage|amperage|voltage rating|current rating|power consumption|color temperature|bulb life|resolution|display size|teeth per inch|number of (batteries|leds|lights|jewels|diamonds|teeth|holes|sides|channels|rounds|pages|strands)|epa|registration number|expiration|date code|net weight|operating temperature)\b/i';
$collectorRe = '/\b(signed|autograph|coa|certificate of authenticity|authentication|featured (person|artist)|^artist$|culture|time period|year of (production|manufacture)|grade|certification number|provenance|main stone|stone (shape|treatment|creation)|backstamp|reference number|with (papers|original box|manual)|production (style|technique)|professional grader|edition|publisher|illustrator|literary|publication year|book series|game title|award|movement|escapement|caseback|dial|bezel|indices|number of jewels)\b/i';
$persRe      = '/\bpersonaliz/i';
$warrantyRe  = '/\bwarranty\b/i';
$candThreshold = 5;   // candidate_export only if frequent enough to justify a whole new Usurper pull

// best-guess Usurper column for the few unmapped aspects worth a picker check
$candidateCol = [
    'product line' => 'product_line / product_line_amazon',
    'series'       => 'series / series_amazon',
    'vehicle make' => 'vehicle_make / make_amazon',
    'connection type' => 'connection_type_amazon',
    'mounting type'   => 'mounting_type / mounting_type_amazon',
    'scale'        => 'scale_amazon',
    'wattage'      => 'wattage_amazon',
];

$rows = []; $tally = [];
foreach ($blank as $aspect => $cnt) {
    $n = nrm($aspect);
    $cat = ''; $action = ''; $col = '';
    if (isset($D[$n])) {
        $cat = 'already_default'; $action = 'sanity-check fill_aspects (should not be blank)';
    } elseif (isset($U[$n])) {
        $cols = (array) $U[$n]; $anyExp = false;
        foreach ($cols as $c) { if (isset($exp[strtolower($c)])) { $anyExp = true; break; } }
        if ($warrantyRe && preg_match($warrantyRe, $aspect)) { $cat = 'supplier_spec'; $action = 'LEAVE OUT (warranty not in Usurper / supplier-only)'; }
        elseif ($anyExp)  { $cat = 'usurper_no_value'; $action = 'Usurper blank for these; LLM declined -> hand-fill or leave'; $col = implode('|', $cols); }
        else              { $cat = 'candidate_export'; $action = 'EXPORT (mapped column never pulled)'; $col = implode('|', $cols); }
    } elseif (preg_match($persRe, $aspect)) {
        $cat = 'personalization'; $action = 'LEAVE OUT (conditional on Personalize=Yes; we default No)';
    } elseif (preg_match($collectorRe, $aspect)) {
        $cat = 'collector_na'; $action = 'LEAVE OUT (collectible aspect; N/A for new retail)';
    } elseif (preg_match($specRe, $aspect)) {
        $cat = 'supplier_spec'; $action = 'LEAVE OUT (exact spec/dimension; not in inventory, LLM cannot guess)';
    } elseif ($cnt < $candThreshold) {
        $cat = 'long_tail_niche'; $action = 'LEAVE OUT (too few listings to justify a new column pull)';
    } else {
        $cat = 'candidate_export'; $action = 'CHECK USURPER PICKER for a matching column'; $col = $candidateCol[$n] ?? '';
    }
    $rows[] = [$aspect, $cnt, $cat, $action, $col];
    $tally[$cat] = ($tally[$cat] ?? 0) + $cnt;
}

$out = fopen($dir . '/blank_triage.csv', 'w');
fputcsv($out, ['aspect', 'gap_count', 'category', 'action', 'candidate_column']);
foreach ($rows as $r) { fputcsv($out, $r); }
fclose($out);

echo "wrote {$dir}/blank_triage.csv — " . count($rows) . " distinct blank aspects\n--- gaps by category ---\n";
arsort($tally);
foreach ($tally as $k => $v) { printf("  %-18s %5d gaps\n", $k, $v); }
