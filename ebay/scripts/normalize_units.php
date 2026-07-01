<?php
declare(strict_types=1);
/**
 * Normalize UNIT MEASUREMENTS in eBay item-specific values to short abbreviations,
 * per the inventory team's upload rules. Produces a REVIEW copy of the sheet with a
 * `unit_changed` (Yes/blank) flag + before/after columns so the change set is auditable.
 *
 * Rules:
 *  - inches: 10", 10 inches, 10 inch, 14-inches  -> 10 in
 *  - feet:   6 feet, 6'                            -> 6 ft
 *  - pounds: 2 pounds, 2 lbs, 2.00                 -> 2 lb   (bare number on a WEIGHT aspect)
 *  - ounces: 5oz, 5 ounces                         -> 5 oz
 *  - fluid ounces: fluid ounce / Capacity's oz     -> fl oz
 *      * disambiguation: fluid wording => fl oz; else a Capacity/Volume aspect => fl oz;
 *        otherwise plain oz.
 *  - grams/kg/ml/L/qt/gal/mm/cm/yd standardized to abbreviations
 *  - number cleanup: 1.00 -> 1, 0.250 -> 0.25 (trailing-zero strip; fractions like 1/8 kept)
 *  - dimensions: 35" x 71" -> 35 in x 71 in (each token normalized)
 *  - bare-number unit inference by aspect: length-like -> in, weight-like -> lb
 *
 * SCOPE: only MEASUREMENT aspects are touched by default (Size/Length/Width/Height/Depth/
 * Thickness/Weight/Diameter/Capacity/Volume/Dimension/Mesh/etc.), excluding prose aspects
 * (Features/Set Includes/Description/Contents). Pass --all-aspects to process every aspect.
 *
 * SAFETY:
 *  - "per inch" / "/inch" density rates are NOT converted.
 *  - SELECTION_ONLY aspects change only when the result still exists in the allowed list
 *    (protects eBay buckets like `4" or Less`); unknown/truncated lists are left as-is.
 *
 * Usage:
 *   php normalize_units.php --account=dows
 *   php normalize_units.php --account=ige --input=path/to/ethans_returned.csv
 *   php normalize_units.php --account=dows --all-aspects
 */

$opts = getopt('', ['account:', 'input:', 'out:', 'all-aspects']);
$account = $opts['account'] ?? 'dows';
$allAspects = array_key_exists('all-aspects', $opts);
$base = dirname(__DIR__);
$input = $opts['input'] ?? "$base/data/$account/output/review_sheet.csv";
$out   = $opts['out']   ?? "$base/data/$account/output/unit_normalized_review.csv";
if (!is_file($input)) { fwrite(STDERR, "input not found: $input\n"); exit(1); }

/* ---------- which aspects are in scope ---------- */
function isTargetAspect(string $aspect, bool $all): bool {
  if ($all) return true;
  $a = strtolower($aspect);
  foreach (['feature','description','content','set include','instruction','warranty'] as $deny)
    if (strpos($a, $deny) !== false) return false;
  $inc = ['size','length','width','height','depth','thick','weight','diameter','capacity',
    'volume','dimension','gauge','mesh','arbor','canopy','disc','cable','band','inseam',
    'waist','span','reach','blade'];
  foreach ($inc as $k) if (strpos($a, $k) !== false) return true;
  return false;
}

/* ---------- aspect -> default unit for BARE-NUMBER inference ---------- */
$LENGTH_IN = ['size','length','width','height','depth','thickness','diameter',
  'blade length','closed length','open length','overall length','handle length','item length',
  'item width','item height','item depth','item diameter','bag height','bag width','bag depth',
  'disc diameter','canopy width','canopy length','arbor size','item size'];
$WEIGHT_LB = ['weight','item weight','product weight','total weight'];

function aspectUnit(string $aspect, array $LEN, array $WT): ?string {
  $a = strtolower(trim($aspect));
  if (in_array($a, $WT, true)) return 'lb';
  if (in_array($a, $LEN, true)) return 'in';
  return null;
}
function fmtNum(string $n): string {
  if (strpos($n, '.') !== false) { $n = rtrim($n, '0'); $n = rtrim($n, '.'); }
  return $n === '' ? '0' : $n;
}

/* ---------- the normalizer ---------- */
function normalizeUnits(string $orig, string $aspect, array $LEN, array $WT): array {
  $v = trim($orig);
  if ($v === '') return [$orig, false, ''];
  $low = strtolower($v);
  $skip = ['blank_value','assorted','n/a','na','none','multi','multi-color','multicolor',
    'enter numeric value.','various','see description','one size'];
  if (in_array($low, $skip, true)) return [$orig, false, ''];

  $al = strtolower($aspect);
  $wantFlOz = (strpos($al,'capacity')!==false || strpos($al,'volume')!==false || strpos($al,'liquid')!==false);

  $s = ' ' . $v . ' ';
  // fluid ounce first
  $s = preg_replace('/\bfl(?:uid)?\.?\s*(?:oz|ounces?)\b/i', ' flozUNIT ', $s);
  // symbols attached to a number
  $s = preg_replace('/(\d)\s*"/', '$1 inUNIT ', $s);                 // 6" -> 6 in
  $s = preg_replace("/(\d)\s*'(?!\w)/", '$1 ftUNIT ', $s);           // 6' -> 6 ft
  // 14-inches -> 14 in
  $s = preg_replace('/(\d)\s*-\s*(?:inches|inch)\b/i', '$1 inUNIT ', $s);
  // word units -> temp tokens (guard "per inch" / "/inch" rate wording for inch & foot)
  $s = preg_replace('#(?<!/)(?<!per )\b(?:inches|inch|in\.)\b#i', ' inUNIT ', $s);
  $s = preg_replace('#(?<!/)(?<!per )\b(?:feet|foot|ft\.)\b#i', ' ftUNIT ', $s);
  $wordMap = [
    '/\b(?:pounds|pound|lbs|lb\.)\b/i'     => ' lbUNIT ',
    '/\b(?:ounces|ounce|oz\.)\b/i'         => ' ozUNIT ',
    '/\b(?:kilograms|kilogram)\b/i'        => ' kgUNIT ',
    '/\b(?:grams|gram)\b/i'                => ' gUNIT ',
    '/\b(?:milliliters|milliliter|millilitre)\b/i' => ' mlUNIT ',
    '/\b(?:liters|liter|litre)\b/i'        => ' LUNIT ',
    '/\b(?:quarts|quart)\b/i'              => ' qtUNIT ',
    '/\b(?:gallons|gallon)\b/i'            => ' galUNIT ',
    '/\b(?:millimeters|millimeter|millimetre)\b/i' => ' mmUNIT ',
    '/\b(?:centimeters|centimeter|centimetre)\b/i' => ' cmUNIT ',
    '/\b(?:yards|yard)\b/i'                => ' ydUNIT ',
  ];
  $s = preg_replace(array_keys($wordMap), array_values($wordMap), $s);
  // glued abbreviations: 5oz, 100g, 4in, 15L (longest first)
  foreach (['mm','cm','ml','kg','gal','qt','yd','in','ft','lb','oz','g','L'] as $u)
    $s = preg_replace('/(\d)\s*'.preg_quote($u,'/').'\b/', '$1 '.$u.'UNIT ', $s);
  // oz -> fl oz for capacity/volume aspects (word-boundary so it doesn't hit flozUNIT)
  if ($wantFlOz) $s = preg_replace('/\bozUNIT\b/', 'fl oz ', $s);
  // canonicalize temp tokens
  $s = strtr($s, ['flozUNIT'=>'fl oz','inUNIT'=>'in','ftUNIT'=>'ft','lbUNIT'=>'lb','ozUNIT'=>'oz',
    'kgUNIT'=>'kg','gUNIT'=>'g','mlUNIT'=>'ml','LUNIT'=>'L','qtUNIT'=>'qt','galUNIT'=>'gal',
    'mmUNIT'=>'mm','cmUNIT'=>'cm','ydUNIT'=>'yd']);
  // number cleanup
  $s = preg_replace_callback('/\d+\.\d+/', fn($m) => fmtNum($m[0]), $s);
  // tidy whitespace + no space before punctuation
  $s = preg_replace('/\s+([,.);\]])/', '$1', $s);
  $s = trim(preg_replace('/\s+/', ' ', $s));

  // bare-number inference
  if (preg_match('/^\d+(?:\.\d+)?$/', $s)) {
    $unit = aspectUnit($aspect, $LEN, $WT);
    if ($unit) $s .= ' ' . $unit;
  }
  // tag the fl-oz heuristic so the reviewer can confirm it isn't a weight
  $note = ($wantFlOz && stripos($v,'fl')===false && stripos($s,'fl oz')!==false)
        ? 'oz->fl oz per Capacity/Volume rule — verify not weight' : '';
  return [$s, $s !== $v, $note];
}

/* ---------- allowed-list parsing (SELECTION_ONLY guard) ---------- */
function parseAllowed(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return ['known'=>false, 'set'=>[]];
  $truncated = (substr($raw, -3) === '...');
  $set = [];
  foreach (array_filter(array_map('trim', explode('|', rtrim($raw, '.| ')))) as $p)
    $set[strtolower($p)] = $p;
  return ['known'=>!$truncated, 'set'=>$set];
}

/* ---------- drive the file ---------- */
$fh = fopen($input, 'r');
$header = fgetcsv($fh);
$idx = array_flip($header);
foreach (['aspect','mode','current_value','proposed_value','approved_value','allowed_values'] as $r)
  if (!isset($idx[$r])) { fwrite(STDERR, "missing column: $r\n"); exit(1); }

$outHeader = array_merge($header, ['effective_value','effective_source','unit_normalized_value','unit_changed','unit_note']);
$ofh = fopen($out, 'w');
fputcsv($ofh, $outHeader);

$rows=0; $changed=0; $skippedSel=0; $touchedAspects=[]; $samples=[]; $skips=[];
while (($row = fgetcsv($fh)) !== false) {
  $rows++;
  $aspect = $row[$idx['aspect']] ?? '';
  $mode   = $row[$idx['mode']] ?? '';
  $appr = trim($row[$idx['approved_value']] ?? '');
  $prop = trim($row[$idx['proposed_value']] ?? '');
  $curr = trim($row[$idx['current_value']] ?? '');
  if (strtolower($prop) === 'blank_value') $prop = '';
  if (strtolower($appr) === 'blank_value') $appr = '';

  $eff=''; $src='';
  if ($appr!=='')      { $eff=$appr; $src='approved'; }
  elseif ($prop!=='')  { $eff=$prop; $src='proposed'; }
  elseif ($curr!=='')  { $eff=$curr; $src='current';  }

  $normVal=$eff; $flag=''; $note='';
  if ($eff !== '' && isTargetAspect($aspect, $allAspects)) {
    [$cand, $chg, $cnote] = normalizeUnits($eff, $aspect, $LENGTH_IN, $WEIGHT_LB);
    if ($chg) {
      $al = parseAllowed($row[$idx['allowed_values']] ?? '');
      $effIsRecommended = $al['known'] && isset($al['set'][strtolower($eff)]);
      $bucketLike = (bool)preg_match('/\b(or less|or more|or older|and up|& up|under|over)\b/i', $eff)
                 || (bool)preg_match('/\d\s*-\s*\d/', $eff); // numeric range like "2.76 - 4in."
      if ($effIsRecommended) {
        // value is already an eBay-canonical/recommended value (bucket) -> don't break the filter match
        $note='kept: already an eBay-recommended value'; $skippedSel++;
      } elseif ($bucketLike) {
        $note='review: range/threshold phrasing — left as-is'; $skippedSel++;
      } elseif (strtoupper($mode) === 'SELECTION_ONLY') {
        if (!$al['known'])                          { $note='review: SELECTION_ONLY allowed-list unknown/truncated'; $skippedSel++; }
        elseif (!isset($al['set'][strtolower($cand)])) { $note='kept: normalized value not in allowed list'; $skippedSel++; }
        else { $normVal=$cand; $flag='Yes'; $note=$cnote; }
      } else { $normVal=$cand; $flag='Yes'; $note=$cnote; }
    }
  }

  if ($flag==='Yes') {
    $changed++; $touchedAspects[$aspect]=($touchedAspects[$aspect]??0)+1;
    if (count($samples)<45) $samples[]=[$aspect,$eff,$normVal,$src];
  } elseif ($note!=='' && count($skips)<15) {
    $skips[]=[$aspect,$eff,$note];
  }
  fputcsv($ofh, array_merge($row, [$eff,$src,$normVal,$flag,$note]));
}
fclose($fh); fclose($ofh);

echo "account: $account   mode: ".($allAspects?'ALL aspects':'measurement aspects only')."\n";
echo "input:   $input\nrows:    $rows\n";
echo "unit_changed = Yes: $changed\n";
echo "SELECTION_ONLY left untouched (safety): $skippedSel\n";
echo "distinct aspects touched: ".count($touchedAspects)."\n";
echo "out:     $out\n\n";
echo "--- sample changes (aspect | before -> after | source) ---\n";
foreach ($samples as $s) printf("  %-20s %-24s -> %-24s [%s]\n", substr($s[0],0,20), substr($s[1],0,24), substr($s[2],0,24), $s[3]);
if ($skips) { echo "\n--- SELECTION_ONLY skips ---\n"; foreach ($skips as $s) printf("  %-18s %-18s %s\n", substr($s[0],0,18), substr($s[1],0,18), $s[2]); }
echo "\n--- aspects touched (name: count) ---\n";
arsort($touchedAspects);
foreach (array_slice($touchedAspects,0,40,true) as $a=>$c) printf("  %-26s %d\n", $a, $c);
