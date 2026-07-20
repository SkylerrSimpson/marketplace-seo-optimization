<?php

declare(strict_types=1);

use Ige\Amazon\Ai\CostEstimator;
use Ige\Amazon\Ai\ModelConfig;
use Ige\Amazon\Ai\ModularTitleGenerator;
use Ige\Amazon\Ai\ProductContext;
use Ige\Amazon\Ai\Provider\AnthropicProvider;
use Ige\Amazon\Ai\Provider\OpenAiProvider;
use Ige\Amazon\Ai\Provider\ProviderInterface;

/**
 * Generate Amazon modular titles (item_name + title_differentiation) for review.
 *
 * For each gap-fill SKU (or a single --sku), calls each requested LLM provider
 * once — a combined ModularTitleGenerator request that returns both attributes —
 * and writes both providers' candidates side by side to
 * amazon/data/{account}/compare/{sku}.json, plus a human-readable
 * output/title_compare.csv rebuilt from all compare files.
 *
 * This script only emits the compare artifacts; it never writes drafts. Phase 7
 * (draft_listings.php) reads compare/{sku}.json and folds both options into the
 * draft under separate keys, where the winner is picked at review/patch time.
 *
 * Usage:
 *   php amazon/scripts/generate_titles.php [--account=IGE|DOWS] [OPTIONS]
 *
 * Flags:
 *   --account=IGE|DOWS        Seller account. Default: IGE.
 *   --provider=both|anthropic|openai
 *                             Which providers to run. Default: both.
 *   --anthropic-model=MODEL   Override the Anthropic model (alias or full ID:
 *                             haiku/sonnet/opus). Default: claude-sonnet-5.
 *   --openai-model=MODEL      Override the OpenAI model (e.g. gpt-4o, gpt-4.1).
 *                             Default: gpt-4o.
 *   --sku=SKU                 Process a single SKU only.
 *   --force                   Overwrite existing compare files.
 *   --dry-run                 Preview only; no API calls, no files written.
 *   --help                    Show this help message.
 *
 * Environment:
 *   ANTHROPIC_API_KEY         Required unless --provider=openai.
 *   OPENAI_API_KEY            Required unless --provider=anthropic.
 *
 * Inputs:
 *   amazon/data/{account}/output/listings_gap_fill.csv   (SKU universe)
 *   amazon/data/{account}/input/listings/{sku}.json
 *   amazon/data/{account}/input/catalog/{asin}.json
 *   amazon/data/schemas/{PRODUCT_TYPE}.json
 *
 * Output:
 *   amazon/data/{account}/compare/{sku}.json
 *   amazon/data/{account}/output/title_compare.csv
 */

require __DIR__ . '/../../lib/bootstrap.php';

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/generate_titles.php [--account=IGE|DOWS] [OPTIONS]

Flags:
  --account=IGE|DOWS   Seller account. Default: IGE.
  --provider=both|anthropic|openai
                       Which providers to run. Default: both.
  --anthropic-model=M  Override Anthropic model (haiku/sonnet/opus or full ID).
  --openai-model=M     Override OpenAI model (gpt-4o, gpt-4o-mini, gpt-4.1, ...).
  --sku=SKU            Process a single SKU only.
  --force              Overwrite existing compare files.
  --dry-run            Preview only; no API calls, no files written.
  --help               Show this help message.

Writes amazon/data/{account}/compare/{sku}.json (both providers' item_name and
title_differentiation candidates) and rebuilds output/title_compare.csv. Drafts
are written later by draft_listings.php, which consumes the compare files.
HELP;
    echo PHP_EOL;
    exit(0);
}

$account       = 'IGE';
$providerArg   = 'both';
$singleSku     = null;
$force         = false;
$dryRun        = false;
$modelOverride = [];

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    } elseif (preg_match('/^--provider=(both|anthropic|openai)$/i', $arg, $m)) {
        $providerArg = strtolower($m[1]);
    } elseif (preg_match('/^--anthropic-model=(.+)$/', $arg, $m)) {
        $modelOverride[ModelConfig::ANTHROPIC] = $m[1];
    } elseif (preg_match('/^--openai-model=(.+)$/', $arg, $m)) {
        $modelOverride[ModelConfig::OPENAI] = $m[1];
    } elseif (preg_match('/^--sku=(.+)$/', $arg, $m)) {
        $singleSku = $m[1];
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--provider=/i', $arg)) {
        fwrite(STDERR, "Invalid --provider (use both|anthropic|openai): {$arg}\n");
        exit(1);
    }
}

$providerIds = $providerArg === 'both' ? ModelConfig::PROVIDERS : [$providerArg];
$paths       = amazon_paths($account);
$comparePath = $paths['data'] . '/compare';
if (!is_dir($comparePath)) {
    mkdir($comparePath, 0755, true);
}

$models = [];
foreach ($providerIds as $pid) {
    $models[$pid] = ModelConfig::resolveModel($pid, $modelOverride[$pid] ?? '');
}

echo 'Account   : ' . $account . PHP_EOL;
foreach ($providerIds as $pid) {
    echo 'Provider  : ' . str_pad($pid, 9) . ' -> ' . $models[$pid] . PHP_EOL;
}
if ($dryRun) {
    echo '[DRY RUN — no API calls, no files written]' . PHP_EOL;
}
echo PHP_EOL;

// --- Build providers (skipped in dry-run) -----------------------------------
$generators = []; // pid => ModularTitleGenerator
if (!$dryRun) {
    foreach ($providerIds as $pid) {
        $key = ModelConfig::apiKey($pid);
        if ($key === '') {
            $var = ModelConfig::API_KEY_ENV[$pid];
            fwrite(STDERR, "{$var} is not set. Add it to .env or export it (or narrow --provider).\n");
            exit(1);
        }
        $provider = $pid === ModelConfig::ANTHROPIC
            ? AnthropicProvider::fromApiKey($key, $models[$pid])
            : OpenAiProvider::fromApiKey($key, $models[$pid]);
        assert($provider instanceof ProviderInterface);
        $generators[$pid] = new ModularTitleGenerator($provider);
    }
}

// --- Collect SKU universe from the gap-fill CSV -----------------------------
$gapFile = $paths['output'] . '/listings_gap_fill.csv';
if (!file_exists($gapFile)) {
    fwrite(STDERR, "Gap-fill CSV not found: {$gapFile}\n");
    fwrite(STDERR, "Run analyze_gap_fill.php --account={$account} first.\n");
    exit(1);
}

$fhG    = fopen($gapFile, 'r');
$header = fgetcsv($fhG, 0, ',', '"', '');
$skus   = []; // sku => ['asin' => ..., 'product_type' => ...]

while (($row = fgetcsv($fhG, 0, ',', '"', '')) !== false) {
    if (!$header || count($row) !== count($header)) {
        continue;
    }
    $r   = array_combine($header, $row);
    $sku = $r['sku'] ?? '';
    if ($sku === '' || ($singleSku !== null && $sku !== $singleSku)) {
        continue;
    }
    $skus[$sku] ??= ['asin' => $r['asin'] ?? '', 'product_type' => $r['product_type'] ?? ''];
}
fclose($fhG);

if (!$skus) {
    echo $singleSku
        ? "SKU '{$singleSku}' not found in gap-fill CSV." . PHP_EOL
        : 'No SKUs found in gap-fill CSV.' . PHP_EOL;
    exit(0);
}

echo 'SKUs       : ' . count($skus) . PHP_EOL . PHP_EOL;

$stats = ['written' => 0, 'skipped' => 0, 'no_context' => 0, 'errors' => 0, 'api_calls' => 0];

// Dry-run cost projection: one modular-title call per provider per SKU.
$costEstimator = new CostEstimator();

foreach ($skus as $sku => $meta) {
    echo "[{$sku}]" . PHP_EOL;

    $listingFile = $paths['listings'] . '/' . $sku . '.json';
    if (!file_exists($listingFile)) {
        echo '  [WARN] no listing snapshot — skipped' . PHP_EOL;
        $stats['skipped']++;
        continue;
    }
    $listing = json_decode((string) file_get_contents($listingFile), true) ?? [];

    $asin        = $meta['asin'] !== '' ? $meta['asin'] : (string) ($listing['summaries'][0]['asin'] ?? '');
    $productType = $meta['product_type'] !== '' ? $meta['product_type'] : ProductContext::resolveProductType($listing);

    $schemaFile = $paths['schemas'] . '/' . $productType . '.json';
    if ($productType === '' || !file_exists($schemaFile)) {
        echo "  [WARN] no schema for product type '{$productType}' — skipped" . PHP_EOL;
        $stats['skipped']++;
        continue;
    }
    $schema = json_decode((string) file_get_contents($schemaFile), true) ?? [];

    $catalogFile  = $paths['catalog'] . '/' . $asin . '.json';
    $catalogAttrs = file_exists($catalogFile)
        ? (json_decode((string) file_get_contents($catalogFile), true)['attributes'] ?? [])
        : [];

    $ctx = ProductContext::fromSnapshots($listing, $catalogAttrs, $schema);
    if (!$ctx->hasContext()) {
        echo '  no product context (title/description/features all empty) — skipped' . PHP_EOL;
        $stats['no_context']++;
        continue;
    }

    $compareFile = $comparePath . '/' . $sku . '.json';
    if (file_exists($compareFile) && !$force) {
        echo '  compare file exists — skipped (use --force)' . PHP_EOL;
        $stats['skipped']++;
        continue;
    }

    if ($dryRun) {
        $preview = ModularTitleGenerator::previewRequest($ctx);
        $inToks  = CostEstimator::estimateTokens($preview['prompt']);
        foreach ($providerIds as $pid) {
            $costEstimator->add($models[$pid], $inToks, $preview['maxOutputTokens']);
            echo '  [DRY RUN] would call ' . $pid . ' (' . $models[$pid] . ') for item_name + title_differentiation' . PHP_EOL;
        }
        continue;
    }

    // The prompt is provider-agnostic (both providers receive the identical
    // string from ModularTitleGenerator), so record it once for debugging.
    $compare = [
        'sku'                   => $sku,
        'asin'                  => $asin,
        'product_type'          => $productType,
        'account'               => $account,
        'generated_at'          => gmdate('c'),
        'providers'             => $providerIds,
        'prompt'                => ModularTitleGenerator::previewRequest($ctx)['prompt'],
        'item_name'             => [],
        'title_differentiation' => [],
    ];

    foreach ($providerIds as $pid) {
        try {
            $out = $generators[$pid]->generate($ctx);
            $stats['api_calls']++;
            $costEstimator->add(
                $models[$pid],
                (int) ($out['usage']['input'] ?? 0),
                (int) ($out['usage']['output'] ?? 0),
            );

            $compare['item_name'][$pid]             = $out['item_name'];
            $compare['title_differentiation'][$pid] = $out['title_differentiation'];

            if ($out['item_name']['item_name'] === null || $out['title_differentiation']['title_differentiation'] === null) {
                $compare['raw'][$pid] = $out['raw'];
            }

            $in = $out['item_name'];
            $td = $out['title_differentiation'];
            echo '  ' . str_pad($pid, 9)
                . ' item_name=' . generate_titles_badge($in['char_count'], $in['validation_error'])
                . '  title_differentiation=' . generate_titles_badge($td['char_count'], $td['validation_error']) . PHP_EOL;
        } catch (\Throwable $e) {
            $err = ['provider' => $pid, 'model' => $models[$pid], 'error' => $e->getMessage()];
            $compare['item_name'][$pid]             = $err;
            $compare['title_differentiation'][$pid] = $err;
            echo '  [ERROR] ' . $pid . ': ' . $e->getMessage() . PHP_EOL;
            $stats['errors']++;
        }
    }

    file_put_contents($compareFile, json_encode($compare, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $stats['written']++;
}

if (!$dryRun && $stats['written'] > 0) {
    $csv = rebuild_title_compare_csv($comparePath, $paths['output'] . '/title_compare.csv');
    echo PHP_EOL . 'Report     : ' . $csv . PHP_EOL;

    // Seed / refresh the editable decision sheet (preserves any existing picks).
    $decisions = $paths['output'] . '/title_decisions.csv';
    \Ige\Amazon\Ai\TitleDecisions::rebuildSheet($comparePath, $decisions);
    echo 'Decisions  : ' . $decisions . ' (set *_pick per row, then patch/project)' . PHP_EOL;
}

if (!$costEstimator->isEmpty()) {
    $heading = $dryRun
        ? 'Estimated cost (rough — heuristic output tokens):'
        : 'Actual cost (from API token usage):';
    echo PHP_EOL . $costEstimator->report($heading);
    if ($dryRun) {
        echo 'Remove --dry-run to generate titles.' . PHP_EOL;
    }
}

echo PHP_EOL . 'Done. '
    . "written={$stats['written']} skipped={$stats['skipped']} "
    . "no_context={$stats['no_context']} errors={$stats['errors']} api_calls={$stats['api_calls']}" . PHP_EOL;

/**
 * One-line console badge for a generated value: char count, or the validation
 * error / MISSING when absent.
 */
function generate_titles_badge(?int $charCount, ?string $error): string
{
    if ($charCount === null) {
        return $error ?? 'MISSING';
    }
    return $charCount . 'c' . ($error !== null ? " [{$error}]" : '');
}

/**
 * Rebuild the human-readable comparison CSV from every compare/{sku}.json so the
 * report always reflects the full set regardless of single-SKU runs.
 */
function rebuild_title_compare_csv(string $comparePath, string $csvFile): string
{
    $rows = [];
    foreach (glob($comparePath . '/*.json') ?: [] as $file) {
        $c = json_decode((string) file_get_contents($file), true);
        if (!is_array($c)) {
            continue;
        }
        $get = static function (array $c, string $attr, string $pid, string $field) {
            $v = $c[$attr][$pid][$field] ?? null;
            return $v === null ? '' : (string) $v;
        };
        $rows[] = [
            $c['sku'] ?? '',
            $c['asin'] ?? '',
            $c['product_type'] ?? '',
            $get($c, 'item_name', 'anthropic', 'item_name'),
            $get($c, 'item_name', 'anthropic', 'char_count'),
            $get($c, 'item_name', 'openai', 'item_name'),
            $get($c, 'item_name', 'openai', 'char_count'),
            $get($c, 'title_differentiation', 'anthropic', 'title_differentiation'),
            $get($c, 'title_differentiation', 'anthropic', 'char_count'),
            $get($c, 'title_differentiation', 'openai', 'title_differentiation'),
            $get($c, 'title_differentiation', 'openai', 'char_count'),
            $c['generated_at'] ?? '',
        ];
    }

    usort($rows, static fn ($a, $b): int => strcmp((string) $a[0], (string) $b[0]));

    $fh = fopen($csvFile, 'w');
    fputcsv($fh, [
        'sku', 'asin', 'product_type',
        'item_name_anthropic', 'item_name_anthropic_chars',
        'item_name_openai', 'item_name_openai_chars',
        'title_differentiation_anthropic', 'title_differentiation_anthropic_chars',
        'title_differentiation_openai', 'title_differentiation_openai_chars',
        'generated_at',
    ], ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ',', '"', '');
    }
    fclose($fh);

    return $csvFile;
}
