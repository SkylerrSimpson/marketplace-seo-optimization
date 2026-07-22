<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Provider;

use Anthropic\Client;
use Ige\Amazon\Ai\ModelConfig;
use Ige\Amazon\Ai\ProviderResult;

/**
 * Anthropic Messages API provider (anthropic-ai/sdk).
 *
 * Construct with an existing Anthropic\Client (draft_listings.php already builds
 * one) or via fromApiKey() for the standalone tool.
 */
final class AnthropicProvider extends AbstractProvider
{
    public function __construct(
        private Client $client,
        string $model,
    ) {
        parent::__construct(ModelConfig::ANTHROPIC, $model);
    }

    public static function fromApiKey(string $apiKey, string $model): self
    {
        return new self(new Client(apiKey: $apiKey), $model);
    }

    public function complete(string $prompt, array $opts = []): ProviderResult
    {
        $message = $this->client->messages->create(
            model: $this->model(),
            maxTokens: $opts['maxTokens'] ?? 256,
            messages: [['role' => 'user', 'content' => $prompt]],
        );

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

        return new ProviderResult($this->name(), $this->model(), $raw, $decoded, $usage);
    }
}
