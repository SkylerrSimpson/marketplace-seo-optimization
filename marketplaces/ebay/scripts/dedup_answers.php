<?php

declare(strict_types=1);

/**
 * Dedup a raw answers JSONL (last occurrence of each item_id wins — it carries the
 * fullest answer set per the upstream agent's note) into a clean answers file.
 * Validates JSON, reports malformed lines, dup collapses, and coverage vs the task list.
 *
 * Usage:
 *   php ebay/scripts/dedup_answers.php --account=ige
 *     reads  ebay/data/{acct}/output/ai_fill_answers_RAW.jsonl
 *     writes ebay/data/{acct}/output/ai_fill_answers.jsonl   (deduped, last-wins)
 */

require __DIR__ . '/../../lib/bootstrap.php';

$opts    = getopt('', ['account:', 'raw:', 'out:']);
$account = strtolower((string) ($opts['account'] ?? 'ige'));
$dir     = ebay_dir($account, 'output');
$raw     = (string) ($opts['raw'] ?? $dir . '/ai_fill_answers_RAW.jsonl');
$out     = (string) ($opts['out'] ?? $dir . '/ai_fill_answers.jsonl');

if (!is_file($raw)) { fwrite(STDERR, "Raw file not found: {$raw}\n"); exit(1); }

// task item_ids for coverage check
$tasks = [];
foreach (file($dir . '/ai_fill_tasks.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $j = json_decode($l, true); if ($j) { $tasks[(string) $j['item_id']] = true; }
}

$byId = []; $lines = 0; $bad = 0; $unknown = 0;
foreach (file($raw, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $lines++;
    $j = json_decode($l, true);
    if (!$j || !isset($j['item_id'], $j['answers'])) { $bad++; fwrite(STDERR, "  malformed line {$lines}\n"); continue; }
    $id = (string) $j['item_id'];
    if (!isset($tasks[$id])) { $unknown++; }
    $byId[$id] = $l;   // last occurrence wins
}

$fh = fopen($out, 'w');
foreach ($byId as $l) { fwrite($fh, $l . "\n"); }
fclose($fh);

$covered = count(array_intersect_key($tasks, $byId));
$missing = array_diff_key($tasks, $byId);

echo "raw lines: {$lines} | malformed: {$bad} | unknown item_ids (not in tasks): {$unknown}\n";
echo "deduped products: " . count($byId) . "  (collapsed " . ($lines - $bad - count($byId)) . " duplicate lines)\n";
echo "coverage: {$covered} / " . count($tasks) . " task products\n";
echo "wrote {$out}\n";
if ($missing) {
    echo "still missing (" . count($missing) . "): ";
    echo implode(' ', array_slice(array_keys($missing), 0, 12)) . (count($missing) > 12 ? ' …' : '') . "\n";
}
