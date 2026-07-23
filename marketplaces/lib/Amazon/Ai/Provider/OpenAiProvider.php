<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Provider;

use Ige\Amazon\Ai\ModelConfig;
use Ige\Amazon\Ai\ProviderResult;
use OpenAI;
use OpenAI\Contracts\ClientContract;

/**
 * OpenAI Chat Completions provider (openai-php/client).
 *
 * Uses response_format json_object so the model returns a clean JSON object;
 * the prompt must mention JSON (the builders do) for that mode to be accepted.
 *
 * gpt-5.x reasoning models reject max_tokens and spend part of their budget on
 * hidden reasoning tokens, so requestParams() sends max_completion_tokens with
 * headroom above the caller's visible cap and a low reasoning_effort to keep this
 * bounded JSON task fast. gpt-4o/4.1 keep the plain max_tokens shape.
 */
final class OpenAiProvider extends AbstractProvider
{
    public function __construct(
        private ClientContract $client,
        string $model,
    ) {
        parent::__construct(ModelConfig::OPENAI, $model);
    }

    public static function fromApiKey(string $apiKey, string $model): self
    {
        return new self(OpenAI::client($apiKey), $model);
    }

    public function complete(string $prompt, array $opts = []): ProviderResult
    {
        $response = $this->client->chat()->create(self::requestBody($this->model(), $prompt, $opts));

        $text = (string) ($response->choices[0]->message->content ?? '');

        [$raw, $decoded] = $this->parseJson($text);

        $usage = array_filter([
            'input'  => $response->usage->promptTokens ?? null,
            'output' => $response->usage->completionTokens ?? null,
        ], static fn ($v): bool => $v !== null);

        return new ProviderResult($this->name(), $this->model(), $raw, $decoded, $usage);
    }

    /**
     * Build the Chat Completions payload, adapting the token-limit parameter to
     * the model family. Reasoning models get max_completion_tokens (with headroom
     * for reasoning tokens) plus a low reasoning_effort; others get max_tokens.
     *
     * Static and self-contained so the batch path (OpenAiBatchProvider) can emit
     * JSONL request bodies byte-identical to the synchronous call.
     *
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    public static function requestBody(string $model, string $prompt, array $opts = []): array
    {
        $visibleCap = (int) ($opts['maxTokens'] ?? 256);

        $params = [
            'model'           => $model,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [['role' => 'user', 'content' => $prompt]],
        ];

        if (ModelConfig::isReasoningModel($model)) {
            $params['max_completion_tokens'] = $visibleCap + 1024;
            $params['reasoning_effort']      = $opts['reasoningEffort'] ?? 'low';

            return $params;
        }

        $params['max_tokens'] = $visibleCap;

        return $params;
    }
}
