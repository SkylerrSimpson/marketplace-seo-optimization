<?php

declare(strict_types=1);

/**
 * Plan the next Usurper export pass(es). After aspect_field_map.json gains new
 * column mappings, this finds every mapped column we have NOT yet exported for an
 * account, scores each by how many still-blank gaps it could fill (demand =
 * sum of blank gaps of the aspects that reference it), and packs them into
 * <=22-column passes (3 reserved for the sku / parent.sku / name join keys).
 *
 * Read-only. Usage: php ebay/scripts/plan_next_pass.php --account=dows
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['account:', 'cap:']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$cap     = (int) ($opts['cap'] ?? 22);
$dataCap = $cap - 3;   // sku, parent.sku, name always selected
$dir     = ebay_dir($account, 'output');

function nrm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }

$map = json_decode((string) file_get_contents(__DIR__ . '/../data/aspect_field_map.json'), true)['usurper'];

// columns already exported for this account
$exp = [];
foreach (glob(ebay_dir($account, 'input') . '/*.csv') as $p) {
    $fh = fopen($p, 'r'); $h = fgetcsv($fh); fclose($fh);
    foreach ($h ?: [] as $c) { $exp[strtolower(trim($c))] = true; }
}

// blank-gap demand per aspect
$dem = [];
$f = fopen($dir . '/review_sheet.csv', 'r'); $h = fgetcsv($f); $ix = array_flip($h);
while (($r = fgetcsv($f)) !== false) {
    if ($r[$ix['source']] !== 'none') { continue; }
    $dem[nrm($r[$ix['aspect']])] = ($dem[nrm($r[$ix['aspect']])] ?? 0) + 1;
}
fclose($f);

// for each blank aspect, take only the TOP-priority column we have NOT exported yet
// (one representative per aspect -> a pass covers the most distinct aspects).
$colScore = [];     // unexported column -> demand credit
$colAspects = [];   // unexported column -> [aspects it would serve]
foreach ($dem as $aspect => $gaps) {
    if (!isset($map[$aspect])) { continue; }
    foreach ((array) $map[$aspect] as $col) {
        if (isset($exp[strtolower($col)])) { continue; }   // skip already-pulled, try next fallback
        $colScore[$col]   = ($colScore[$col] ?? 0) + $gaps;
        $colAspects[$col][] = $aspect;
        break;   // first unexported column is the representative for this aspect
    }
}
arsort($colScore);

if (!$colScore) { echo "No new columns to pull for {$account} — every mapped column is already exported.\n"; exit(0); }

// pack into passes of $dataCap columns
$cols = array_keys($colScore);
$passes = array_chunk($cols, $dataCap);

echo "=== {$account}: next Usurper export pass plan ===\n";
echo "Join keys to ALWAYS select: sku, parent.sku (Parent SKU), name\n";
echo count($cols) . " new columns across " . count($passes) . " pass(es) (cap {$cap}/pass)\n\n";
foreach ($passes as $i => $chunk) {
    $pn = $i + 5;   // Pass 5, 6, ...
    $tot = 0; foreach ($chunk as $c) { $tot += $colScore[$c]; }
    echo "--- Pass {$pn}  (" . count($chunk) . " data cols, ~{$tot} blank gaps targeted) ---\n";
    foreach ($chunk as $c) {
        printf("  %-32s  demand %-4d  serves: %s\n", $c, $colScore[$c], implode(', ', array_unique($colAspects[$c])));
    }
    echo "\n";
}
echo "Paste the Pass-5 columns into Usurper for each batch, name the file BatchXPass5.csv,\n";
echo "drop in {$account}/input/, then: php ebay/scripts/fill_aspects.php --account={$account}\n";
