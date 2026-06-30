<?php

declare(strict_types=1);

/**
 * DEEP LLM CHECK of the values ALREADY LIVE on eBay (source=current in the review
 * sheet). For every current item-specific, an LLM judges whether the live value is
 * correct for that product. Where it is NOT 100% sure the value is right, it
 * proposes a corrected value + a certainty %. Confirmed-correct values get no
 * suggestion (just marked checked). No Usurper / external lookups — judgment only
 * from the product title, category, and the value itself.
 *
 * Mirrors ai_fill_deep.php (tasks -> answers -> merge), resumable.
 *
 *   --tasks   Build current_check_tasks.jsonl — one product/line:
 *             {item_id, sku, name, title, category_id, category_path,
 *              current:[{aspect, value, mode, allowed:[...]|null}]}.
 *             Skips item_ids already present in current_check_answers.jsonl
 *             (resumable) unless --all. Current values read from items/{id}.json
 *             (the live snapshot); variation dimensions excluded (those are checked
 *             as variation rows, not parent currents) — same rule as the sheet.
 *   --merge   Read current_check_answers.jsonl (LLM output:
 *             {item_id, checks:[{aspect, ok:bool, value, certainty, reason}]}) ->
 *             validate any suggested SELECTION_ONLY value against the schema + the
 *             65-char cap -> write current_value_checks.csv. build_review_sheet.php
 *             folds that into the sheet (suggestion -> proposed_value on the
 *             current row; certainty=100 when confirmed correct).
 *
 * Read-only against eBay (dry-run). Usage:
 *   php ebay/scripts/ai_check_current.php --account=dows --tasks
 *   php ebay/scripts/ai_check_current.php --account=dows --merge
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts = getopt('', ['account:', 'tasks', 'merge', 'all', 'max-allowed:', 'limit:', 'help']);
if (isset($opts['help']) || (!isset($opts['tasks']) && !isset($opts['merge']))) {
    fwrite(STDOUT, "Usage: php ai_check_current.php --account=dows (--tasks | --merge)\n");
    exit(0);
}
$account    = strtolower((string) ($opts['account'] ?? 'dows'));
$dir        = ebay_dir($account, 'output');
$itemsD     = $dir . '/items';
$maxAllowed = (int) ($opts['max-allowed'] ?? 80);

function cnorm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }
function cstrip(string $s): string { return rtrim(trim($s), ':'); }

// schema: catId -> normAspect -> ['values'=>[normValue=>canonical], 'mode'=>..]
$schemaCache = [];
function cSchema(string $catId, array &$cache): array
{
    if (!array_key_exists($catId, $cache)) {
        $path = EBAY_ASPECTS . "/{$catId}.json"; $byAspect = [];
        if (is_file($path)) {
            foreach ((json_decode((string) file_get_contents($path), true) ?: [])['aspects'] ?? [] as $a) {
                $vals = [];
                foreach ($a['values'] ?? [] as $v) { $vals[cnorm((string) $v)] = (string) $v; }
                $byAspect[cnorm((string) $a['name'])] = ['values' => $vals, 'mode' => $a['mode'] ?? ''];
            }
        }
        $cache[$catId] = $byAspect;
    }
    return $cache[$catId];
}

function readCsvAssoc(string $path): array
{
    $rows = []; if (!is_file($path)) { return $rows; }
    $fh = fopen($path, 'r'); $h = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) { $rows[] = array_combine($h, $r); }
    fclose($fh);
    return $rows;
}

// parent sku + variation dimensions (excluded from parent current set)
$pSku = []; $variedNrm = [];
foreach (json_decode((string) file_get_contents($dir . '/listings.json'), true) as $l) {
    $id = (string) $l['item_id']; $pSku[$id] = (string) ($l['sku'] ?? '');
    foreach ($l['variations'] ?? [] as $v) {
        foreach (explode(';', (string) ($v['specifics'] ?? '')) as $pair) {
            if (strpos($pair, '=') === false) { continue; }
            [$k] = explode('=', $pair, 2);
            $variedNrm[$id][cnorm(trim($k))] = true;
        }
    }
}

// cleaned product name
$pName = [];
foreach (readCsvAssoc($dir . '/proposed_fills.csv') as $r) {
    if (!empty($r['name']) && !isset($pName[$r['item_id']])) { $pName[$r['item_id']] = $r['name']; }
}

// =============================== --tasks =====================================
if (isset($opts['tasks'])) {
    // resumable: skip products already answered
    $done = [];
    if (!isset($opts['all'])) {
        foreach (@file($dir . '/current_check_answers.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
            $j = json_decode($ln, true); if ($j && isset($j['item_id'])) { $done[(string) $j['item_id']] = true; }
        }
    }
    $limit = (int) ($opts['limit'] ?? 0);
    $tf = fopen($dir . '/current_check_tasks.jsonl', 'w'); $n = 0; $c = 0;
    foreach ($pSku as $id => $sku) {
        if (isset($done[$id])) { continue; }
        $itemF = "$itemsD/$id.json";
        $item  = is_file($itemF) ? json_decode((string) file_get_contents($itemF), true) : [];
        $aspects = $item['aspects'] ?? [];
        if (!$aspects) { continue; }
        $cat = (string) ($item['category_id'] ?? '');
        $isGroup = !empty($item['is_group']) || isset($variedNrm[$id]);
        $sc = cSchema($cat, $schemaCache);
        $cur = [];
        foreach ($aspects as $aname => $aval) {
            $an = cnorm(cstrip((string) $aname));
            if ($isGroup && isset($variedNrm[$id][$an])) { continue; }
            $meta = $sc[$an] ?? null;
            $allowed = null;
            if ($meta && ($meta['mode'] ?? '') === 'SELECTION_ONLY' && $meta['values'] && count($meta['values']) <= $maxAllowed) {
                $allowed = array_values($meta['values']);
            }
            $cur[] = ['aspect' => cstrip((string) $aname), 'value' => (string) $aval, 'mode' => $meta['mode'] ?? '', 'allowed' => $allowed];
        }
        if (!$cur) { continue; }
        fwrite($tf, json_encode([
            'item_id' => $id, 'sku' => $sku, 'name' => $pName[$id] ?? ($item['title'] ?? ''),
            'title' => $item['title'] ?? '', 'category_id' => $cat,
            'category_path' => $item['category_path'] ?? '', 'current' => $cur,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $n++; $c += count($cur);
        if ($limit && $n >= $limit) { break; }
    }
    fclose($tf);
    echo "wrote {$dir}/current_check_tasks.jsonl — {$n} products, {$c} live values to check" . (isset($opts['all']) ? "\n" : " (" . count($done) . " already answered, skipped)\n");
    echo "Append to current_check_answers.jsonl: {\"item_id\":\"..\",\"checks\":[{\"aspect\":\"..\",\"ok\":true|false,\"value\":\"<suggestion if !ok>\",\"certainty\":NN,\"reason\":\"..\"}]}\n";
    exit(0);
}

// =============================== --merge =====================================
if (isset($opts['merge'])) {
    $af = $dir . '/current_check_answers.jsonl';
    if (!is_file($af)) { fwrite(STDERR, "No current_check_answers.jsonl in {$dir}\n"); exit(1); }

    // current value + category per (item_id|aspect), from items/*.json
    $curVal = []; $curCat = [];
    foreach ($pSku as $id => $sku) {
        $item = is_file("$itemsD/$id.json") ? json_decode((string) file_get_contents("$itemsD/$id.json"), true) : [];
        $cat = (string) ($item['category_id'] ?? '');
        foreach ($item['aspects'] ?? [] as $aname => $aval) {
            $k = $id . '|' . cnorm(cstrip((string) $aname));
            $curVal[$k] = (string) $aval; $curCat[$k] = $cat;
        }
    }

    $out = fopen($dir . '/current_value_checks.csv', 'w');
    fputcsv($out, ['item_id', 'sku', 'category_id', 'aspect', 'current_value', 'verdict', 'suggested_value', 'certainty', 'status', 'reason']);
    $stat = ['ok' => 0, 'suspect' => 0, 'value_not_in_list' => 0, 'too_long' => 0, 'no_suggestion' => 0];
    foreach (file($af, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $rec = json_decode($line, true); if (!$rec || !isset($rec['item_id'])) { continue; }
        $id = (string) $rec['item_id'];
        foreach ($rec['checks'] ?? [] as $ch) {
            $aspect = (string) ($ch['aspect'] ?? ''); if ($aspect === '') { continue; }
            $k    = $id . '|' . cnorm($aspect);
            $cat  = $curCat[$k] ?? '';
            $ok   = !empty($ch['ok']);
            $val  = trim((string) ($ch['value'] ?? ''));
            $cert = (int) round((float) ($ch['certainty'] ?? 0));
            $reason = trim((string) ($ch['reason'] ?? ''));
            $verdict = $ok ? 'ok' : 'suspect';
            $status  = 'ok';
            if (!$ok) {
                if ($val === '') { $status = 'no_suggestion'; }
                elseif (mb_strlen($val) > 65) { $val = mb_substr($val, 0, 60) . '...'; $status = 'too_long'; }
                else {
                    $meta = cSchema($cat, $schemaCache)[cnorm($aspect)] ?? null;
                    if ($meta && ($meta['mode'] ?? '') === 'SELECTION_ONLY' && $meta['values']) {
                        $canon = $meta['values'][cnorm($val)] ?? null;
                        if ($canon === null) { $status = 'value_not_in_list'; } else { $val = $canon; }
                    }
                }
            }
            if ($ok) { $val = ''; }
            fputcsv($out, [$id, $pSku[$id] ?? '', $cat, $aspect, $curVal[$k] ?? '', $verdict, $val, $cert, $status, $reason]);
            $stat[$ok ? 'ok' : ($status === 'ok' ? 'suspect' : $status)]++;
        }
    }
    fclose($out);
    echo "wrote {$dir}/current_value_checks.csv\n";
    foreach ($stat as $k => $v) { printf("  %-18s %d\n", $k, $v); }
    echo "Now: php ebay/scripts/build_review_sheet.php --account={$account}\n";
    exit(0);
}
