<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * Provider identifiers, model registries, and API-key resolution for the AI
 * title / highlight generators.
 *
 * MODELS lists every model tier each provider exposes, keyed by a short alias so
 * a run can pass --anthropic-model=opus or the full claude-opus-4-8. Any full ID
 * a provider ships is accepted at runtime (resolve() passes unknown strings
 * through unchanged); the registry drives --help output and alias expansion, it
 * is not an allowlist. Bump a default or add a tier here in one place.
 *
 * Anthropic IDs mirror draft_listings.php's MODEL_* constants. The sonnet default
 * suits the richer combined title prompt (token derivation + search terms); pass
 * --anthropic-model=opus/haiku to trade quality for cost either way.
 *
 * The OpenAI gpt-5.x tier are reasoning models: they reject max_tokens (require
 * max_completion_tokens, which also funds hidden reasoning tokens). OpenAiProvider
 * branches on isReasoningModel() so those aliases work; gpt-4o stays the default
 * because this bounded JSON extraction does not need reasoning and gpt-4o is the
 * cheapest predictable fit. Pass --openai-model=gpt-5.4-mini to opt in.
 */
final class ModelConfig
{
    public const ANTHROPIC = 'anthropic';
    public const OPENAI    = 'openai';

    /** Providers the compare workflow runs, in display order. */
    public const PROVIDERS = [self::ANTHROPIC, self::OPENAI];

    /** alias => full model ID, per provider. */
    public const MODELS = [
        self::ANTHROPIC => [
            'haiku'  => 'claude-haiku-4-5',
            'sonnet' => 'claude-sonnet-5',
            'opus'   => 'claude-opus-4-8',
        ],
        self::OPENAI => [
            'gpt-4o'       => 'gpt-4o',
            'gpt-4o-mini'  => 'gpt-4o-mini',
            'gpt-4.1'      => 'gpt-4.1',
            'gpt-4.1-mini' => 'gpt-4.1-mini',
            'gpt-5.4'      => 'gpt-5.4',
            'gpt-5.4-mini' => 'gpt-5.4-mini',
            'gpt-5.4-nano' => 'gpt-5.4-nano',
            'gpt-5.4-pro'  => 'gpt-5.4-pro',
        ],
    ];

    /** Default model per provider (a value from MODELS). */
    public const DEFAULTS = [
        self::ANTHROPIC => self::MODELS[self::ANTHROPIC]['sonnet'],
        self::OPENAI    => self::MODELS[self::OPENAI]['gpt-4o'],
    ];

    /** Env var holding each provider's API key. */
    public const API_KEY_ENV = [
        self::ANTHROPIC => 'ANTHROPIC_API_KEY',
        self::OPENAI    => 'OPENAI_API_KEY',
    ];

    public static function defaultModel(string $provider): string
    {
        return self::DEFAULTS[self::assertProvider($provider)];
    }

    /**
     * True for OpenAI gpt-5.x / o-series reasoning models, which require
     * max_completion_tokens instead of max_tokens on Chat Completions.
     */
    public static function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(gpt-5|o[13])/', trim($model));
    }

    /**
     * Expand a short alias (haiku/opus/gpt-4.1) to its full model ID, or pass a
     * full ID through unchanged so future models work without a code change.
     */
    public static function resolveModel(string $provider, string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            return self::defaultModel($provider);
        }
        return self::MODELS[self::assertProvider($provider)][$model] ?? $model;
    }

    /** @return list<string> full model IDs offered for a provider (for --help). */
    public static function knownModels(string $provider): array
    {
        return array_values(self::MODELS[self::assertProvider($provider)]);
    }

    /**
     * Provider's API key from the environment ($_ENV then getenv), mirroring
     * draft_listings.php. Returns '' when unset so callers can emit a clear error.
     */
    public static function apiKey(string $provider): string
    {
        $var = self::API_KEY_ENV[self::assertProvider($provider)];
        return (string) ($_ENV[$var] ?? getenv($var) ?: '');
    }

    private static function assertProvider(string $provider): string
    {
        if (!isset(self::MODELS[$provider])) {
            throw new \InvalidArgumentException("Unknown provider: {$provider}");
        }
        return $provider;
    }
}
