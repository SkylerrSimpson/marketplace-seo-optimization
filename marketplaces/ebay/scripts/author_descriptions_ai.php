<?php

declare(strict_types=1);

use Anthropic\Client;

/**
 * AI-authored descriptions — calls the Anthropic API directly to write the six
 * fields AUTHOR_PROMPT.md specifies (factual/sales/bullets/mobile/title_issue/
 * new_title) per listing, replacing the interactive-Claude-session authoring step.
 *
 * AUTHOR_PROMPT.md, split_author_batches.py, and merge_authored_batch.py are
 * untouched — this is a new, independent path to the exact same output shape, not a
 * replacement of the existing files.
 *
 * Self-contained: reads desc_source_pack.jsonl directly and does its own
 * resumability check against desc_authored.jsonl (skips item_ids already present,
 * same convention split_author_batches.php uses) and its own chunking + merge — no
 * dependency on author_batches/*.jsonl or a separate merge step.
 *
 * Usage:
 *   php marketplaces/ebay/scripts/author_descriptions_ai.php --account=dows --dry-run
 *   php marketplaces/ebay/scripts/author_descriptions_ai.php --account=dows --limit=5
 *   php marketplaces/ebay/scripts/author_descriptions_ai.php --account=dows
 *   php marketplaces/ebay/scripts/author_descriptions_ai.php --account=dows --ids=ID,ID   # reprocess specific items regardless of resumability
 *
 * Flags:
 *   --account=dows|ige   Required.
 *   --chunk-size=N       Listings per API call. Default: 5.
 *   --model=MODEL        Default: claude-sonnet-4-6.
 *   --limit=N            Cap how many unauthored listings to process.
 *   --ids=ID,ID          Only these item_ids, regardless of prior authoring.
 *   --dry-run            Show chunk plan + one example prompt. No API calls, no writes.
 *   --help
 *
 * Environment:
 *   ANTHROPIC_API_KEY    Required (repo-root .env) — same as marketplaces/amazon/scripts/draft_listings.php.
 *
 * Input:  marketplaces/ebay/data/{account}/output/desc_source_pack.jsonl
 * Output: marketplaces/ebay/data/{account}/output/desc_authored.jsonl (upserted by item_id, saved after every chunk)
 *         marketplaces/ebay/data/{account}/output/author_descriptions_ai_errors.csv (failed items, if any)
 */

require __DIR__ . '/../../lib/bootstrap.php';

const MODEL_DEFAULT = 'claude-sonnet-4-6';
const MODEL_RATES = [
    'claude-haiku-4-5'  => ['in' => 1.0, 'out' => 5.0],
    'claude-sonnet-4-6' => ['in' => 3.0, 'out' => 15.0],
    'claude-opus-4-8'   => ['in' => 5.0, 'out' => 25.0],
];

$opts = getopt('', ['account:', 'chunk-size:', 'model:', 'limit:', 'ids:', 'dry-run', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php author_descriptions_ai.php --account=dows|ige [--chunk-size=N] [--model=MODEL] [--limit=N] [--ids=ID,ID] [--dry-run]\n");
    exit(0);
}

$account = strtolower((string) ($opts['account'] ?? ''));
if (!in_array($account, ['dows', 'ige'], true)) {
    fwrite(STDERR, "--account=dows|ige is required.\n");
    exit(1);
}
$chunkSize = max(1, (int) ($opts['chunk-size'] ?? 5));
$model     = (string) ($opts['model'] ?? MODEL_DEFAULT);
$limit     = isset($opts['limit']) ? (int) $opts['limit'] : null;
$onlyIds   = isset($opts['ids'])
    ? array_values(array_filter(array_map('trim', explode(',', (string) $opts['ids']))))
    : null;
$dryRun    = isset($opts['dry-run']);

$outDir       = ebay_dir($account, 'output');
$sourcePath   = $outDir . '/desc_source_pack.jsonl';
$authoredPath = $outDir . '/desc_authored.jsonl';
$promptPath   = __DIR__ . '/AUTHOR_PROMPT.md';
$errorsPath   = $outDir . '/author_descriptions_ai_errors.csv';

if (!is_file($sourcePath)) {
    fwrite(STDERR, "No source pack at {$sourcePath}.\n");
    exit(1);
}
if (!is_file($promptPath)) {
    fwrite(STDERR, "AUTHOR_PROMPT.md not found at {$promptPath}.\n");
    exit(1);
}
$promptText = (string) file_get_contents($promptPath);

// --- load already-authored item_ids (resumability), keyed for upsert -----------
$authored = []; // item_id => decoded row
if (is_file($authoredPath)) {
    foreach (file($authoredPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['item_id'])) {
            $authored[(string) $row['item_id']] = $row;
        }
    }
}

// --- select candidates from the source pack -------------------------------------
$candidates = []; // item_id => source row
foreach (file($sourcePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $row = json_decode($line, true);
    if (!is_array($row) || !isset($row['item_id'])) {
        continue;
    }
    $itemId = (string) $row['item_id'];

    if ($onlyIds !== null) {
        if (!in_array($itemId, $onlyIds, true)) {
            continue;
        }
    } elseif (isset($authored[$itemId])) {
        continue; // already authored — resumability
    }

    $candidates[$itemId] = $row;
}

if ($limit !== null) {
    $candidates = array_slice($candidates, 0, $limit, true);
}

if (!$candidates) {
    echo "Nothing to author for {$account} — 0 listings selected"
        . ($onlyIds ? ' (none of --ids matched the source pack)' : ' (all already in desc_authored.jsonl)')
        . ".\n";
    exit(0);
}

$chunks = array_chunk($candidates, $chunkSize, true);
echo "=== author_descriptions_ai: {$account} — " . count($candidates) . ' listing(s), '
    . count($chunks) . " chunk(s) of up to {$chunkSize}, model={$model} ===\n\n";

if ($dryRun) {
    $exampleChunk = $chunks[0];
    echo '[DRY RUN] Example prompt for chunk 1 (' . count($exampleChunk) . " listing(s)):\n\n";
    echo buildPrompt($promptText, $exampleChunk) . "\n\n";
    echo "[DRY RUN] No API calls made, no files written.\n";
    exit(0);
}

$apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "ANTHROPIC_API_KEY is not set. Add it to .env or export it.\n");
    exit(1);
}
$anthropic = new Client(apiKey: $apiKey);

$stats  = ['ok' => 0, 'error' => 0, 'api_calls' => 0, 'in_tokens' => 0, 'out_tokens' => 0];
$errors = [];

foreach ($chunks as $i => $chunk) {
    $n = $i + 1;
    echo "--- chunk {$n}/" . count($chunks) . ' (' . count($chunk) . " listing(s)) ---\n";

    try {
        $message = $anthropic->messages->create(
            model: $model,
            maxTokens: 4096,
            messages: [['role' => 'user', 'content' => buildPrompt($promptText, $chunk)]],
        );
        $stats['api_calls']++;
        $stats['in_tokens']  += $message->usage->inputTokens;
        $stats['out_tokens'] += $message->usage->outputTokens;

        $rawText = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $rawText = $block->text;
                break;
            }
        }
        $rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
        $rawText = trim((string) preg_replace('/\s*```$/m', '', (string) $rawText));

        $seenInChunk = [];
        foreach (array_filter(array_map('trim', explode("\n", $rawText))) as $line) {
            $parsed = json_decode($line, true);
            [$ok, $reason] = validateAuthoredRow($parsed, $chunk);
            if (!$ok) {
                $stats['error']++;
                $errors[] = ['item_id' => is_array($parsed) ? (string) ($parsed['item_id'] ?? '') : '', 'reason' => $reason, 'raw' => $line];
                echo "  [WARN] {$reason}\n";
                continue;
            }
            $itemId = (string) $parsed['item_id'];
            $authored[$itemId] = [
                'item_id'     => $itemId,
                'factual'     => $parsed['factual'],
                'sales'       => $parsed['sales'],
                'bullets'     => $parsed['bullets'],
                'mobile'      => $parsed['mobile'],
                'title_issue' => $parsed['title_issue'],
                'new_title'   => $parsed['new_title'],
            ];
            $seenInChunk[$itemId] = true;
            $stats['ok']++;
            echo "  ok: {$itemId}\n";
        }

        foreach (array_keys($chunk) as $itemId) {
            if (!isset($seenInChunk[$itemId])) {
                $stats['error']++;
                $errors[] = ['item_id' => $itemId, 'reason' => 'no output line for this item_id', 'raw' => ''];
                echo "  [WARN] no output for {$itemId}\n";
            }
        }
    } catch (\Throwable $e) {
        foreach (array_keys($chunk) as $itemId) {
            $stats['error']++;
            $errors[] = ['item_id' => $itemId, 'reason' => 'API call failed: ' . $e->getMessage(), 'raw' => ''];
        }
        echo "  [ERROR] chunk {$n} failed: " . $e->getMessage() . "\n";
    }

    // Save progress after every chunk — resumable if the run is killed midway.
    $fh = fopen($authoredPath, 'w');
    foreach ($authored as $row) {
        fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
    }
    fclose($fh);
}

if ($errors) {
    $fh = fopen($errorsPath, 'w');
    fputcsv($fh, ['item_id', 'reason', 'raw']);
    foreach ($errors as $e) {
        fputcsv($fh, [$e['item_id'], $e['reason'], $e['raw']]);
    }
    fclose($fh);
}

echo "\n========================================\n";
echo "authored: {$stats['ok']} ok, {$stats['error']} error (of " . count($candidates) . ")\n";
echo "api calls: {$stats['api_calls']}\n";
if (isset(MODEL_RATES[$model])) {
    $rate = MODEL_RATES[$model];
    $cost = $stats['in_tokens'] / 1e6 * $rate['in'] + $stats['out_tokens'] / 1e6 * $rate['out'];
    printf("tokens: in=%s out=%s  est. cost: \$%.4f\n", number_format($stats['in_tokens']), number_format($stats['out_tokens']), $cost);
}
echo "  {$authoredPath}\n";
if ($errors) {
    echo "  {$errorsPath}\n";
}

exit($stats['error'] > 0 ? 1 : 0);

// --- helpers ---------------------------------------------------------------------

/** @param array<string, array<string, mixed>> $chunk item_id => source row */
function buildPrompt(string $promptText, array $chunk): string
{
    $listings = [];
    foreach ($chunk as $itemId => $row) {
        $listings[] = [
            'item_id'            => $itemId,
            'title'              => $row['title'] ?? '',
            'short_description'  => $row['short_description'] ?? '',
            'narrative'          => $row['narrative'] ?? [],
            'feature_bullets'    => $row['feature_bullets'] ?? [],
            'aspects'            => $row['aspects'] ?? [],
        ];
    }

    return $promptText . "\n\n## Input batch\n\n" . json_encode($listings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * @param  mixed  $parsed
 * @param  array<string, array<string, mixed>>  $chunk
 * @return array{0: bool, 1: string}
 */
function validateAuthoredRow(mixed $parsed, array $chunk): array
{
    if (!is_array($parsed)) {
        return [false, 'non-JSON output line'];
    }
    $itemId = (string) ($parsed['item_id'] ?? '');
    if ($itemId === '' || !isset($chunk[$itemId])) {
        return [false, "item_id '{$itemId}' not in this chunk"];
    }

    foreach (['factual', 'sales', 'mobile'] as $field) {
        if (!isset($parsed[$field]) || !is_string($parsed[$field]) || trim($parsed[$field]) === '') {
            return [false, "{$itemId}: missing/blank '{$field}'"];
        }
    }
    if (!isset($parsed['bullets']) || !is_array($parsed['bullets']) || count($parsed['bullets']) === 0) {
        return [false, "{$itemId}: 'bullets' missing or empty"];
    }
    if (!array_key_exists('title_issue', $parsed) || !is_bool($parsed['title_issue'])) {
        return [false, "{$itemId}: 'title_issue' missing or not boolean"];
    }
    $newTitle = (string) ($parsed['new_title'] ?? '');
    if ($parsed['title_issue'] && $newTitle === '') {
        return [false, "{$itemId}: title_issue=true but new_title is empty"];
    }
    if (!$parsed['title_issue'] && $newTitle !== '') {
        return [false, "{$itemId}: title_issue=false but new_title is non-empty"];
    }
    if (mb_strlen($newTitle) > 80) {
        return [false, "{$itemId}: new_title exceeds 80 chars (" . mb_strlen($newTitle) . ')'];
    }

    return [true, ''];
}
