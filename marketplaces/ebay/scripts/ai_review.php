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
 *                   to this product (write the literal `blank_value` marker so a reviewer
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
 * --mode=deep also supports --run: a direct Claude API call, no external agent
 * round-trip needed. Reuses the exact same task-building (buildDeepFillTasks()) and
 * answer-validation (mergeDeepFillAnswers()) logic --tasks/--merge use — --run isn't
 * a separate implementation, it's the same two steps back-to-back with the AI
 * standing in for the human/agent in between. Also persists ai_fill_answers.jsonl as
 * it goes, so a --merge re-run later sees the exact same answers.
 *
 * All modes are read-only against eBay (dry-run) — none of this ever writes a listing.
 * --run calls the Anthropic API directly and costs real money per run (see
 * MODEL_RATES) — same safety model as author_descriptions_ai.php: always --dry-run
 * first to see the chunk plan and an example prompt at no cost.
 *
 * Usage:
 *   php ebay/scripts/ai_review.php --mode=blanks  --account=dows --tasks
 *   php ebay/scripts/ai_review.php --mode=blanks  --account=dows --merge
 *   php ebay/scripts/ai_review.php --mode=current --account=dows --tasks
 *   php ebay/scripts/ai_review.php --mode=current --account=dows --merge
 *   php ebay/scripts/ai_review.php --mode=deep     --account=dows --tasks
 *   php ebay/scripts/ai_review.php --mode=deep     --account=dows --merge
 *   php ebay/scripts/ai_review.php --mode=deep     --account=dows --run --dry-run
 *   php ebay/scripts/ai_review.php --mode=deep     --account=dows --run
 *   Add --limit=N to any --tasks or --run call to cap the batch size; --all to
 *   include already-answered items (normally skipped so re-runs are resumable).
 *   --run-only flags: --chunk-size=N (default 5), --model=MODEL (default
 *   claude-sonnet-4-6), --dry-run (show the chunk plan + one example prompt, no
 *   API calls, no writes).
 *
 * Environment (--run only):
 *   ANTHROPIC_API_KEY    Required (repo-root .env) — same as author_descriptions_ai.php.
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/prop65.php';

const MODEL_DEFAULT = 'claude-sonnet-4-6';
const MODEL_RATES = [
    'claude-haiku-4-5'  => ['in' => 1.0, 'out' => 5.0],
    'claude-sonnet-4-6' => ['in' => 3.0, 'out' => 15.0],
    'claude-opus-4-8'   => ['in' => 5.0, 'out' => 25.0],
];

$opts = getopt('', [
    'mode:', 'account:', 'tasks', 'merge', 'run', 'all', 'max-allowed:', 'limit:',
    'chunk-size:', 'model:', 'dry-run', 'help',
]);
$mode = strtolower((string) ($opts['mode'] ?? ''));
$validMode = in_array($mode, ['blanks', 'current', 'deep'], true);
if (isset($opts['help']) || !$validMode || (!isset($opts['tasks']) && !isset($opts['merge']) && !isset($opts['run']))) {
    fwrite(STDOUT, "Usage: php ai_review.php --mode=blanks|current|deep --account=dows (--tasks | --merge)\n");
    fwrite(STDOUT, "       --mode=deep also supports --run [--dry-run] [--chunk-size=N] [--model=MODEL] (needs ANTHROPIC_API_KEY)\n");
    exit($validMode ? 0 : 1);
}

$account    = strtolower((string) ($opts['account'] ?? 'dows'));
$dir        = ebay_dir($account, 'output');
$itemsD     = $dir . '/items';
$maxAllowed = (int) ($opts['max-allowed'] ?? 80);
$limit      = (int) ($opts['limit'] ?? 0);
$includeAll = isset($opts['all']);
$chunkSize  = max(1, (int) ($opts['chunk-size'] ?? 5));
$model      = (string) ($opts['model'] ?? MODEL_DEFAULT);
$dryRun     = isset($opts['dry-run']);

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

/**
 * MODE=deep task-building — shared by --tasks (writes the JSONL for an external
 * agent) and --run (feeds the same payload straight to the API). One item_id ->
 * one task entry, exactly the shape ai_fill_tasks.jsonl already used.
 *
 * @param  array<string, bool>  $ok  item_id|normAspect -> true for gaps fill_aspects.php already filled
 * @param  array<string, mixed>  $schemaCache  passed by reference, see schemaFor()
 * @return array<string, array{item_id: string, sku: string, name: string, title: string, category_id: string, gaps: list<array{aspect: string, mode: string, allowed: ?list<string>}>}>
 */
function buildDeepFillTasks(
    string $dir,
    array $ok,
    array $sku,
    array $name,
    array $title,
    int $maxAllowed,
    array &$schemaCache,
    ?int $limit,
): array {
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

    return $limit ? array_slice($byProd, 0, $limit, true) : $byProd;
}

/**
 * MODE=deep answer-merging — shared by --merge (reads ai_fill_answers.jsonl off
 * disk) and --run (passes the answer lines it just got back from the API, without
 * a file round-trip first — though --run also persists them to that same file for
 * an audit trail / a later --merge rerun). Writes proposed_fills_deep.csv.
 *
 * @param  list<string>  $answerLines  raw JSON-per-line strings, {"item_id":"..","answers":[{"aspect":"..","value":"..","certainty":NN}]}
 * @return array<string, int>  status => count, for the caller to print
 */
function mergeDeepFillAnswers(
    string $dir,
    array $answerLines,
    array $sku,
    array $name,
    array $title,
    array &$schemaCache,
): array {
    $gapCat = [];
    $wf = fopen($dir . '/aspect_gaps_worklist.csv', 'r'); $wh = fgetcsv($wf); $wcix = array_flip($wh);
    while (($r = fgetcsv($wf)) !== false) { $gapCat[$r[$wcix['item_id']] . '|' . normAspect($r[$wcix['aspect']])] = $r[$wcix['category_id']]; }
    fclose($wf);

    $out = fopen($dir . '/proposed_fills_deep.csv', 'w');
    fputcsv($out, ['item_id', 'sku', 'name', 'category_id', 'aspect', 'mode', 'proposed_value', 'source', 'ai_generated', 'certainty', 'status', 'title']);
    $stat = ['ok' => 0, 'value_not_in_list' => 0, 'too_long' => 0, 'empty' => 0]; $buckets = ['90-100' => 0, '70-89' => 0, '50-69' => 0, '<50' => 0];
    foreach ($answerLines as $line) {
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

    return $stat;
}

/**
 * The AI prompt for MODE=deep --run. Instructs the model to fill only from the
 * title/name (no invented facts), respect a closed "allowed" list when given, and
 * respond in exactly the shape mergeDeepFillAnswers() already parses — the same
 * shape the --tasks output message tells a human/agent to answer in.
 *
 * @param  array<string, array{item_id: string, sku: string, name: string, title: string, category_id: string, gaps: list<array{aspect: string, mode: string, allowed: ?list<string>}>}>  $chunk
 */
function buildDeepFillPrompt(array $chunk): string
{
    $instructions = <<<'TXT'
You are filling in missing eBay item-specific (aspect) values for a batch of
product listings, grounded ONLY in each product's own title/name/category —
never invent a fact the title/name doesn't already imply.

For each product below you're given its gaps: aspects with no current value.
For each gap:
  - If "allowed" is a non-null list (a closed set eBay requires), you MUST pick
    a value from that exact list verbatim, or leave value "" if none genuinely fit.
  - If "allowed" is null, it's free text — a short, factual value (eBay's real
    limit is 65 characters).
  - certainty is 0-100: how confident you are this is actually correct for THIS
    product, not just a plausible-sounding guess. A low-certainty answer someone
    can see and skip is more useful than a confident-sounding wrong one.
  - Leave value "" if you genuinely cannot determine it from the title/name
    alone — do not guess just to fill the field.

Respond with EXACTLY one line of raw JSON per product (no markdown fences, no
commentary, no blank lines), in this shape:
{"item_id":"...","answers":[{"aspect":"...","value":"...","certainty":85}]}

Include one entry in "answers" for every gap listed for that product, even if
value ends up being "".
TXT;

    $listings = array_values(array_map(
        static fn (array $p): array => [
            'item_id' => $p['item_id'], 'sku' => $p['sku'], 'name' => $p['name'],
            'title' => $p['title'], 'category_id' => $p['category_id'], 'gaps' => $p['gaps'],
        ],
        $chunk,
    ));

    return $instructions . "\n\n## Input batch\n\n" . json_encode($listings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
                if (isProp65((string) $aname)) { continue; }  // removed from item specifics, see review-rules.md §3
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
        $byProd = buildDeepFillTasks($dir, $ok, $sku, $name, $title, $maxAllowed, $schemaCache, null);
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

        $answerLines = file($af, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        mergeDeepFillAnswers($dir, $answerLines, $sku, $name, $title, $schemaCache);
        exit(0);
    }

    if (isset($opts['run'])) {
        $byProd = buildDeepFillTasks($dir, $ok, $sku, $name, $title, $maxAllowed, $schemaCache, $limit ?: null);
        if (!$byProd) {
            echo "Nothing to fill for {$account} — every gap is either already filled by fill_aspects.php or there are no gaps.\n";
            exit(0);
        }

        $chunks = array_chunk($byProd, $chunkSize, true);

        // Dry-run needs no API key at all — same as author_descriptions_ai.php,
        // so the chunk plan + an example prompt can be inspected at zero cost
        // before a key is even provisioned.
        if ($dryRun) {
            echo '[DRY RUN] ' . count($byProd) . " product(s) with gaps, " . count($chunks) . " chunk(s) of up to {$chunkSize}, model={$model}\n\n";
            echo '[DRY RUN] Example prompt for chunk 1 (' . count($chunks[0]) . " product(s)):\n\n";
            echo buildDeepFillPrompt($chunks[0]) . "\n\n";
            echo "[DRY RUN] No API calls made, no files written.\n";
            exit(0);
        }

        $key = getenv('ANTHROPIC_API_KEY') ?: ($_ENV['ANTHROPIC_API_KEY'] ?? '');
        if ($key === '') { fwrite(STDERR, "ANTHROPIC_API_KEY not set — use the --tasks/--merge manual loop instead, or add --dry-run to preview at no cost.\n"); exit(1); }

        $anthropic = new \Anthropic\Client(apiKey: $key);
        echo "=== ai_review --mode=deep --run: {$account} — " . count($byProd) . ' product(s), '
            . count($chunks) . " chunk(s) of up to {$chunkSize}, model={$model} ===\n\n";

        $answerLines = [];
        $apiCalls = 0; $inTokens = 0; $outTokens = 0; $parseErrors = 0;
        foreach ($chunks as $i => $chunk) {
            $n = $i + 1;
            echo "--- chunk {$n}/" . count($chunks) . ' (' . count($chunk) . " product(s)) ---\n";

            try {
                $message = $anthropic->messages->create(
                    model: $model,
                    maxTokens: 4096,
                    messages: [['role' => 'user', 'content' => buildDeepFillPrompt($chunk)]],
                );
                $apiCalls++;
                $inTokens  += $message->usage->inputTokens;
                $outTokens += $message->usage->outputTokens;

                $rawText = '';
                foreach ($message->content as $block) {
                    if ($block->type === 'text') { $rawText = $block->text; break; }
                }
                $rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
                $rawText = trim((string) preg_replace('/\s*```$/m', '', (string) $rawText));

                foreach (array_filter(array_map('trim', explode("\n", $rawText))) as $line) {
                    $parsed = json_decode($line, true);
                    if (!is_array($parsed) || !isset($parsed['item_id']) || !isset($chunk[(string) $parsed['item_id']])) {
                        $parseErrors++;
                        echo "  [WARN] unparseable line or unknown item_id in response\n";
                        continue;
                    }
                    $answerLines[] = $line;
                    echo '  ok: ' . $parsed['item_id'] . "\n";
                }
            } catch (\Throwable $e) {
                echo "  [ERROR] chunk {$n} failed: " . $e->getMessage() . "\n";
            }

            // Save progress after every chunk — resumable (via --merge) if the run
            // is killed midway, same convention author_descriptions_ai.php uses.
            $af = fopen($dir . '/ai_fill_answers.jsonl', 'w');
            foreach ($answerLines as $l) { fwrite($af, $l . "\n"); }
            fclose($af);
        }

        mergeDeepFillAnswers($dir, $answerLines, $sku, $name, $title, $schemaCache);

        echo "\napi calls: {$apiCalls}";
        if ($parseErrors) { echo "  (unparseable/unmatched response lines: {$parseErrors})"; }
        echo "\n";
        if (isset(MODEL_RATES[$model])) {
            $rate = MODEL_RATES[$model];
            $cost = $inTokens / 1e6 * $rate['in'] + $outTokens / 1e6 * $rate['out'];
            printf("tokens: in=%s out=%s  est. cost: \$%.4f\n", number_format($inTokens), number_format($outTokens), $cost);
        }
        exit(0);
    }
}
