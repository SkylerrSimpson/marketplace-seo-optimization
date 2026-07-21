<?php

declare(strict_types=1);

use Ige\Amazon\Ai\Batch\AnthropicBatchProvider;
use Ige\Amazon\Ai\Batch\BatchManifest;
use Ige\Amazon\Ai\Batch\BatchRunner;
use Ige\Amazon\Ai\Batch\OpenAiBatchProvider;
use Ige\Amazon\Ai\Concurrent\ParallelJob;
use Ige\Amazon\Ai\Concurrent\ParallelRunner;
use Ige\Amazon\Ai\CostEstimator;
use Ige\Amazon\Ai\ItemNameGenerator;
use Ige\Amazon\Ai\ModelConfig;
use Ige\Amazon\Ai\ModularTitleGenerator;
use Ige\Amazon\Ai\ProductContext;
use Ige\Amazon\Ai\Provider\AnthropicProvider;
use Ige\Amazon\Ai\Provider\OpenAiProvider;
use Ige\Amazon\Ai\Provider\ProviderInterface;
use Ige\Amazon\Ai\TitleDifferentiationGenerator;

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
 *   --batch                   Use each provider's Batch API instead of live
 *                             per-SKU calls: ~50% cheaper and processed
 *                             concurrently, so a full account finishes in one
 *                             batch window instead of thousands of serial calls.
 *                             The run blocks until the batches complete. On
 *                             submit it writes a manifest of the batch ids so an
 *                             interrupted run can be resumed or cancelled.
 *   --parallel                Full-price live calls, but all fired concurrently
 *                             through one HTTP pool instead of serially, so no
 *                             request blocks another and results come back
 *                             immediately (no batch window). Mutually exclusive
 *                             with --batch/--resume/--cancel.
 *   --concurrency=N           With --parallel, max requests in flight (10).
 *   --resume                  Reattach to the batches recorded in the manifest,
 *                             take one status pass and assemble results for
 *                             whichever providers have finished. Providers still
 *                             running don't block the finished ones; the manifest
 *                             is kept so a later --resume collects them too.
 *   --cancel                  Cancel the manifest's in-flight batches and remove
 *                             it. --provider/--model args are ignored; the
 *                             manifest is the source of truth.
 *   --poll-interval=SECONDS   With --batch, seconds between status polls (30).
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
  --batch              Use each provider's Batch API (~50% cheaper, concurrent).
                       Submits one batch per provider and blocks until done.
  --parallel           Full-price live calls fired concurrently (no batch window,
                       immediate results). Mutually exclusive with --batch.
  --concurrency=N      With --parallel, max requests in flight (default 10).
  --resume             Reattach to an interrupted --batch run (from its manifest)
                       and assemble results for whichever providers have finished
                       (a still-running provider won't block the finished ones).
  --cancel             Cancel the manifest's in-flight batches and remove it.
  --poll-interval=SEC  With --batch, seconds between status polls (default 30).
  --force              Overwrite existing compare files.
  --dry-run            Preview only; no API calls, no files written.
  --help               Show this help message.

Writes amazon/data/{account}/compare/{sku}.json (both providers' item_name and
title_differentiation candidates) and rebuilds output/title_compare.csv. Drafts
are written later by draft_listings.php, which consumes the compare files.

--batch records the submitted batch ids in amazon/data/{account}/batch-manifest.json
and removes it once results are assembled. If a run is interrupted mid-poll the
manifest survives: --resume reattaches to those same batches (no resubmit, no
double billing), and --cancel tears them down.
HELP;
    echo PHP_EOL;
    exit(0);
}

$account       = 'IGE';
$providerArg   = 'both';
$singleSku     = null;
$force         = false;
$dryRun        = false;
$batch         = false;
$parallel      = false;
$concurrency   = 10;
$resume        = false;
$cancel        = false;
$pollInterval  = 30;
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
    } elseif ($arg === '--batch') {
        $batch = true;
    } elseif ($arg === '--parallel') {
        $parallel = true;
    } elseif (preg_match('/^--concurrency=(\d+)$/', $arg, $m)) {
        $concurrency = max(1, (int) $m[1]);
    } elseif ($arg === '--resume') {
        $batch  = true;
        $resume = true;
    } elseif ($arg === '--cancel') {
        $batch  = true;
        $cancel = true;
    } elseif (preg_match('/^--poll-interval=(\d+)$/', $arg, $m)) {
        $pollInterval = max(1, (int) $m[1]);
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--provider=/i', $arg)) {
        fwrite(STDERR, "Invalid --provider (use both|anthropic|openai): {$arg}\n");
        exit(1);
    }
}

if ($parallel && ($batch || $resume || $cancel)) {
    fwrite(STDERR, "--parallel cannot be combined with --batch/--resume/--cancel.\n");
    exit(1);
}

// Batch and parallel are the two multi-request modes: both build per-provider
// requests keyed by a positional custom_id and assemble the same
// pid => custom_id => ProviderResult shape. They differ only in dispatch
// (async half-price batch window vs. immediate full-price concurrent pool).
$multi       = $batch || $parallel;
$providerIds = $providerArg === 'both' ? ModelConfig::PROVIDERS : [$providerArg];
$paths       = amazon_paths($account);
$comparePath = $paths['data'] . '/compare';
if (!is_dir($comparePath)) {
    mkdir($comparePath, 0755, true);
}

// --resume / --cancel act on a previously submitted run: the manifest is the
// source of truth for which providers, models and batch ids to attach to, so it
// overrides the provider/model CLI args entirely.
$manifest = null;
if ($resume || $cancel) {
    $manifest = BatchManifest::load($paths['data']);
    if ($manifest === null) {
        fwrite(STDERR, 'No batch manifest at ' . BatchManifest::path($paths['data'])
            . ' — nothing to ' . ($cancel ? 'cancel' : 'resume') . ".\n");
        exit(1);
    }
    $providerIds   = array_keys($manifest->providers);
    $modelOverride = $manifest->models();
}

$models = [];
foreach ($providerIds as $pid) {
    $models[$pid] = ModelConfig::resolveModel($pid, $modelOverride[$pid] ?? '');
}

$batchMode = match (true) {
    $cancel   => 'batch cancel (from manifest)',
    $resume   => 'batch resume (from manifest)',
    $batch    => 'batch (async, ~50% cost)',
    $parallel => 'parallel (concurrent, full cost, x' . $concurrency . ')',
    default   => 'live (per-SKU calls)',
};
echo 'Account   : ' . $account . PHP_EOL;
echo 'Mode      : ' . $batchMode . PHP_EOL;
foreach ($providerIds as $pid) {
    echo 'Provider  : ' . str_pad($pid, 9) . ' -> ' . $models[$pid] . PHP_EOL;
}
if ($dryRun) {
    echo '[DRY RUN — no API calls, no files written]' . PHP_EOL;
}
echo PHP_EOL;

// --- Build providers (skipped in dry-run) -----------------------------------
$generators     = []; // live: pid => ModularTitleGenerator
$batchProviders = []; // batch: pid => BatchProviderInterface
$parallelRunner = null; // parallel: shared ParallelRunner over all providers
if (!$dryRun) {
    $parallelKeys = []; // pid => api key (parallel mode)
    foreach ($providerIds as $pid) {
        $key = ModelConfig::apiKey($pid);
        if ($key === '') {
            $var = ModelConfig::API_KEY_ENV[$pid];
            fwrite(STDERR, "{$var} is not set. Add it to .env or export it (or narrow --provider).\n");
            exit(1);
        }
        if ($parallel) {
            $parallelKeys[$pid] = $key;
            continue;
        }
        if ($batch) {
            $batchProviders[$pid] = $pid === ModelConfig::ANTHROPIC
                ? AnthropicBatchProvider::fromApiKey($key, $models[$pid])
                : OpenAiBatchProvider::fromApiKey($key, $models[$pid]);
            continue;
        }
        $provider = $pid === ModelConfig::ANTHROPIC
            ? AnthropicProvider::fromApiKey($key, $models[$pid])
            : OpenAiProvider::fromApiKey($key, $models[$pid]);
        assert($provider instanceof ProviderInterface);
        $generators[$pid] = new ModularTitleGenerator($provider);
    }
    if ($parallel) {
        $parallelRunner = new ParallelRunner($parallelKeys, $concurrency);
    }
}

// --- Cancel: tear down the manifest's batches, then stop --------------------
if ($cancel) {
    assert($manifest !== null);
    echo 'Cancelling batches submitted ' . $manifest->createdAt . ' …' . PHP_EOL;
    foreach ($manifest->batchIds() as $pid => $batchId) {
        try {
            $batchProviders[$pid]->cancelBatch($batchId);
            echo '  cancelled ' . str_pad($pid, 9) . ' ' . $batchId . PHP_EOL;
        } catch (\Throwable $e) {
            echo '  [ERROR] ' . $pid . ': ' . $e->getMessage() . PHP_EOL;
        }
    }
    BatchManifest::delete($paths['data']);
    echo 'Manifest removed.' . PHP_EOL;
    exit(0);
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

// Batch runs bill at ~half rate; scale the cost figures to match.
$costEstimator = new CostEstimator($batch ? 0.5 : 1.0);

if ($multi) {
    // --- Multi-request path (batch or parallel): prepare per-provider requests
    //     (or reattach to a prior batch submission), dispatch, then assemble the
    //     per-SKU compare files. Prep and assembly are shared; only dispatch
    //     differs — an async batch window vs. an immediate concurrent pool. ------
    $prepared      = []; // sku => ['ctx'=>ProductContext,'asin'=>string,'product_type'=>string,...]
    $skuToCustomId = []; // sku => positional batch custom_id
    $results       = []; // pid => (custom_id => ProviderResult)
    $pending       = []; // provider ids whose batch is still running (resume only)

    if ($resume) {
        // Reattach to the manifest's batches: rebuild each SKU's context
        // (deterministic from the same snapshots), then take one non-blocking
        // status pass — collecting whichever providers have finished without
        // waiting on any that are still running.
        assert($manifest !== null);
        echo 'Reattaching to batches submitted ' . $manifest->createdAt . ' …' . PHP_EOL;
        foreach ($manifest->skuToCustomId as $sku => $customId) {
            $meta = $skus[$sku] ?? ['asin' => '', 'product_type' => ''];
            // Force past the "compare file exists" skip: the batch is already
            // paid for, so we always reassemble from its results.
            $p = generate_titles_prepare($sku, $meta, $paths, $comparePath, true);
            if ($p['skip'] !== null) {
                echo "[{$sku}]" . PHP_EOL . '  ' . $p['skip'] . PHP_EOL;
                $stats[$p['bucket']]++;
                continue;
            }
            $prepared[$sku]      = $p;
            $skuToCustomId[$sku] = $customId;
        }
        if ($prepared !== []) {
            echo PHP_EOL . 'Checking batch status…' . PHP_EOL;
            $poll    = (new BatchRunner($manifest->pollInterval))->poll($batchProviders, $manifest->batchIds());
            $results = $poll['results'];
            $pending = $poll['pending'];
        }
    } else {
        // Build one positional request per SKU per provider.
        $requestsByProvider = array_fill_keys($providerIds, []);
        foreach ($skus as $sku => $meta) {
            echo "[{$sku}]" . PHP_EOL;
            $p = generate_titles_prepare($sku, $meta, $paths, $comparePath, $force);
            if ($p['skip'] !== null) {
                echo '  ' . $p['skip'] . PHP_EOL;
                $stats[$p['bucket']]++;
                continue;
            }

            $preview        = ModularTitleGenerator::previewRequest($p['ctx']);
            $prepared[$sku] = $p;
            // SKUs can contain characters (dots, spaces) that Anthropic's custom_id
            // pattern '^[a-zA-Z0-9_-]{1,64}$' rejects, so key each request by a
            // positional id and map it back to the SKU when assembling results.
            $customId            = 'sku-' . count($prepared);
            $skuToCustomId[$sku] = $customId;
            foreach ($providerIds as $pid) {
                if ($dryRun) {
                    $costEstimator->add($models[$pid], CostEstimator::estimateTokens($preview['prompt']), $preview['maxOutputTokens']);
                    continue;
                }
                $requestsByProvider[$pid][] = [
                    'custom_id' => $customId,
                    'prompt'    => $preview['prompt'],
                    'maxTokens' => $preview['maxOutputTokens'],
                ];
            }
            echo $dryRun
                ? '  [DRY RUN] would dispatch ' . count($providerIds) . ' request(s)' . PHP_EOL
                : '  queued' . PHP_EOL;
        }

        if (!$dryRun && $prepared !== [] && $parallel) {
            // Parallel: fire every provider's request at once and demux the flat
            // custom_id => result map back into the pid => custom_id shape the
            // shared assembly expects.
            echo PHP_EOL . 'Dispatching ' . array_sum(array_map('count', $requestsByProvider))
                . ' request(s) concurrently (up to ' . $concurrency . ' in flight)…' . PHP_EOL;
            assert($parallelRunner !== null);
            $jobs = [];
            foreach ($requestsByProvider as $pid => $reqs) {
                foreach ($reqs as $req) {
                    $jobs[] = new ParallelJob($pid . '|' . $req['custom_id'], $pid, $models[$pid], $req['prompt'], $req['maxTokens']);
                }
            }
            $flat = $parallelRunner->run($jobs);
            foreach ($requestsByProvider as $pid => $reqs) {
                foreach ($reqs as $req) {
                    $results[$pid][$req['custom_id']] = $flat[$pid . '|' . $req['custom_id']] ?? null;
                }
            }
        } elseif (!$dryRun && $prepared !== []) {
            echo PHP_EOL . 'Submitting batches (polling every ' . $pollInterval . 's until complete)…' . PHP_EOL;
            // Persist the batch ids the instant they exist so an interrupted run
            // can be resumed (--resume) or torn down (--cancel) instead of
            // orphaning batches that keep billing server-side.
            $persist = static function (array $batchIds) use ($account, $pollInterval, $models, $skuToCustomId, $paths): void {
                $providers = [];
                foreach ($batchIds as $pid => $batchId) {
                    $providers[$pid] = ['batch_id' => $batchId, 'model' => $models[$pid]];
                }
                (new BatchManifest($account, date('c'), $pollInterval, $providers, $skuToCustomId))->save($paths['data']);
                echo '  manifest : ' . BatchManifest::path($paths['data']) . ' (--resume to reattach, --cancel to abort)' . PHP_EOL;
            };
            $results = (new BatchRunner($pollInterval))->run($batchProviders, $requestsByProvider, $persist);
        }
    }

    // --- Shared assembly: fold each provider's result into its compare file ---
    if (!$dryRun && $results !== []) {
        echo PHP_EOL . 'Assembling compare files…' . PHP_EOL;
        foreach ($prepared as $sku => $p) {
            $ctx     = $p['ctx'];
            $compare = generate_titles_compare_base(
                $sku,
                $p['asin'],
                $p['product_type'],
                $account,
                $providerIds,
                ModularTitleGenerator::previewRequest($ctx)['prompt'],
            );

            $customId = $skuToCustomId[$sku];
            foreach ($providerIds as $pid) {
                // A provider whose batch is still running is left unfilled here
                // (no error stamped): its slot fills on a later --resume once the
                // batch ends. The manifest is kept below so that stays possible.
                if (in_array($pid, $pending, true)) {
                    continue;
                }
                $result = $results[$pid][$customId] ?? null;
                if ($result === null) {
                    $err = ['provider' => $pid, 'model' => $models[$pid], 'error' => 'no result returned'];
                    $compare['item_name'][$pid]             = $err;
                    $compare['title_differentiation'][$pid] = $err;
                    echo '  [' . $sku . '] [ERROR] ' . $pid . ': no result returned' . PHP_EOL;
                    $stats['errors']++;
                    continue;
                }

                $stats['api_calls']++;
                $costEstimator->add(
                    $models[$pid],
                    (int) ($result->usage['input'] ?? 0),
                    (int) ($result->usage['output'] ?? 0),
                );

                $out = [
                    'item_name'             => ItemNameGenerator::generate($result, $ctx),
                    'title_differentiation' => TitleDifferentiationGenerator::generate($result, $ctx),
                    'raw'                   => $result->rawText,
                ];
                echo '  [' . $sku . '] ' . generate_titles_fold($compare, $pid, $out) . PHP_EOL;
            }

            file_put_contents($comparePath . '/' . $sku . '.json', json_encode($compare, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $stats['written']++;
        }
    }

    // Manifest lifecycle (batch only — parallel has no manifest): drop the handle
    // once every provider is terminal and drained. If any provider is still
    // running, keep it so a later --resume can collect that provider's batch
    // instead of orphaning it server-side.
    if (!$dryRun && $batch && $prepared !== []) {
        if ($pending === []) {
            BatchManifest::delete($paths['data']);
        } else {
            echo PHP_EOL . '[NOTE] provider(s) still running: ' . implode(', ', $pending)
                . '. Manifest kept — re-run with --resume later to collect them.' . PHP_EOL;
        }
    }
} else {
    // --- Live path: one call per provider per SKU, in order ------------------
    foreach ($skus as $sku => $meta) {
        echo "[{$sku}]" . PHP_EOL;
        $p = generate_titles_prepare($sku, $meta, $paths, $comparePath, $force);
        if ($p['skip'] !== null) {
            echo '  ' . $p['skip'] . PHP_EOL;
            $stats[$p['bucket']]++;
            continue;
        }
        $ctx = $p['ctx'];

        if ($dryRun) {
            $preview = ModularTitleGenerator::previewRequest($ctx);
            $inToks  = CostEstimator::estimateTokens($preview['prompt']);
            foreach ($providerIds as $pid) {
                $costEstimator->add($models[$pid], $inToks, $preview['maxOutputTokens']);
                echo '  [DRY RUN] would call ' . $pid . ' (' . $models[$pid] . ') for item_name + title_differentiation' . PHP_EOL;
            }
            continue;
        }

        $compare = generate_titles_compare_base(
            $sku,
            $p['asin'],
            $p['product_type'],
            $account,
            $providerIds,
            ModularTitleGenerator::previewRequest($ctx)['prompt'],
        );

        foreach ($providerIds as $pid) {
            try {
                $out = $generators[$pid]->generate($ctx);
                $stats['api_calls']++;
                $costEstimator->add(
                    $models[$pid],
                    (int) ($out['usage']['input'] ?? 0),
                    (int) ($out['usage']['output'] ?? 0),
                );
                echo '  ' . generate_titles_fold($compare, $pid, $out) . PHP_EOL;
            } catch (\Throwable $e) {
                $err = ['provider' => $pid, 'model' => $models[$pid], 'error' => $e->getMessage()];
                $compare['item_name'][$pid]             = $err;
                $compare['title_differentiation'][$pid] = $err;
                echo '  [ERROR] ' . $pid . ': ' . $e->getMessage() . PHP_EOL;
                $stats['errors']++;
            }
        }

        file_put_contents($comparePath . '/' . $sku . '.json', json_encode($compare, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stats['written']++;
    }
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
 * Load one SKU's snapshots and build its ProductContext, applying the shared skip
 * rules (no listing, no schema, no context, or an existing compare file). Used by
 * both the live loop and the batch request builder so both make identical
 * per-SKU decisions.
 *
 * @param  array{asin:string,product_type:string} $meta
 * @param  array<string,string>                   $paths  amazon_paths() result
 * @return array{ctx:?ProductContext,asin:string,product_type:string,skip:?string,bucket:?string}
 *         skip/bucket are set (console message + $stats key) when the SKU is skipped.
 */
function generate_titles_prepare(string $sku, array $meta, array $paths, string $comparePath, bool $force): array
{
    $miss = static fn (string $msg, string $bucket): array => [
        'ctx' => null, 'asin' => '', 'product_type' => '', 'skip' => $msg, 'bucket' => $bucket,
    ];

    $listingFile = $paths['listings'] . '/' . $sku . '.json';
    if (!file_exists($listingFile)) {
        return $miss('[WARN] no listing snapshot — skipped', 'skipped');
    }
    $listing = json_decode((string) file_get_contents($listingFile), true) ?? [];

    $asin        = $meta['asin'] !== '' ? $meta['asin'] : (string) ($listing['summaries'][0]['asin'] ?? '');
    $productType = $meta['product_type'] !== '' ? $meta['product_type'] : ProductContext::resolveProductType($listing);

    $schemaFile = $paths['schemas'] . '/' . $productType . '.json';
    if ($productType === '' || !file_exists($schemaFile)) {
        return $miss("[WARN] no schema for product type '{$productType}' — skipped", 'skipped');
    }
    $schema = json_decode((string) file_get_contents($schemaFile), true) ?? [];

    $catalogFile  = $paths['catalog'] . '/' . $asin . '.json';
    $catalogAttrs = file_exists($catalogFile)
        ? (json_decode((string) file_get_contents($catalogFile), true)['attributes'] ?? [])
        : [];

    $ctx = ProductContext::fromSnapshots($listing, $catalogAttrs, $schema);
    if (!$ctx->hasContext()) {
        return $miss('no product context (title/description/features all empty) — skipped', 'no_context');
    }

    if (file_exists($comparePath . '/' . $sku . '.json') && !$force) {
        return $miss('compare file exists — skipped (use --force)', 'skipped');
    }

    return ['ctx' => $ctx, 'asin' => $asin, 'product_type' => $productType, 'skip' => null, 'bucket' => null];
}

/**
 * The base compare-file structure both paths fill in per provider. The prompt is
 * provider-agnostic (both providers receive the identical string), so it is
 * recorded once for debugging.
 *
 * @param  list<string> $providerIds
 * @return array<string,mixed>
 */
function generate_titles_compare_base(string $sku, string $asin, string $productType, string $account, array $providerIds, string $prompt): array
{
    return [
        'sku'                   => $sku,
        'asin'                  => $asin,
        'product_type'          => $productType,
        'account'               => $account,
        'generated_at'          => gmdate('c'),
        'providers'             => $providerIds,
        'prompt'                => $prompt,
        'item_name'             => [],
        'title_differentiation' => [],
    ];
}

/**
 * Fold one provider's modular-title output (ModularTitleGenerator::generate()'s
 * shape: item_name, title_differentiation, raw) into $compare and return the
 * console badge line. Shared by the live and batch paths so both write identical
 * compare files.
 *
 * @param array<string,mixed> $compare
 * @param array<string,mixed> $out
 */
function generate_titles_fold(array &$compare, string $pid, array $out): string
{
    $in = $out['item_name'];
    $td = $out['title_differentiation'];

    $compare['item_name'][$pid]             = $in;
    $compare['title_differentiation'][$pid] = $td;

    if (($in['item_name'] ?? null) === null || ($td['title_differentiation'] ?? null) === null) {
        $compare['raw'][$pid] = $out['raw'] ?? '';
    }

    return str_pad($pid, 9)
        . ' item_name=' . generate_titles_badge($in['char_count'] ?? null, $in['validation_error'] ?? null)
        . '  title_differentiation=' . generate_titles_badge($td['char_count'] ?? null, $td['validation_error'] ?? null);
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
