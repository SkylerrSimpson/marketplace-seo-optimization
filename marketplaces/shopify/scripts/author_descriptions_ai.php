<?php

declare(strict_types=1);

use Anthropic\Client;

/**
 * AI-authored SEO meta descriptions + image alt text — calls the Anthropic API
 * directly to draft the two fields Assemble Output/Apply Metadata read
 * (drafts_manual.json, drafts_alt.json), replacing the in-session manual
 * authoring step. Mirrors marketplaces/ebay/scripts/author_descriptions_ai.php's
 * proven shape (chunking, dry-run cost preview, resumability, per-chunk save)
 * for the same reason: a script this app can just shell out to, not app-layer logic.
 *
 * The meta-description prompt is loaded verbatim from
 * marketplaces/shopify/rules/product-metadata-rules.md §4 — the already-reviewed
 * drafting rules (140-160 chars, condense-the-real-body-don't-invent, voice, hard
 * don'ts) — not re-derived here. Image alt text has its own inline prompt below:
 * that rules doc predates alt text being in scope (see its own §"Scope locked"
 * note), so there's no existing prompt to reuse for it.
 *
 * Self-contained: reads phase2_input.json (export_descriptions.php) and, when
 * present, image_alts.json (export_image_alts.php) directly. Does its own
 * resumability check against drafts_manual.json (skips numeric_ids already
 * drafted, same convention the eBay script uses against desc_authored.jsonl) and
 * its own chunking + upsert-merge — no separate merge step.
 *
 * Usage:
 *   php marketplaces/shopify/scripts/author_descriptions_ai.php --dry-run
 *   php marketplaces/shopify/scripts/author_descriptions_ai.php --limit=5
 *   php marketplaces/shopify/scripts/author_descriptions_ai.php
 *   php marketplaces/shopify/scripts/author_descriptions_ai.php --ids=ID,ID   # reprocess specific products regardless of resumability
 *
 * Flags:
 *   --chunk-size=N   Products per API call. Default: 5.
 *   --model=MODEL    Default: claude-sonnet-4-6.
 *   --limit=N        Cap how many undrafted products to process.
 *   --ids=ID,ID      Only these numeric_ids, regardless of prior drafting.
 *   --dry-run        Show chunk plan + one example prompt. No API calls, no writes.
 *   --help
 *
 * Environment:
 *   ANTHROPIC_API_KEY   Required (repo-root .env) — same as the eBay script.
 *
 * Input:  data/input/phase2_input.json (required — from export_descriptions.php)
 *         data/input/image_alts.json (optional, from export_image_alts.php —
 *         alt text skipped per product if this file or that product's entry
 *         doesn't exist)
 * Output: data/drafts/drafts_manual.json (upserted by numeric_id, saved after every chunk)
 *         data/drafts/drafts_alt.json (upserted by numeric_id, saved after every chunk)
 *         data/output/author_descriptions_ai_errors.csv (failed items, if any)
 */

require __DIR__ . '/../../lib/bootstrap.php';

const MODEL_DEFAULT = 'claude-sonnet-4-6';
const MODEL_RATES = [
    'claude-haiku-4-5'  => ['in' => 1.0, 'out' => 5.0],
    'claude-sonnet-4-6' => ['in' => 3.0, 'out' => 15.0],
    'claude-opus-4-8'   => ['in' => 5.0, 'out' => 25.0],
];
const SEO_MIN = 140; const SEO_MAX = 160; const SEO_HARD_MIN = 70;
const ALT_MAX = 125; // standard practical alt-text ceiling — screen readers, not a Shopify hard limit

$opts = getopt('', ['chunk-size:', 'model:', 'limit:', 'ids:', 'dry-run', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php author_descriptions_ai.php [--chunk-size=N] [--model=MODEL] [--limit=N] [--ids=ID,ID] [--dry-run]\n");
    exit(0);
}

$chunkSize = max(1, (int) ($opts['chunk-size'] ?? 5));
$model     = (string) ($opts['model'] ?? MODEL_DEFAULT);
$limit     = isset($opts['limit']) ? (int) $opts['limit'] : null;
$onlyIds   = isset($opts['ids'])
    ? array_values(array_filter(array_map('trim', explode(',', (string) $opts['ids']))))
    : null;
$dryRun    = isset($opts['dry-run']);

$inputPath    = SHOPIFY_INPUT . '/phase2_input.json';
$altInputPath = SHOPIFY_INPUT . '/image_alts.json';
$draftsPath   = SHOPIFY_DRAFTS . '/drafts_manual.json';
$altPath      = SHOPIFY_DRAFTS . '/drafts_alt.json';
$rulesPath    = __DIR__ . '/../rules/product-metadata-rules.md';
$errorsPath   = SHOPIFY_OUTPUT . '/author_descriptions_ai_errors.csv';

if (!is_file($inputPath)) {
    fwrite(STDERR, "No source input at {$inputPath} — run export_descriptions.php first.\n");
    exit(1);
}
if (!is_file($rulesPath)) {
    fwrite(STDERR, "product-metadata-rules.md not found at {$rulesPath}.\n");
    exit(1);
}
$rulesText = (string) file_get_contents($rulesPath);
// Grounding for the drafter, not the rules doc's own headers/sample-drafts noise
// — just §4's actual prompt block, extracted the same way a human copy-pasting
// it would: between the "## 4." heading and the next "---".
if (!preg_match('/## 4\. AI drafting prompt.*?\n(.*?)\n---/s', $rulesText, $m)) {
    fwrite(STDERR, "Could not find '## 4. AI drafting prompt' section in {$rulesPath}.\n");
    exit(1);
}
$metaDescriptionRules = trim($m[1]);

// --- load already-drafted numeric_ids (resumability), keyed for upsert -----------
$drafts = is_file($draftsPath) ? (json_decode((string) file_get_contents($draftsPath), true) ?: []) : [];
$altDrafts = is_file($altPath) ? (json_decode((string) file_get_contents($altPath), true) ?: []) : [];
$imageAlts = is_file($altInputPath) ? (json_decode((string) file_get_contents($altInputPath), true) ?: []) : [];

// --- select candidates from phase2_input.json -----------------------------------
$input = json_decode((string) file_get_contents($inputPath), true) ?: [];
$candidates = []; // numeric_id => source row
foreach ($input as $row) {
    if (!is_array($row) || !isset($row['numeric_id'])) {
        continue;
    }
    $id = (string) $row['numeric_id'];

    if ($onlyIds !== null) {
        if (!in_array($id, $onlyIds, true)) {
            continue;
        }
    } elseif (isset($drafts[$id]) && trim((string) $drafts[$id]) !== '') {
        continue; // already drafted — resumability
    }

    $candidates[$id] = $row;
}

if ($limit !== null) {
    $candidates = array_slice($candidates, 0, $limit, true);
}

if (!$candidates) {
    echo 'Nothing to draft — 0 products selected'
        . ($onlyIds ? ' (none of --ids matched phase2_input.json)' : ' (all already in drafts_manual.json)')
        . ".\n";
    exit(0);
}

$chunks = array_chunk($candidates, $chunkSize, true);
echo '=== author_descriptions_ai (Shopify): ' . count($candidates) . ' product(s), '
    . count($chunks) . " chunk(s) of up to {$chunkSize}, model={$model} ===\n\n";

if ($dryRun) {
    $exampleChunk = $chunks[0];
    echo '[DRY RUN] Example prompt for chunk 1 (' . count($exampleChunk) . " product(s)):\n\n";
    echo buildPrompt($metaDescriptionRules, $exampleChunk, $imageAlts) . "\n\n";
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
    echo "--- chunk {$n}/" . count($chunks) . ' (' . count($chunk) . " product(s)) ---\n";

    try {
        $message = $anthropic->messages->create(
            model: $model,
            maxTokens: 4096,
            messages: [['role' => 'user', 'content' => buildPrompt($metaDescriptionRules, $chunk, $imageAlts)]],
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
                $errors[] = ['numeric_id' => is_array($parsed) ? (string) ($parsed['numeric_id'] ?? '') : '', 'reason' => $reason, 'raw' => $line];
                echo "  [WARN] {$reason}\n";
                continue;
            }
            $id = (string) $parsed['numeric_id'];
            $drafts[$id] = $parsed['meta_description'];
            $alt = trim((string) ($parsed['image_alt'] ?? ''));
            if ($alt !== '' && isset($imageAlts[$id])) {
                $altDrafts[$id] = $alt;
            }
            $seenInChunk[$id] = true;
            $stats['ok']++;
            echo "  ok: {$id}\n";
        }

        foreach (array_keys($chunk) as $id) {
            if (!isset($seenInChunk[$id])) {
                $stats['error']++;
                $errors[] = ['numeric_id' => $id, 'reason' => 'no output line for this numeric_id', 'raw' => ''];
                echo "  [WARN] no output for {$id}\n";
            }
        }
    } catch (\Throwable $e) {
        foreach (array_keys($chunk) as $id) {
            $stats['error']++;
            $errors[] = ['numeric_id' => $id, 'reason' => 'API call failed: ' . $e->getMessage(), 'raw' => ''];
        }
        echo "  [ERROR] chunk {$n} failed: " . $e->getMessage() . "\n";
    }

    // Save progress after every chunk — resumable if the run is killed midway.
    file_put_contents($draftsPath, json_encode($drafts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT));
    file_put_contents($altPath, json_encode($altDrafts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT));
}

if ($errors) {
    $fh = fopen($errorsPath, 'w');
    fputcsv($fh, ['numeric_id', 'reason', 'raw']);
    foreach ($errors as $e) {
        fputcsv($fh, [$e['numeric_id'], $e['reason'], $e['raw']]);
    }
    fclose($fh);
}

echo "\n========================================\n";
echo "drafted: {$stats['ok']} ok, {$stats['error']} error (of " . count($candidates) . ")\n";
echo "api calls: {$stats['api_calls']}\n";
if (isset(MODEL_RATES[$model])) {
    $rate = MODEL_RATES[$model];
    $cost = $stats['in_tokens'] / 1e6 * $rate['in'] + $stats['out_tokens'] / 1e6 * $rate['out'];
    printf("tokens: in=%s out=%s  est. cost: \$%.4f\n", number_format($stats['in_tokens']), number_format($stats['out_tokens']), $cost);
}
echo "  {$draftsPath}\n";
echo "  {$altPath}\n";
if ($errors) {
    echo "  {$errorsPath}\n";
}

exit($stats['error'] > 0 ? 1 : 0);

// --- helpers ---------------------------------------------------------------------

/**
 * @param  array<string, array<string, mixed>>  $chunk  numeric_id => source row
 * @param  array<string, array<string, mixed>>  $imageAlts  numeric_id => {title, media_id, old_alt, url}
 */
function buildPrompt(string $metaDescriptionRules, array $chunk, array $imageAlts): string
{
    $altInstructions = <<<'TXT'
For each product that has an "image" block below, also write concise, descriptive
alt text for its featured product image: what the image actually shows (the
product itself, material/color/context visible in it), grounded only in the
product's title/body — never invent what's in the photo beyond what the product
data already implies. Plain, factual, no "image of" / "picture of" prefix, no
marketing language, under 125 characters. If a product has no "image" block,
leave image_alt as "".
TXT;

    $products = [];
    foreach ($chunk as $id => $row) {
        $entry = [
            'numeric_id'   => $id,
            'title'        => $row['title'] ?? '',
            'product_type' => $row['product_type'] ?? '',
            'category'     => $row['category'] ?? '',
            'body'         => $row['body_text'] ?? '',
        ];
        if (isset($imageAlts[$id])) {
            $entry['image'] = ['current_alt' => $imageAlts[$id]['old_alt'] ?? ''];
        }
        $products[] = $entry;
    }

    return $metaDescriptionRules . "\n\n" . $altInstructions
        . "\n\nRespond with EXACTLY one line of raw JSON per product (no markdown fences, no"
        . "\ncommentary), in this shape:"
        . "\n{\"numeric_id\":\"...\",\"meta_description\":\"...\",\"image_alt\":\"...\"}"
        . "\n\n## Input batch\n\n" . json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
    $id = (string) ($parsed['numeric_id'] ?? '');
    if ($id === '' || !isset($chunk[$id])) {
        return [false, "numeric_id '{$id}' not in this chunk"];
    }

    $meta = $parsed['meta_description'] ?? null;
    if (!is_string($meta) || trim($meta) === '') {
        return [false, "{$id}: missing/blank 'meta_description'"];
    }
    $len = mb_strlen($meta);
    if ($len > SEO_MAX || $len < SEO_HARD_MIN) {
        return [false, "{$id}: meta_description length {$len} outside " . SEO_HARD_MIN . '-' . SEO_MAX . ' hard band'];
    }

    if (isset($parsed['image_alt']) && is_string($parsed['image_alt']) && mb_strlen($parsed['image_alt']) > ALT_MAX) {
        return [false, "{$id}: image_alt exceeds " . ALT_MAX . ' chars (' . mb_strlen($parsed['image_alt']) . ')'];
    }

    return [true, ''];
}
