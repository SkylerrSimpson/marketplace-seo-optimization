<?php

declare(strict_types=1);

/**
 * ai_review.php — unified LLM review/fill harness for review_sheet.csv.
 *
 * Replaces three former scripts (ai_check_blanks.php, ai_check_current.php,
 * ai_fill_deep.php) that each implemented the exact same --tasks/--merge(/--run)
 * harness — build a task JSONL, an external agent (or --run) answers it, merge the
 * answers back into a CSV — three times over, with only the domain logic differing.
 * Same behavior, one entry point, one place to fix the shared harness if it needs it.
 *
 * Modes:
 *   --mode=blanks   RULE #5 — for every field that's blank (no live value, no proposed
 *                   value after rules #1-4), an LLM judges whether it's genuinely N/A
 *                   to this product (write the literal `blank_value` marker so Ethan
 *                   knows it was intentional) or just unknown (leave truly blank).
 *                   -> blank_value_checks.csv. apply_review_rules.php folds this in.
 *   --mode=current  Deep LLM audit of values ALREADY LIVE on eBay (source=current).
 *                   Where the LLM isn't confident the live value is right, it proposes
 *                   a correction + certainty %; confirmed-correct values get no
 *                   suggestion. -> current_value_checks.csv. build_review_sheet.php
 *                   folds this in (suggestion -> proposed_value on the current row).
 *   --mode=deep     Deep LLM-fill for every gap fill_aspects.php's deterministic pass
 *                   didn't reach. Attempts EVERY gap; honesty lives in `certainty`.
 *                   -> proposed_fills_deep.csv.
 *
 * Each mode: --tasks (build a task JSONL, resumable — skips item_ids already answered
 * unless --all) -> an agent/human appends JSON-per-line answers -> --merge (validate
 * against the category schema + the 65-char cap, write the mode's output CSV).
 * --mode=deep also supports --run (direct Claude API call if ANTHROPIC_API_KEY is set;
 * otherwise use the manual --tasks/--merge loop).
 *
 * All modes are read-only against eBay (dry-run) — none of this ever writes a listing.
 *
 * Usage:
 *   php ebay/scripts/ai_review.php --mode=blanks  --account=dows --tasks
 *   php ebay/scripts/ai_review.php --mode=blanks  --account=dows --merge
 *   php ebay/scripts/ai_review.php --mode=current --account=dows --tasks
 *   php ebay/scripts/ai_review.php --mode=current --account=dows --merge
 *   php ebay/scripts/ai_review.php --mode=deep     --account=dows --tasks
 *   php ebay/scripts/ai_review.php --mode=deep     --account=dows --merge
 *   php ebay/scripts/ai_review.php --mode=deep     --account=dows --run
 *   Add --limit=N to any --tasks call to cap the batch size; --all to include
 *   already-answered items (normally skipped so re-runs are resumable).
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts = getopt('', ['mode:', 'account:', 'tasks', 'merge', 'run', 'all', 'max-allowed:', 'limit:', 'help']);
$mode = strtolower((string) ($opts['mode'] ?? ''));
$validMode = in_array($mode, ['blanks', 'current', 'deep'], true);
if (isset($opts['help']) || !$validMode || (!isset($opts['tasks']) && !isset($opts['merge']) && !isset($opts['run']))) {
    fwrite(STDOUT, "Usage: php ai_review.php --mode=blanks|current|deep --account=dows (--tasks | --merge)\n");
    fwrite(STDOUT, "       --mode=deep also supports --run (needs ANTHROPIC_API_KEY)\n");
    exit($validMode ? 0 : 1);
}

$account    = strtolower((string) ($opts['account'] ?? 'dows'));
$dir        = ebay_dir($account, 'output');
$itemsD     = $dir . '/items';
$maxAllowed = (int) ($opts['max-allowed'] ?? 80);
$limit      = (int) ($opts['limit'] ?? 0);
$includeAll = isset($opts['all']);

// ---- shared helpers (were duplicated near-verbatim across all three originals) ---

function normAspect(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }
function stripColon(string $s): string { return rtrim(trim($s), ':'); }

function readCsvAssoc(string $path): array
{
    $rows = []; if (!is_file($path)) { return $rows; }
    $fh = fopen($path, 'r'); $h = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) { $rows[] = array_combine($h, $r); }
    fclose($fh);
    return $rows;
}

/** catId -> normAspect -> ['values'=>[normValue=>canonical], 'mode'=>SELECTION_ONLY|FREE_TEXT] */
$schemaCache = [];
function schemaFor(string $catId, array &$cache): array
{
    if (!array_key_exists($catId, $cache)) {
        $path = EBAY_ASPECTS . "/{$catId}.json"; $byAspect = [];
        if (is_file($path)) {
            foreach ((json_decode((string) file_get_contents($path), true) ?: [])['aspects'] ?? [] as $a) {
                $vals = [];
                foreach ($a['values'] ?? [] as $v) { $vals[normAspect((string) $v)] = (string) $v; }
                $byAspect[normAspect((string) $a['name'])] = ['values' => $vals, 'mode' => $a['mode'] ?? ''];
            }
        }
        $cache[$catId] = $byAspect;
    }
    return $cache[$catId];
}

/** item_ids already present in a task-answers JSONL (for resumable --tasks). */
function alreadyAnswered(string $path): array
{
    $done = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
        $j = json_decode($ln, true); if ($j && isset($j['item_id'])) { $done[(string) $j['item_id']] = true; }
    }
    return $done;
}

// ===========================================================================
// MODE: blanks — Rule #5, blank_value applicability judgment
// ===========================================================================
if ($mode === 'blanks') {
    $sheet = readCsvAssoc($dir . '/review_sheet.csv');
    if (!$sheet) { fwrite(STDERR, "no review_sheet.csv for {$account}\n"); exit(1); }

    // $blanks = fields to JUDGE: truly blank (no live, no proposed) and not already
    //           owned by rules #1-4. 'blank_value' counts as blank (it's our marker).
    // $allAsp = every non-variation (item_id|aspect) row, used by --merge to drop
    //           hallucinated aspects without being fooled by a prior blank_value pass.
    $blanks = []; $allAsp = []; $meta = [];
    foreach ($sheet as $r) {
        if (($r['source'] ?? '') === 'variation') { continue; }
        $id = $r['item_id'];
        $meta[$id] ??= ['sku' => $r['sku'], 'title' => $r['title'], 'category_id' => $r['category_id']];
        $allAsp[$id . '|' . normAspect($r['aspect'])] = $r['aspect'];

        $prop = trim($r['proposed_value']);
        if (trim($r['current_value']) !== '' || ($prop !== '' && $prop !== 'blank_value')) { continue; }
        if (strpos($r['reviewer_notes'] ?? '', 'rule #') !== false && $prop !== 'blank_value') { continue; }
        $blanks[$id][] = $r['aspect'];
    }

    if (isset($opts['tasks'])) {
        $done = $includeAll ? [] : alreadyAnswered($dir . '/blank_check_answers.jsonl');
        $tf = fopen($dir . '/blank_check_tasks.jsonl', 'w'); $n = 0; $c = 0;
        foreach ($blanks as $id => $aspects) {
            if (isset($done[$id])) { continue; }
            $item = is_file("$itemsD/$id.json") ? json_decode((string) file_get_contents("$itemsD/$id.json"), true) : [];
            $aspects = array_values(array_unique($aspects));
            fwrite($tf, json_encode([
                'item_id' => (string) $id, 'sku' => $meta[$id]['sku'], 'title' => $meta[$id]['title'],
                'category_id' => $meta[$id]['category_id'], 'category_path' => $item['category_path'] ?? '',
                'blanks' => $aspects,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $n++; $c += count($aspects);
            if ($limit && $n >= $limit) { break; }
        }
        fclose($tf);
        echo "wrote {$dir}/blank_check_tasks.jsonl — {$n} products, {$c} blank fields to judge"
           . ($includeAll ? "\n" : " (" . count($done) . " already answered, skipped)\n");
        echo "Append to blank_check_answers.jsonl: {\"item_id\":\"..\",\"na\":[{\"aspect\":\"..\",\"reason\":\"..\"}]}\n";
        echo "  (list ONLY the aspects that DON'T APPLY to the product; empty na:[] = none are N/A)\n";
        exit(0);
    }

    if (isset($opts['merge'])) {
        $af = $dir . '/blank_check_answers.jsonl';
        if (!is_file($af)) { fwrite(STDERR, "No blank_check_answers.jsonl in {$dir}\n"); exit(1); }

        $validBlank = $allAsp; // rule #5 only applies blank_value where the field is actually blank
        $out = fopen($dir . '/blank_value_checks.csv', 'w');
        fputcsv($out, ['item_id', 'sku', 'category_id', 'aspect', 'reason']);
        $stat = ['na' => 0, 'unknown_aspect' => 0, 'dup' => 0]; $seen = [];
        foreach (file($af, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $rec = json_decode($line, true); if (!$rec || !isset($rec['item_id'])) { continue; }
            $id = (string) $rec['item_id'];
            foreach ($rec['na'] ?? [] as $entry) {
                $aspect = is_array($entry) ? (string) ($entry['aspect'] ?? '') : (string) $entry;
                $reason = is_array($entry) ? trim((string) ($entry['reason'] ?? '')) : '';
                if ($aspect === '') { continue; }
                $k = $id . '|' . normAspect($aspect);
                if (!isset($validBlank[$k])) { $stat['unknown_aspect']++; continue; }
                if (isset($seen[$k]))        { $stat['dup']++; continue; }
                $seen[$k] = true;
                fputcsv($out, [$id, $meta[$id]['sku'] ?? '', $meta[$id]['category_id'] ?? '', $validBlank[$k], $reason]);
                $stat['na']++;
            }
        }
        fclose($out);
        echo "wrote {$dir}/blank_value_checks.csv\n";
        printf("  N/A marked: %d | dropped(not a real blank/hallucinated): %d | dup: %d\n",
            $stat['na'], $stat['unknown_aspect'], $stat['dup']);
        echo "Now: php ebay/scripts/apply_review_rules.php --account={$account}\n";
        exit(0);
    }
}

// ===========================================================================
// MODE: current — deep LLM audit of values already live on eBay
// ===========================================================================
if ($mode === 'current') {
    // parent sku + variation dimensions (excluded from parent current set)
    $pSku = []; $variedNrm = [];
    foreach (json_decode((string) file_get_contents($dir . '/listings.json'), true) as $l) {
        $id = (string) $l['item_id']; $pSku[$id] = (string) ($l['sku'] ?? '');
        foreach ($l['variations'] ?? [] as $v) {
            foreach (explode(';', (string) ($v['specifics'] ?? '')) as $pair) {
                if (strpos($pair, '=') === false) { continue; }
                [$k] = explode('=', $pair, 2);
                $variedNrm[$id][normAspect(trim($k))] = true;
            }
        }
    }

    $pName = [];
    foreach (readCsvAssoc($dir . '/proposed_fills.csv') as $r) {
        if (!empty($r['name']) && !isset($pName[$r['item_id']])) { $pName[$r['item_id']] = $r['name']; }
    }

    if (isset($opts['tasks'])) {
        $done = $includeAll ? [] : alreadyAnswered($dir . '/current_check_answers.jsonl');
        $tf = fopen($dir . '/current_check_tasks.jsonl', 'w'); $n = 0; $c = 0;
        foreach ($pSku as $id => $sku) {
            if (isset($done[$id])) { continue; }
            $itemF = "$itemsD/$id.json";
            $item  = is_file($itemF) ? json_decode((string) file_get_contents($itemF), true) : [];
            $aspects = $item['aspects'] ?? [];
            if (!$aspects) { continue; }
            $cat = (string) ($item['category_id'] ?? '');
            $isGroup = !empty($item['is_group']) || isset($variedNrm[$id]);
            $sc = schemaFor($cat, $schemaCache);
            $cur = [];
            foreach ($aspects as $aname => $aval) {
                $an = normAspect(stripColon((string) $aname));
                if ($isGroup && isset($variedNrm[$id][$an])) { continue; }
                $meta = $sc[$an] ?? null;
                $allowed = null;
                if ($meta && ($meta['mode'] ?? '') === 'SELECTION_ONLY' && $meta['values'] && count($meta['values']) <= $maxAllowed) {
                    $allowed = array_values($meta['values']);
                }
                $cur[] = ['aspect' => stripColon((string) $aname), 'value' => (string) $aval, 'mode' => $meta['mode'] ?? '', 'allowed' => $allowed];
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
        echo "wrote {$dir}/current_check_tasks.jsonl — {$n} products, {$c} live values to check"
           . ($includeAll ? "\n" : " (" . count($done) . " already answered, skipped)\n");
        echo "Append to current_check_answers.jsonl: {\"item_id\":\"..\",\"checks\":[{\"aspect\":\"..\",\"ok\":true|false,\"value\":\"<suggestion if !ok>\",\"certainty\":NN,\"reason\":\"..\"}]}\n";
        exit(0);
    }

    if (isset($opts['merge'])) {
        $af = $dir . '/current_check_answers.jsonl';
        if (!is_file($af)) { fwrite(STDERR, "No current_check_answers.jsonl in {$dir}\n"); exit(1); }

        $curVal = []; $curCat = [];
        foreach ($pSku as $id => $sku) {
            $item = is_file("$itemsD/$id.json") ? json_decode((string) file_get_contents("$itemsD/$id.json"), true) : [];
            $cat = (string) ($item['category_id'] ?? '');
            foreach ($item['aspects'] ?? [] as $aname => $aval) {
                $k = $id . '|' . normAspect(stripColon((string) $aname));
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
                $k    = $id . '|' . normAspect($aspect);
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
                        $meta = schemaFor($cat, $schemaCache)[normAspect($aspect)] ?? null;
                        if ($meta && ($meta['mode'] ?? '') === 'SELECTION_ONLY' && $meta['values']) {
                            $canon = $meta['values'][normAspect($val)] ?? null;
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
}

// ===========================================================================
// MODE: deep — deep LLM-fill for every remaining gap
// ===========================================================================
if ($mode === 'deep') {
    $ok = []; $name = []; $title = []; $sku = [];
    $pf = $dir . '/proposed_fills.csv';
    if (!is_file($pf)) { fwrite(STDERR, "Run fill_aspects.php first ($pf missing)\n"); exit(1); }
    $fh = fopen($pf, 'r'); $h = fgetcsv($fh); $ix = array_flip($h);
    while (($r = fgetcsv($fh)) !== false) {
        $id = $r[$ix['item_id']];
        if ($r[$ix['status']] === 'ok') { $ok[$id . '|' . normAspect($r[$ix['aspect']])] = true; }
        $name[$id]  = $r[$ix['name']]  ?: ($name[$id]  ?? '');
        $title[$id] = $r[$ix['title']] ?: ($title[$id] ?? '');
        $sku[$id]   = $r[$ix['sku']]   ?: ($sku[$id]   ?? '');
    }
    fclose($fh);

    if (isset($opts['tasks'])) {
        $byProd = [];
        $wf = fopen($dir . '/aspect_gaps_worklist.csv', 'r'); $wh = fgetcsv($wf); $wcix = array_flip($wh);
        while (($r = fgetcsv($wf)) !== false) {
            $id = $r[$wcix['item_id']]; $aspect = $r[$wcix['aspect']]; $catId = $r[$wcix['category_id']];
            if (isset($ok[$id . '|' . normAspect($aspect)])) { continue; }
            $sc = schemaFor($catId, $schemaCache);
            $meta = $sc[normAspect($aspect)] ?? ['values' => [], 'mode' => $r[$wcix['mode']]];
            $allowed = null;
            if (($meta['mode'] ?? '') === 'SELECTION_ONLY' && $meta['values']) {
                $allowed = (count($meta['values']) <= $maxAllowed) ? array_values($meta['values']) : null;
            }
            $byProd[$id] ??= ['item_id' => $id, 'sku' => $sku[$id] ?? '', 'name' => $name[$id] ?? '', 'title' => $title[$id] ?? '', 'category_id' => $catId, 'gaps' => []];
            $byProd[$id]['gaps'][] = ['aspect' => $aspect, 'mode' => $meta['mode'] ?? $r[$wcix['mode']], 'allowed' => $allowed];
        }
        fclose($wf);
        $tf = fopen($dir . '/ai_fill_tasks.jsonl', 'w'); $n = 0; $g = 0;
        foreach ($byProd as $p) { fwrite($tf, json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"); $n++; $g += count($p['gaps']); if ($limit && $n >= $limit) break; }
        fclose($tf);
        echo "wrote {$dir}/ai_fill_tasks.jsonl — {$n} products, {$g} gaps\n";
        echo "Fill ai_fill_answers.jsonl with lines: {\"item_id\":\"..\",\"answers\":[{\"aspect\":\"..\",\"value\":\"..\",\"certainty\":NN}]}\n";
        exit(0);
    }

    if (isset($opts['merge'])) {
        $af = $dir . '/ai_fill_answers.jsonl';
        if (!is_file($af)) { fwrite(STDERR, "No ai_fill_answers.jsonl in {$dir}\n"); exit(1); }

        $gapCat = [];
        $wf = fopen($dir . '/aspect_gaps_worklist.csv', 'r'); $wh = fgetcsv($wf); $wcix = array_flip($wh);
        while (($r = fgetcsv($wf)) !== false) { $gapCat[$r[$wcix['item_id']] . '|' . normAspect($r[$wcix['aspect']])] = $r[$wcix['category_id']]; }
        fclose($wf);

        $out = fopen($dir . '/proposed_fills_deep.csv', 'w');
        fputcsv($out, ['item_id', 'sku', 'name', 'category_id', 'aspect', 'mode', 'proposed_value', 'source', 'ai_generated', 'certainty', 'status', 'title']);
        $stat = ['ok' => 0, 'value_not_in_list' => 0, 'too_long' => 0, 'empty' => 0]; $buckets = ['90-100' => 0, '70-89' => 0, '50-69' => 0, '<50' => 0];
        foreach (file($af, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $rec = json_decode($line, true); if (!$rec) { continue; }
            $id = (string) $rec['item_id'];
            foreach ($rec['answers'] ?? [] as $ans) {
                $aspect = (string) ($ans['aspect'] ?? ''); if ($aspect === '') { continue; }
                $catId  = $gapCat[$id . '|' . normAspect($aspect)] ?? '';
                $val    = trim((string) ($ans['value'] ?? ''));
                $cert   = (int) round((float) ($ans['certainty'] ?? 0));
                $sc     = schemaFor($catId, $schemaCache); $meta = $sc[normAspect($aspect)] ?? null;
                $status = 'ok';
                if ($val === '') { $status = 'empty'; }
                elseif (mb_strlen($val) > 65) { $val = mb_substr($val, 0, 60) . '...'; $status = 'too_long'; }
                elseif ($meta && ($meta['mode'] ?? '') === 'SELECTION_ONLY' && $meta['values']) {
                    $canon = $meta['values'][normAspect($val)] ?? null;
                    if ($canon === null) { $status = 'value_not_in_list'; } else { $val = $canon; }
                }
                fputcsv($out, [$id, $sku[$id] ?? '', $name[$id] ?? '', $catId, $aspect, $meta['mode'] ?? '', $val, 'llm', 'yes', $cert, $status, $title[$id] ?? '']);
                $stat[$status] = ($stat[$status] ?? 0) + 1;
                if ($status === 'ok') { $buckets[$cert >= 90 ? '90-100' : ($cert >= 70 ? '70-89' : ($cert >= 50 ? '50-69' : '<50'))]++; }
            }
        }
        fclose($out);
        echo "wrote {$dir}/proposed_fills_deep.csv\n--- by status ---\n";
        foreach ($stat as $k => $v) { printf("  %-18s %d\n", $k, $v); }
        echo "--- OK by certainty ---\n";
        foreach ($buckets as $b => $v) { printf("  %-8s %d\n", $b, $v); }
        exit(0);
    }

    if (isset($opts['run'])) {
        $key = getenv('ANTHROPIC_API_KEY') ?: ($_ENV['ANTHROPIC_API_KEY'] ?? '');
        if ($key === '') { fwrite(STDERR, "ANTHROPIC_API_KEY not set — use the --tasks/--merge manual loop instead.\n"); exit(1); }
        fwrite(STDERR, "[--run] API path is scaffolded; wire model call here when a key is provisioned.\n");
        exit(1);
    }
}
