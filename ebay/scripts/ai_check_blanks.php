<?php

declare(strict_types=1);

/**
 * RULE #5 — blank_value applicability check.  DRY-RUN.
 *
 * For every field that is BLANK in the review sheet (no live value AND no proposed
 * value, after rules #1-4), an LLM decides WHY it is blank:
 *   - NOT APPLICABLE to this product (e.g. "Cord Length" on a cordless light,
 *     "Lumens" on a knife) -> we want the literal value `blank_value` so Ethan
 *     knows it was intentionally left empty.
 *   - merely UNKNOWN (a real spec we just don't have, e.g. exact "Blade Length")
 *     -> leave it truly blank.
 * Only judgement from the product title + category + the aspect name. No lookups.
 *
 * Mirrors ai_check_current.php (tasks -> answers -> merge), resumable.
 *
 *   --tasks  Build blank_check_tasks.jsonl — one product/line:
 *            {item_id, sku, title, category_id, category_path, blanks:[aspect,...]}.
 *            Blanks read straight from review_sheet.csv (current=='' & proposed=='',
 *            non-variation). Skips item_ids already in blank_check_answers.jsonl.
 *   --merge  Read blank_check_answers.jsonl (LLM output:
 *            {item_id, na:[{aspect, reason} | "aspect"]}) -> write
 *            blank_value_checks.csv (item_id, sku, category_id, aspect, reason).
 *            apply_review_rules.php folds it in as proposed_value='blank_value'.
 *
 * Usage:
 *   php ebay/scripts/ai_check_blanks.php --account=dows --tasks
 *   php ebay/scripts/ai_check_blanks.php --account=dows --merge
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts = getopt('', ['account:', 'tasks', 'merge', 'all', 'limit:', 'help']);
if (isset($opts['help']) || (!isset($opts['tasks']) && !isset($opts['merge']))) {
    fwrite(STDOUT, "Usage: php ai_check_blanks.php --account=dows (--tasks | --merge)\n");
    exit(0);
}
$account = strtolower((string) ($opts['account'] ?? 'dows'));
$dir     = ebay_dir($account, 'output');
$itemsD  = $dir . '/items';

function bnorm(string $s): string { return preg_replace('/\s+/', ' ', trim(mb_strtolower(rtrim(trim($s), ':')))); }

function readSheet(string $path): array
{
    $rows = []; if (!is_file($path)) { return $rows; }
    $fh = fopen($path, 'r'); $h = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) { $rows[] = array_combine($h, $r); }
    fclose($fh);
    return $rows;
}

$sheet = readSheet($dir . '/review_sheet.csv');
if (!$sheet) { fwrite(STDERR, "no review_sheet.csv for {$account}\n"); exit(1); }

// ----- scan the sheet --------------------------------------------------------
// $blanks  = fields to JUDGE: truly blank (no live, no proposed) and not already
//            owned by rules #1-4. 'blank_value' counts as blank (it's our marker).
// $allAsp  = every non-variation (item_id|aspect) row, used by --merge to drop
//            hallucinated aspects WITHOUT being fooled by a prior blank_value pass.
$blanks = [];   // item_id => [aspect, ...]
$allAsp = [];   // item_id|normAspect => aspect
$meta   = [];   // item_id => [sku,title,category_id]
foreach ($sheet as $r) {
    if (($r['source'] ?? '') === 'variation') { continue; }
    $id = $r['item_id'];
    $meta[$id] ??= ['sku' => $r['sku'], 'title' => $r['title'], 'category_id' => $r['category_id']];
    $allAsp[$id . '|' . bnorm($r['aspect'])] = $r['aspect'];

    $prop = trim($r['proposed_value']);
    if (trim($r['current_value']) !== '' || ($prop !== '' && $prop !== 'blank_value')) { continue; }
    if (strpos($r['reviewer_notes'] ?? '', 'rule #') !== false && $prop !== 'blank_value') { continue; }
    $blanks[$id][] = $r['aspect'];
}

// =============================== --tasks =====================================
if (isset($opts['tasks'])) {
    $done = [];
    if (!isset($opts['all'])) {
        foreach (@file($dir . '/blank_check_answers.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
            $j = json_decode($ln, true); if ($j && isset($j['item_id'])) { $done[(string) $j['item_id']] = true; }
        }
    }
    $limit = (int) ($opts['limit'] ?? 0);
    $tf = fopen($dir . '/blank_check_tasks.jsonl', 'w'); $n = 0; $c = 0;
    foreach ($blanks as $id => $aspects) {
        if (isset($done[$id])) { continue; }
        $item = is_file("$itemsD/$id.json") ? json_decode((string) file_get_contents("$itemsD/$id.json"), true) : [];
        $aspects = array_values(array_unique($aspects));
        fwrite($tf, json_encode([
            'item_id'       => (string) $id,
            'sku'           => $meta[$id]['sku'],
            'title'         => $meta[$id]['title'],
            'category_id'   => $meta[$id]['category_id'],
            'category_path' => $item['category_path'] ?? '',
            'blanks'        => $aspects,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $n++; $c += count($aspects);
    if ($limit && $n >= $limit) { break; }
    }
    fclose($tf);
    echo "wrote {$dir}/blank_check_tasks.jsonl — {$n} products, {$c} blank fields to judge"
       . (isset($opts['all']) ? "\n" : " (" . count($done) . " already answered, skipped)\n");
    echo "Append to blank_check_answers.jsonl: {\"item_id\":\"..\",\"na\":[{\"aspect\":\"..\",\"reason\":\"..\"}]}\n";
    echo "  (list ONLY the aspects that DON'T APPLY to the product; empty na:[] = none are N/A)\n";
    exit(0);
}

// =============================== --merge =====================================
if (isset($opts['merge'])) {
    $af = $dir . '/blank_check_answers.jsonl';
    if (!is_file($af)) { fwrite(STDERR, "No blank_check_answers.jsonl in {$dir}\n"); exit(1); }

    // validate na entries against EVERY real aspect row on the listing (stable across
    // re-runs); rule #5 only applies blank_value where the field is actually blank.
    $validBlank = $allAsp;

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
            $k = $id . '|' . bnorm($aspect);
            if (!isset($validBlank[$k])) { $stat['unknown_aspect']++; continue; }   // not a real blank on this listing
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
