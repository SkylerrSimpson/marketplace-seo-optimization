<?php

declare(strict_types=1);

/**
 * Build ONE human review sheet per account: every eBay LISTING, every aspect —
 * both the values currently live on eBay AND the recommended/required gaps we
 * propose to fill. The inventory team verifies the current values and fills the
 * proposed/blank ones.
 *
 * Inputs (ebay/data/{acct}/output/):
 *   items/{item_id}.json      — per-listing CURRENT item specifics (live on eBay)
 *   listings.json             — every listing: parent sku + variation children
 *   aspect_gaps_worklist.csv  — every (listing x MISSING aspect)
 *   proposed_fills.csv        — deterministic Usurper/rule/default fills (high trust)
 *   proposed_fills_deep.csv   — LLM proposals for leftover gaps (+ certainty %)
 *   ../aspects/{cat}.json      — category schema: mode, allowed values, variation flag
 *
 * Output: review_sheet.csv — one row per (listing x aspect), columns:
 *   item_id, sku, varied_by, name, category_id, aspect, mode, cardinality,
 *   source, certainty, current_value, proposed_value, approved_value,
 *   reviewer_notes, allowed_values, title
 *
 *   - current_value : what is live on eBay now (blank for a gap we're proposing)
 *   - proposed_value: our fill for a gap (blank for an already-live aspect)
 *   - varied_by     : on a variation child row, the aspect that child varies on
 *   - source        : current | usurper | rule | default | llm | none | variation
 *
 * sku + name are filled on EVERY row. Read-only against eBay.
 *
 * --mode=worksheet (default): current state + gap-fill proposals + blank
 *   approved_value/reviewer_notes for a human reviewer — full review_sheet.csv.
 * --mode=read: current state only. No gap rows (nothing proposed to fill), and
 *   no LLM-suspect-value overlay on current rows — just what's live right now.
 *
 * Usage:
 *   php ebay/scripts/build_review_sheet.php --account=dows
 *   php ebay/scripts/build_review_sheet.php --account=dows --mode=read
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['account:', 'mode:']);
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$mode    = strtolower((string) ($opts['mode'] ?? 'worksheet'));
if (!in_array($mode, ['read', 'worksheet'], true)) {
    fwrite(STDERR, "--mode must be 'read' or 'worksheet' (got '{$mode}')\n");
    exit(1);
}
$dir     = ebay_dir($account, 'output');
$itemsD  = $dir . '/items';
$schemaD = __DIR__ . '/../data/aspects';

function nrm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }
function stripColon(string $s): string { return rtrim(trim($s), ':'); }

function readCsv(string $path): array
{
    $rows = [];
    if (!is_file($path)) { return $rows; }
    $fh = fopen($path, 'r'); $h = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) { $rows[] = array_combine($h, $r); }
    fclose($fh);
    return $rows;
}

/** "Color=Black; Size=25 ft" -> [['Color','Black'],['Size','25 ft']] */
function parseSpecifics(string $s): array
{
    $out = [];
    foreach (explode(';', $s) as $pair) {
        if (strpos($pair, '=') === false) { continue; }
        [$k, $v] = explode('=', $pair, 2);
        $k = trim($k); $v = trim($v);
        if ($k !== '') { $out[] = [$k, $v]; }
    }
    return $out;
}

// ---- category schema cache: cat -> nrm(aspect) -> {name,mode,card,required,allowed,for_var} ----
$schemaCache = [];
function schema(string $cat, string $dir, array &$cache): array
{
    if (isset($cache[$cat])) { return $cache[$cat]; }
    $map = []; $f = "$dir/$cat.json";
    if (is_file($f)) {
        $d = json_decode((string) file_get_contents($f), true);
        foreach ($d['aspects'] ?? [] as $a) {
            $vals = $a['values'] ?? [];
            $map[nrm($a['name'])] = [
                'name'     => $a['name'],
                'mode'     => $a['mode'] ?? '',
                'card'     => $a['cardinality'] ?? '',
                'required' => !empty($a['required']),
                'allowed'  => $vals ? implode(' | ', $vals) : '',
                'for_var'  => !empty($a['for_variations']),
            ];
        }
    }
    return $cache[$cat] = $map;
}

function clip(string $s, int $n = 300): string { return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 3) . '...' : $s; }

// ---- every listing: parent sku + variation children -------------------------
$pSku = []; $variations = [];
foreach (json_decode((string) file_get_contents($dir . '/listings.json'), true) as $l) {
    $id = (string) $l['item_id'];
    $pSku[$id] = (string) ($l['sku'] ?? '');
    if (!empty($l['variations'])) { $variations[$id] = $l['variations']; }
}

// ---- product name: prefer proposed_fills' cleaned name, fall back to title ---
$pName = [];
foreach (readCsv($dir . '/proposed_fills.csv') as $r) {
    $id = $r['item_id'];
    if (!empty($r['name']) && !isset($pName[$id])) { $pName[$id] = $r['name']; }
}

// ---- proposals indexed by item_id|nrm(aspect) -------------------------------
$det = []; // deterministic: usurper / rule / default
foreach (readCsv($dir . '/proposed_fills.csv') as $r) {
    if (($r['status'] ?? '') !== 'ok') { continue; }
    $det[$r['item_id'] . '|' . nrm($r['aspect'])] = ['value' => $r['proposed_value'], 'source' => $r['source'] ?: 'usurper'];
}
$llm = []; // deep LLM
foreach (readCsv($dir . '/proposed_fills_deep.csv') as $r) {
    if (($r['status'] ?? '') !== 'ok') { continue; }
    $llm[$r['item_id'] . '|' . nrm($r['aspect'])] = ['value' => $r['proposed_value'], 'certainty' => $r['certainty']];
}

// ---- gaps grouped by listing ------------------------------------------------
$gapsByItem = [];
foreach (readCsv($dir . '/aspect_gaps_worklist.csv') as $r) {
    $gapsByItem[$r['item_id']][] = $r;
}

// ---- LLM checks of the live values --------------------------------------------
// current_value_checks.csv carries only the SUSPECT values (item_id|aspect ->
// suggestion). A product whose item_id appears in current_check_answers.jsonl was
// reviewed in full → its other current values are confirmed-correct (certainty 100).
$checks = [];
foreach (readCsv($dir . '/current_value_checks.csv') as $r) {
    $checks[$r['item_id'] . '|' . nrm($r['aspect'])] = $r;
}
$reviewed = [];
foreach (@file($dir . '/current_check_answers.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
    $j = json_decode($ln, true);
    if ($j && isset($j['item_id'])) { $reviewed[(string) $j['item_id']] = true; }
}

// ---- walk every listing, emit current + gap + variation rows ----------------
$rows = [];
$stat = ['current' => 0, 'usurper_rule_default' => 0, 'llm' => 0, 'blank' => 0, 'variation' => 0, 'listings' => 0];

foreach ($pSku as $id => $parentSku) {
    $stat['listings']++;
    $itemF  = "$itemsD/$id.json";
    $item   = is_file($itemF) ? json_decode((string) file_get_contents($itemF), true) : [];
    $cat    = (string) ($item['category_id'] ?? ($gapsByItem[$id][0]['category_id'] ?? ''));
    $title  = (string) ($item['title'] ?? '');
    $name   = $pName[$id] ?? $title;
    $sch    = schema($cat, $schemaD, $schemaCache);
    $isGroup = !empty($item['is_group']) || isset($variations[$id]);

    // aspects this listing varies on -> handled by child rows, not parent rows
    $variedNrm = [];
    if (isset($variations[$id])) {
        foreach ($variations[$id] as $v) {
            foreach (parseSpecifics((string) ($v['specifics'] ?? '')) as [$k, $_]) { $variedNrm[nrm($k)] = true; }
        }
    }

    $emit = function (array $f) use (&$rows, $id, $parentSku, $name, $cat, $title) {
        $rows[] = $f + [
            'item_id' => $id, 'sku' => $parentSku, 'varied_by' => '', 'name' => $name,
            'category_id' => $cat, 'mode' => '', 'cardinality' => '', 'certainty' => '',
            'current_value' => '', 'proposed_value' => '', 'reviewer_notes' => '',
            'allowed_values' => '', 'title' => $title, 'required' => false, 'child' => '',
        ];
    };

    // A) CURRENT aspects (live on eBay). Skip the varied dimensions on a group.
    // If the LLM check flagged a live value as suspect, surface its suggestion in
    // proposed_value + the reason in reviewer_notes so the reviewer can adjudicate.
    $currentNrm = [];
    foreach (($item['aspects'] ?? []) as $aname => $aval) {
        $an = nrm(stripColon((string) $aname));
        if ($isGroup && isset($variedNrm[$an])) { continue; }
        $currentNrm[$an] = true;
        $s = $sch[$an] ?? null;
        $proposed = ''; $cert = ''; $note = '';
        if ($mode === 'worksheet') {
            $chk = $checks[$id . '|' . $an] ?? null;
            if ($chk) {
                if ($chk['verdict'] === 'suspect') {        // LLM not sure the live value is right
                    $proposed = $chk['suggested_value'];     // may be '' when no better value exists
                    $cert     = $chk['certainty'];
                    $note     = 'LLM: ' . ($chk['reason'] !== '' ? $chk['reason'] : 'live value looks off');
                } else {
                    $cert = 100;                             // verdict=ok → checked & confirmed
                }
            } elseif (isset($reviewed[$id])) {
                $cert = 100;                                 // product reviewed, this value not flagged
            }
        }
        $emit([
            'aspect'         => stripColon((string) $aname),
            'mode'           => $s['mode'] ?? '',
            'cardinality'    => $s['card'] ?? '',
            'source'         => 'current',
            'certainty'      => $cert,
            'current_value'  => (string) $aval,
            'proposed_value' => $proposed,
            'reviewer_notes' => $note,
            'allowed_values' => clip($s['allowed'] ?? ''),
            'required'       => $s['required'] ?? false,
        ]);
        $stat['current']++;
    }

    // B) GAP aspects we propose to fill. Skip varied dims + anything already live.
    // Worksheet mode only — a pure read sheet only shows what's actually live.
    if ($mode === 'worksheet') {
        foreach ($gapsByItem[$id] ?? [] as $g) {
            $an = nrm($g['aspect']);
            if ($isGroup && isset($variedNrm[$an])) { continue; }
            if (isset($currentNrm[$an])) { continue; }
            $k = $id . '|' . $an;
            $value = ''; $source = 'none'; $cert = '';
            if (isset($det[$k]))      { $value = $det[$k]['value']; $source = $det[$k]['source']; $cert = 100; $stat['usurper_rule_default']++; }
            elseif (isset($llm[$k]))  { $value = $llm[$k]['value']; $source = 'llm'; $cert = $llm[$k]['certainty']; $stat['llm']++; }
            else                      { $stat['blank']++; }
            $s = $sch[$an] ?? null;
            $allowed = $s['allowed'] ?? '';
            if ($allowed === '') { $allowed = (string) ($g['values_sample'] ?? ''); }
            $emit([
                'aspect'         => $g['aspect'],
                'mode'           => $g['mode'] ?? ($s['mode'] ?? ''),
                'cardinality'    => $g['cardinality'] ?? ($s['card'] ?? ''),
                'source'         => $source,
                'certainty'      => $cert,
                'proposed_value' => $value,
                'allowed_values' => clip($allowed),
                'required'       => ($g['importance'] ?? '') === 'required',
            ]);
        }
    }

    // C) VARIATION children: one row per child per varied aspect, value = child's.
    foreach ($variations[$id] ?? [] as $v) {
        $childSku = (string) ($v['sku'] ?? '');
        foreach (parseSpecifics((string) ($v['specifics'] ?? '')) as [$ak, $av]) {
            $an = nrm($ak); $s = $sch[$an] ?? null;
            $rows[] = [
                'item_id' => $id, 'sku' => $childSku, 'varied_by' => $ak, 'name' => $name,
                'category_id' => $cat, 'aspect' => $ak,
                'mode' => $s['mode'] ?? '', 'cardinality' => $s['card'] ?? '',
                'source' => 'variation', 'certainty' => '',
                'current_value' => $av, 'proposed_value' => '', 'reviewer_notes' => '',
                'allowed_values' => clip($s['allowed'] ?? ''), 'title' => $title,
                'required' => false, 'child' => $childSku,
            ];
            $stat['variation']++;
        }
    }
}

// sort: listing, parent rows before child rows, child grouped by sku,
// required-first within a group, then aspect — reviewer goes top to bottom
usort($rows, function ($a, $b) {
    return [$a['item_id'], $a['child'], $a['required'] ? 0 : 1, $a['aspect']]
       <=> [$b['item_id'], $b['child'], $b['required'] ? 0 : 1, $b['aspect']];
});

$out = fopen($dir . '/review_sheet.csv', 'w');
fputcsv($out, [
    'item_id', 'sku', 'varied_by', 'name', 'category_id', 'aspect', 'mode', 'cardinality',
    'source', 'certainty', 'current_value', 'proposed_value', 'approved_value',
    'reviewer_notes', 'allowed_values', 'title',
]);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['item_id'], $r['sku'], $r['varied_by'], $r['name'], $r['category_id'], $r['aspect'],
        $r['mode'], $r['cardinality'], $r['source'], $r['certainty'], $r['current_value'],
        $r['proposed_value'], '', $r['reviewer_notes'], $r['allowed_values'], $r['title'],
    ]);
}
fclose($out);

$tot = count($rows);
printf("wrote %s/review_sheet.csv (mode=%s)\n", $dir, $mode);
printf("  %d rows across %d listings\n", $tot, $stat['listings']);
printf("  current(live): %d  variation: %d  proposed usurper/rule/default: %d  llm: %d  blank gap: %d\n",
    $stat['current'], $stat['variation'], $stat['usurper_rule_default'], $stat['llm'], $stat['blank']);
