<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Provider;

use Ige\Amazon\Ai\ProviderResult;

/**
 * One LLM provider that answers a JSON-returning prompt. Implementations own the
 * request, response extraction, markdown-fence stripping, and JSON decode so the
 * generators stay provider-agnostic.
 */
interface ProviderInterface
{
    /** Provider id (ModelConfig::ANTHROPIC / ::OPENAI). */
    public function name(): string;

    /** Model ID this instance calls. */
    public function model(): string;

    /**
     * Send $prompt and return the parsed result.
     *
     * @param array{maxTokens?:int} $opts
     * @throws \Throwable on transport/API failure (callers catch per SKU)
     */
    public function complete(string $prompt, array $opts = []): ProviderResult;
}
