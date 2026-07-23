<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Batch;

use Anthropic\Client;
use Anthropic\Messages\Batches\BatchCreateParams\Request;
use Anthropic\Messages\Batches\BatchCreateParams\Request\Params;
use Anthropic\Messages\ThinkingConfigDisabled;
use Ige\Amazon\Ai\ModelConfig;
use Ige\Amazon\Ai\Provider\ParsesProviderJson;
use Ige\Amazon\Ai\ProviderResult;

/**
 * Anthropic Message Batches provider (anthropic-ai/sdk).
 *
 * Submits every prompt as one inline batch ($client->messages->batches->create),
 * polls the batch to completion, then streams the typed per-request results back
 * as ProviderResults keyed by the SKU custom_id. The per-request message params
 * mirror the synchronous AnthropicProvider so batched and live calls behave the
 * same; only the transport (async, half price) differs.
 *
 * Extended thinking is disabled on every request: this is a bounded JSON task,
 * and a thinking model would otherwise spend the small max_tokens budget on a
 * thinking block and return no text.
 */
final class AnthropicBatchProvider implements BatchProviderInterface
{
    use ParsesProviderJson;

    public function __construct(
        private Client $client,
        private string $model,
    ) {
    }

    public static function fromApiKey(string $apiKey, string $model): self
    {
        return new self(new Client(apiKey: $apiKey), $model);
    }

    public function name(): string
    {
        return ModelConfig::ANTHROPIC;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** Anthropic's required custom_id shape; anything else 400s the whole batch. */
    private const CUSTOM_ID_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

    public function submitBatch(array $requests): string
    {
        $items = [];
        foreach ($requests as $r) {
            if (!preg_match(self::CUSTOM_ID_PATTERN, $r['custom_id'])) {
                throw new \InvalidArgumentException(sprintf(
                    "custom_id '%s' is not batch-safe: Anthropic requires %s. "
                    . 'Map SKUs to positional ids before submitting.',
                    $r['custom_id'],
                    self::CUSTOM_ID_PATTERN,
                ));
            }
            $items[] = Request::with(
                customID: $r['custom_id'],
                params: Params::with(
                    maxTokens: $r['maxTokens'],
                    messages: [['role' => 'user', 'content' => $r['prompt']]],
                    model: $this->model,
                    thinking: ThinkingConfigDisabled::with(),
                ),
            );
        }

        return $this->client->messages->batches->create($items)->id;
    }

    public function pollStatus(string $batchId): BatchStatus
    {
        $batch  = $this->client->messages->batches->retrieve($batchId);
        $status = $batch->processingStatus;
        $counts = $batch->requestCounts;

        return new BatchStatus(
            ended: $status === 'ended',
            failed: false, // Anthropic batches end normally; per-request errors surface in results.
            rawStatus: $status,
            processing: $counts->processing,
            succeeded: $counts->succeeded,
            errored: $counts->errored + $counts->canceled + $counts->expired,
        );
    }

    public function cancelBatch(string $batchId): void
    {
        $this->client->messages->batches->cancel($batchId);
    }

    public function fetchResults(string $batchId): iterable
    {
        foreach ($this->client->messages->batches->resultsStream($batchId) as $item) {
            $customId = $item->customID;

            if ($item->result->type !== 'succeeded') {
                yield $customId => new ProviderResult($this->name(), $this->model, '', null);
                continue;
            }

            $message = $item->result->message;

            $text = '';
            foreach ($message->content as $block) {
                if (($block->type ?? '') === 'text') {
                    $text = (string) $block->text;
                    break;
                }
            }

            [$raw, $decoded] = $this->parseJson($text);

            $usage = array_filter([
                'input'  => $message->usage->inputTokens ?? null,
                'output' => $message->usage->outputTokens ?? null,
            ], static fn ($v): bool => $v !== null);

            yield $customId => new ProviderResult($this->name(), $this->model, $raw, $decoded, $usage);
        }
    }
}
