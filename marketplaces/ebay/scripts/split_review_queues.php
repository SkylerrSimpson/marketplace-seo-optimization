<?php

declare(strict_types=1);

/**
 * Split the consolidated review_sheet.csv into two focused queues for the
 * inventory team, so they don't review thousands of equal-looking rows:
 *
 *   hand_fill_queue.csv   - gaps with NO proposed value (source=none) that the
 *                           triage flagged candidate_export (a real aspect worth
 *                           filling by eye), grouped by category so similar
 *                           products are filled together. allowed_values shown
 *                           so SELECTION_ONLY picks are valid.
 *   llm_spotcheck_queue.csv - every LLM-proposed value, sorted by certainty ASC
 *                           (riskiest first; >=80 can be rubber-stamped).
 *
 * Both keep the approved_value / reviewer_notes columns for the reviewer.
 * Read-only. Usage: php ebay/scripts/split_review_queues.php --account=dows
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['account:']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');

function nrm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }

function readCsv(string $path): array
{
    $rows = []; if (!is_file($path)) { return $rows; }
    $fh = fopen($path, 'r'); $h = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) { $rows[] = array_combine($h, $r); }
    fclose($fh);
    return $rows;
}

// item_id -> {sku,name} from any populated row (source=none gaps don't carry it)
$idSku = []; $idName = [];
foreach (readCsv($dir . '/proposed_fills.csv') as $r) {
    $id = $r['item_id'];
    if (!empty($r['sku']))  { $idSku[$id]  = $idSku[$id]  ?? $r['sku']; }
    if (!empty($r['name'])) { $idName[$id] = $idName[$id] ?? $r['name']; }
}

// each blank aspect's triage category -> drives the fillability rating
$triageCat = [];
foreach (readCsv($dir . '/blank_triage.csv') as $r) { $triageCat[nrm($r['aspect'])] = $r['category'] ?? ''; }

// EASY = a person can read it straight off the product photo/title
$EASY = array_flip([
    'color', 'base color', 'manufacturer color', 'material', 'primary material', 'theme', 'pattern',
    'style', 'shape', 'department', 'handle material', 'blade material', 'blade color', 'frame color',
    'finish', 'type', 'product type', 'character', 'character family', 'gender', 'fabric type', 'closure',
    'number in pack', 'number of pieces', 'number of items in set', 'room', 'occasion', 'season',
]);

// rate how hard a blank is to fill: easy | medium | hard
function fillability(string $aspectNorm, string $cat, array $easy): string
{
    if (isset($easy[$aspectNorm]))                                         { return 'easy'; }
    if (in_array($cat, ['supplier_spec', 'collector_na', 'personalization'], true)) { return 'hard'; }
    return 'medium';   // candidate_export research / long_tail_niche / usurper_no_value not eyeball-able
}
$rank = ['easy' => 0, 'medium' => 1, 'hard' => 2];

$rows = readCsv($dir . '/review_sheet.csv');

// ---- hand-fill queue: EVERY blank gap, tagged easy/medium/hard ----
$hand = [];
foreach ($rows as $r) {
    if ($r['source'] !== 'none') { continue; }
    $an = nrm($r['aspect']);
    $r['fillability'] = fillability($an, $triageCat[$an] ?? '', $EASY);
    $hand[] = $r;
}
// sort easy-first, then by category so similar products group, then aspect
usort($hand, fn($a, $b) => [$rank[$a['fillability']], $a['category_id'], $a['aspect'], $a['item_id']]
                       <=> [$rank[$b['fillability']], $b['category_id'], $b['aspect'], $b['item_id']]);

$hf = fopen($dir . '/hand_fill_queue.csv', 'w');
fputcsv($hf, ['fillability', 'category_id', 'aspect', 'mode', 'item_id', 'sku', 'name', 'approved_value', 'reviewer_notes', 'allowed_values', 'title']);
foreach ($hand as $r) {
    $sku = $r['sku'] ?: ($idSku[$r['item_id']] ?? ''); $name = $r['name'] ?: ($idName[$r['item_id']] ?? '');
    fputcsv($hf, [$r['fillability'], $r['category_id'], $r['aspect'], $r['mode'], $r['item_id'], $sku, $name, '', '', $r['allowed_values'], $r['title']]);
}
fclose($hf);
$fc = array_count_values(array_column($hand, 'fillability'));

// ---- LLM spot-check queue: source=llm, certainty ASC ----
$llm = array_values(array_filter($rows, fn($r) => $r['source'] === 'llm'));
usort($llm, fn($a, $b) => [(int) $a['certainty'], $a['item_id'], $a['aspect']] <=> [(int) $b['certainty'], $b['item_id'], $b['aspect']]);

$lf = fopen($dir . '/llm_spotcheck_queue.csv', 'w');
fputcsv($lf, ['certainty', 'item_id', 'sku', 'name', 'category_id', 'aspect', 'mode', 'proposed_value', 'approved_value', 'reviewer_notes', 'allowed_values', 'title']);
foreach ($llm as $r) {
    $sku = $r['sku'] ?: ($idSku[$r['item_id']] ?? ''); $name = $r['name'] ?: ($idName[$r['item_id']] ?? '');
    fputcsv($lf, [$r['certainty'], $r['item_id'], $sku, $name, $r['category_id'], $r['aspect'], $r['mode'], $r['proposed_value'], '', '', $r['allowed_values'], $r['title']]);
}
fclose($lf);

$lo = count(array_filter($llm, fn($r) => (int) $r['certainty'] < 70));
echo "wrote {$dir}/hand_fill_queue.csv     — " . count($hand) . " blank gaps  (easy " . ($fc['easy'] ?? 0) . " / medium " . ($fc['medium'] ?? 0) . " / hard " . ($fc['hard'] ?? 0) . ")\n";
echo "wrote {$dir}/llm_spotcheck_queue.csv — " . count($llm) . " values (" . $lo . " under certainty 70 → review first)\n";
