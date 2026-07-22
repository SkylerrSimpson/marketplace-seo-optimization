<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Concurrent;

use GuzzleHttp\Psr7\Request;
use Ige\Amazon\Ai\ModelConfig;
use Ige\Amazon\Ai\Provider\ParsesProviderJson;
use Ige\Amazon\Ai\ProviderResult;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Anthropic Messages API over raw HTTP for the concurrent pool.
 *
 * Mirrors the Messages endpoint and the {model, max_tokens, messages} body with
 * the same text-block + usage extraction; only the transport differs (a PSR-7
 * request driven by GuzzleHttp\Pool instead of the SDK's blocking call).
 *
 * Extended thinking is disabled on every request, matching AnthropicBatchProvider:
 * these are bounded JSON tasks, and a thinking model (e.g. claude-sonnet-5, the
 * title default) would otherwise spend the small max_tokens budget on a thinking
 * block and return no text at all.
 */
final class AnthropicHttp implements ProviderHttp
{
    use ParsesProviderJson;

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION  = '2023-06-01';

    public function __construct(private string $apiKey)
    {
    }

    public function request(ParallelJob $job): RequestInterface
    {
        $body = json_encode([
            'model'      => $job->model,
            'max_tokens' => $job->maxTokens,
            'messages'   => [['role' => 'user', 'content' => $job->prompt]],
            'thinking'   => ['type' => 'disabled'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Request('POST', self::ENDPOINT, [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::VERSION,
            'content-type'      => 'application/json',
        ], (string) $body);
    }

    public function parse(ParallelJob $job, ResponseInterface $response): ProviderResult
    {
        $payload = json_decode((string) $response->getBody(), true);

        $text = '';
        foreach ($payload['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = (string) ($block['text'] ?? '');
                break;
            }
        }

        [$raw, $decoded] = $this->parseJson($text);

        $usage = array_filter([
            'input'  => $payload['usage']['input_tokens'] ?? null,
            'output' => $payload['usage']['output_tokens'] ?? null,
        ], static fn ($v): bool => $v !== null);

        return new ProviderResult(ModelConfig::ANTHROPIC, $job->model, $raw, $decoded, $usage);
    }
}
