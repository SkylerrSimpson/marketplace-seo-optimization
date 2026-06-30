<?php

declare(strict_types=1);

/**
 * DEEP LLM-FILL (Phase 6, AI part 2). For every gap NOT already filled by
 * fill_aspects.php (the deterministic Usurper + high-precision rule passes), use
 * per-product LLM judgment to propose a value AND a certainty %. Unlike the rule
 * pass, this attempts EVERY gap; honesty lives in the `certainty` column (low % =
 * "couldn't really tell from the listing").
 *
 * Three sub-commands:
 *   --tasks   Build ebay/data/{acct}/output/ai_fill_tasks.jsonl — one product per
 *             line: {item_id, sku, name, title, category_id, gaps:[{aspect, mode,
 *             allowed:[...]|null}]}. `allowed` is the SELECTION_ONLY value list
 *             (null/omitted when FREE_TEXT or when the list is too large to inline).
 *   --merge   Read ai_fill_answers.jsonl (the LLM output: {item_id, answers:[{aspect,
 *             value, certainty}]}) → validate SELECTION_ONLY values against the
 *             category schema (drop/flag non-matches) and the 65-char cap → write
 *             proposed_fills_deep.csv with a `certainty` column.
 *   --run     If ANTHROPIC_API_KEY is set, call Claude per product to produce the
 *             answers automatically (otherwise --tasks/--merge is the manual loop).
 *
 * Read-only against eBay (dry-run); never writes a listing.
 *
 * Usage:
 *   php ebay/scripts/ai_fill_deep.php --account=dows --tasks
 *   php ebay/scripts/ai_fill_deep.php --account=dows --merge
 *   php ebay/scripts/ai_fill_deep.php --account=dows --run        (needs ANTHROPIC_API_KEY)
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts = getopt('', ['account:', 'tasks', 'merge', 'run', 'max-allowed:', 'limit:', 'help']);
if (isset($opts['help']) || (!isset($opts['tasks']) && !isset($opts['merge']) && !isset($opts['run']))) {
    fwrite(STDOUT, "Usage: php ai_fill_deep.php --account=dows (--tasks | --merge | --run)\n");
    exit(0);
}
$account    = strtolower((string) ($opts['account'] ?? 'dows'));
$outDir     = ebay_dir($account, 'output');
$maxAllowed = (int) ($opts['max-allowed'] ?? 80);   // inline allowed-list cap (huge lists -> free-text + post-validate)

function norm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower($s))); }

// schema: catId -> [normAspect => ['values'=>[normValue=>canonical], 'mode'=>..]]
$schemaCache = [];
function schemaFor(string $catId, array &$cache): array
{
    if (!array_key_exists($catId, $cache)) {
        $path = EBAY_ASPECTS . "/{$catId}.json"; $byAspect = [];
        if (is_file($path)) {
            foreach ((json_decode((string) file_get_contents($path), true) ?: [])['aspects'] ?? [] as $a) {
                $vals = [];
                foreach ($a['values'] ?? [] as $v) { $vals[norm((string) $v)] = (string) $v; }
                $byAspect[norm((string) $a['name'])] = ['values' => $vals, 'mode' => $a['mode'] ?? ''];
            }
        }
        $cache[$catId] = $byAspect;
    }
    return $cache[$catId];
}

// ---- the already-filled set + product name/title, from proposed_fills.csv -----
$ok = []; $name = []; $title = []; $sku = [];
$pf = $outDir . '/proposed_fills.csv';
if (!is_file($pf)) { fwrite(STDERR, "Run fill_aspects.php first ($pf missing)\n"); exit(1); }
$fh = fopen($pf, 'r'); $h = fgetcsv($fh); $ix = array_flip($h);
while (($r = fgetcsv($fh)) !== false) {
    $id = $r[$ix['item_id']];
    if ($r[$ix['status']] === 'ok') { $ok[$id . '|' . norm($r[$ix['aspect']])] = true; }
    $name[$id]  = $r[$ix['name']]  ?: ($name[$id]  ?? '');
    $title[$id] = $r[$ix['title']] ?: ($title[$id] ?? '');
    $sku[$id]   = $r[$ix['sku']]   ?: ($sku[$id]   ?? '');
}
fclose($fh);

// =============================== --tasks =====================================
if (isset($opts['tasks'])) {
    $byProd = [];
    $wf = fopen($outDir . '/aspect_gaps_worklist.csv', 'r'); $wh = fgetcsv($wf); $wcix = array_flip($wh);
    while (($r = fgetcsv($wf)) !== false) {
        $id = $r[$wcix['item_id']]; $aspect = $r[$wcix['aspect']]; $catId = $r[$wcix['category_id']];
        if (isset($ok[$id . '|' . norm($aspect)])) { continue; }   // already filled
        $sc = schemaFor($catId, $schemaCache);
        $meta = $sc[norm($aspect)] ?? ['values' => [], 'mode' => $r[$wcix['mode']]];
        $allowed = null;
        if (($meta['mode'] ?? '') === 'SELECTION_ONLY' && $meta['values']) {
            $allowed = (count($meta['values']) <= (isset($GLOBALS['opts']['max-allowed']) ? (int)$GLOBALS['opts']['max-allowed'] : 80))
                ? array_values($meta['values']) : null;   // omit when too large; post-validate on merge
        }
        $byProd[$id] ??= ['item_id' => $id, 'sku' => $sku[$id] ?? '', 'name' => $name[$id] ?? '', 'title' => $title[$id] ?? '', 'category_id' => $catId, 'gaps' => []];
        $byProd[$id]['gaps'][] = ['aspect' => $aspect, 'mode' => $meta['mode'] ?? $r[$wcix['mode']], 'allowed' => $allowed];
    }
    fclose($wf);
    $limit = (int) ($opts['limit'] ?? 0);
    $tf = fopen($outDir . '/ai_fill_tasks.jsonl', 'w'); $n = 0; $g = 0;
    foreach ($byProd as $p) { fwrite($tf, json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"); $n++; $g += count($p['gaps']); if ($limit && $n >= $limit) break; }
    fclose($tf);
    echo "wrote {$outDir}/ai_fill_tasks.jsonl — {$n} products, {$g} gaps\n";
    echo "Fill ai_fill_answers.jsonl with lines: {\"item_id\":\"..\",\"answers\":[{\"aspect\":\"..\",\"value\":\"..\",\"certainty\":NN}]}\n";
    exit(0);
}

// =============================== --merge =====================================
if (isset($opts['merge'])) {
    $af = $outDir . '/ai_fill_answers.jsonl';
    if (!is_file($af)) { fwrite(STDERR, "No ai_fill_answers.jsonl in {$outDir}\n"); exit(1); }

    // need each gap's category to validate -> rebuild gap->catId from worklist
    $gapCat = [];
    $wf = fopen($outDir . '/aspect_gaps_worklist.csv', 'r'); $wh = fgetcsv($wf); $wcix = array_flip($wh);
    while (($r = fgetcsv($wf)) !== false) { $gapCat[$r[$wcix['item_id']] . '|' . norm($r[$wcix['aspect']])] = $r[$wcix['category_id']]; }
    fclose($wf);

    $out = fopen($outDir . '/proposed_fills_deep.csv', 'w');
    fputcsv($out, ['item_id', 'sku', 'name', 'category_id', 'aspect', 'mode', 'proposed_value', 'source', 'ai_generated', 'certainty', 'status', 'title']);
    $stat = ['ok' => 0, 'value_not_in_list' => 0, 'too_long' => 0, 'empty' => 0]; $buckets = ['90-100' => 0, '70-89' => 0, '50-69' => 0, '<50' => 0];
    foreach (file($af, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $rec = json_decode($line, true); if (!$rec) { continue; }
        $id = (string) $rec['item_id'];
        foreach ($rec['answers'] ?? [] as $ans) {
            $aspect = (string) ($ans['aspect'] ?? ''); if ($aspect === '') { continue; }
            $catId  = $gapCat[$id . '|' . norm($aspect)] ?? '';
            $val    = trim((string) ($ans['value'] ?? ''));
            $cert   = (int) round((float) ($ans['certainty'] ?? 0));
            $sc     = schemaFor($catId, $schemaCache); $meta = $sc[norm($aspect)] ?? null;
            $status = 'ok';
            if ($val === '') { $status = 'empty'; }
            elseif (mb_strlen($val) > 65) { $val = mb_substr($val, 0, 60) . '...'; $status = 'too_long'; }
            elseif ($meta && ($meta['mode'] ?? '') === 'SELECTION_ONLY' && $meta['values']) {
                $canon = $meta['values'][norm($val)] ?? null;
                if ($canon === null) { $status = 'value_not_in_list'; } else { $val = $canon; }
            }
            fputcsv($out, [$id, $sku[$id] ?? '', $name[$id] ?? '', $catId, $aspect, $meta['mode'] ?? '', $val, 'llm', 'yes', $cert, $status, $title[$id] ?? '']);
            $stat[$status] = ($stat[$status] ?? 0) + 1;
            if ($status === 'ok') { $buckets[$cert >= 90 ? '90-100' : ($cert >= 70 ? '70-89' : ($cert >= 50 ? '50-69' : '<50'))]++; }
        }
    }
    fclose($out);
    echo "wrote {$outDir}/proposed_fills_deep.csv\n--- by status ---\n";
    foreach ($stat as $k => $v) { printf("  %-18s %d\n", $k, $v); }
    echo "--- OK by certainty ---\n";
    foreach ($buckets as $b => $v) { printf("  %-8s %d\n", $b, $v); }
    exit(0);
}

// =============================== --run (API) =================================
if (isset($opts['run'])) {
    $key = getenv('ANTHROPIC_API_KEY') ?: ($_ENV['ANTHROPIC_API_KEY'] ?? '');
    if ($key === '') { fwrite(STDERR, "ANTHROPIC_API_KEY not set — use the --tasks/--merge manual loop instead.\n"); exit(1); }
    fwrite(STDERR, "[--run] API path is scaffolded; wire model call here when a key is provisioned.\n");
    exit(1);
}
