<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Concurrent;

use GuzzleHttp\Psr7\Request;
use Ige\Amazon\Ai\ModelConfig;
use Ige\Amazon\Ai\Provider\OpenAiProvider;
use Ige\Amazon\Ai\Provider\ParsesProviderJson;
use Ige\Amazon\Ai\ProviderResult;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * OpenAI Chat Completions over raw HTTP for the concurrent pool.
 *
 * The request body is built by OpenAiProvider::requestBody() — the exact payload
 * the synchronous and batch paths send, including the reasoning-model
 * max_completion_tokens handling — so a completion is byte-identical however it
 * is dispatched. Only the transport (a PSR-7 request driven by GuzzleHttp\Pool)
 * differs from the SDK call.
 */
final class OpenAiHttp implements ProviderHttp
{
    use ParsesProviderJson;

    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(private string $apiKey)
    {
    }

    public function request(ParallelJob $job): RequestInterface
    {
        $body = json_encode(
            OpenAiProvider::requestBody($job->model, $job->prompt, ['maxTokens' => $job->maxTokens]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return new Request('POST', self::ENDPOINT, [
            'authorization' => 'Bearer ' . $this->apiKey,
            'content-type'  => 'application/json',
        ], (string) $body);
    }

    public function parse(ParallelJob $job, ResponseInterface $response): ProviderResult
    {
        $payload = json_decode((string) $response->getBody(), true);

        $text = (string) ($payload['choices'][0]['message']['content'] ?? '');

        [$raw, $decoded] = $this->parseJson($text);

        $usage = array_filter([
            'input'  => $payload['usage']['prompt_tokens'] ?? null,
            'output' => $payload['usage']['completion_tokens'] ?? null,
        ], static fn ($v): bool => $v !== null);

        return new ProviderResult(ModelConfig::OPENAI, $job->model, $raw, $decoded, $usage);
    }
}
