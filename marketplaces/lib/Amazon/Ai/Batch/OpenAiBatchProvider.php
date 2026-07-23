<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Batch;

use Ige\Amazon\Ai\ModelConfig;
use Ige\Amazon\Ai\Provider\OpenAiProvider;
use Ige\Amazon\Ai\Provider\ParsesProviderJson;
use Ige\Amazon\Ai\ProviderResult;
use OpenAI;
use OpenAI\Contracts\ClientContract;

/**
 * OpenAI Batch API provider (openai-php/client).
 *
 * OpenAI batches are file-based: this writes a JSONL request file (one Chat
 * Completions call per line, bodies built by OpenAiProvider::requestBody so they
 * match the synchronous path), uploads it, creates the batch, polls to
 * completion, then downloads and parses the output JSONL into ProviderResults
 * keyed by the SKU custom_id. Per-request failures are read from the batch's
 * error file and surfaced as null-decoded results.
 */
final class OpenAiBatchProvider implements BatchProviderInterface
{
    use ParsesProviderJson;

    private const ENDPOINT          = '/v1/chat/completions';
    private const COMPLETION_WINDOW = '24h';

    public function __construct(
        private ClientContract $client,
        private string $model,
    ) {
    }

    public static function fromApiKey(string $apiKey, string $model): self
    {
        return new self(OpenAI::client($apiKey), $model);
    }

    public function name(): string
    {
        return ModelConfig::OPENAI;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function submitBatch(array $requests): string
    {
        $jsonlPath = (string) tempnam(sys_get_temp_dir(), 'oai_batch_');
        $fh        = fopen($jsonlPath, 'w');
        foreach ($requests as $r) {
            fwrite($fh, json_encode([
                'custom_id' => $r['custom_id'],
                'method'    => 'POST',
                'url'       => self::ENDPOINT,
                'body'      => OpenAiProvider::requestBody($this->model, $r['prompt'], ['maxTokens' => $r['maxTokens']]),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
        fclose($fh);

        try {
            $file  = $this->client->files()->upload(['purpose' => 'batch', 'file' => fopen($jsonlPath, 'r')]);
            $batch = $this->client->batches()->create([
                'input_file_id'     => $file->id,
                'endpoint'          => self::ENDPOINT,
                'completion_window' => self::COMPLETION_WINDOW,
            ]);
        } finally {
            @unlink($jsonlPath);
        }

        return $batch->id;
    }

    public function pollStatus(string $batchId): BatchStatus
    {
        $batch  = $this->client->batches()->retrieve($batchId);
        $counts = $batch->requestCounts;

        $terminal = ['completed', 'failed', 'expired', 'cancelled'];

        return new BatchStatus(
            ended: in_array($batch->status, $terminal, true),
            failed: in_array($batch->status, ['failed', 'expired', 'cancelled'], true),
            rawStatus: $batch->status,
            processing: $counts !== null ? max(0, $counts->total - $counts->completed - $counts->failed) : 0,
            succeeded: $counts->completed ?? 0,
            errored: $counts->failed ?? 0,
        );
    }

    public function cancelBatch(string $batchId): void
    {
        $this->client->batches()->cancel($batchId);
    }

    public function fetchResults(string $batchId): iterable
    {
        $batch = $this->client->batches()->retrieve($batchId);

        if ($batch->outputFileId !== null) {
            yield from $this->parseOutputFile($batch->outputFileId);
        }

        // Requests that never produced a completion land in the error file; emit
        // them as null-decoded results so the generators flag them per SKU.
        if ($batch->errorFileId !== null) {
            foreach ($this->jsonlLines($batch->errorFileId) as $line) {
                $customId = (string) ($line['custom_id'] ?? '');
                if ($customId !== '') {
                    yield $customId => new ProviderResult($this->name(), $this->model, '', null);
                }
            }
        }
    }

    /** @return iterable<string,ProviderResult> */
    private function parseOutputFile(string $fileId): iterable
    {
        foreach ($this->jsonlLines($fileId) as $line) {
            $customId = (string) ($line['custom_id'] ?? '');
            if ($customId === '') {
                continue;
            }

            $body = $line['response']['body'] ?? null;
            $text = is_array($body) ? (string) ($body['choices'][0]['message']['content'] ?? '') : '';

            [$raw, $decoded] = $this->parseJson($text);

            $usage = array_filter([
                'input'  => is_array($body) ? ($body['usage']['prompt_tokens'] ?? null) : null,
                'output' => is_array($body) ? ($body['usage']['completion_tokens'] ?? null) : null,
            ], static fn ($v): bool => $v !== null);

            yield $customId => new ProviderResult($this->name(), $this->model, $raw, $decoded, $usage);
        }
    }

    /**
     * Download an OpenAI batch file and decode it line by line.
     *
     * @return iterable<int,array<string,mixed>>
     */
    private function jsonlLines(string $fileId): iterable
    {
        $content = $this->client->files()->download($fileId);
        foreach (explode("\n", trim($content)) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                yield $decoded;
            }
        }
    }
}
