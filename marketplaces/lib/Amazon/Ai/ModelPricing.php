<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * Published API list prices ($ per 1M tokens) for every model the drafting and
 * title tools can call, across both providers. One source of truth so dry-run
 * cost projections (see CostEstimator) agree no matter which script runs.
 *
 * Anthropic rows carry over the rates draft_listings.php previously hard-coded in
 * its MODEL_RATES constant; the OpenAI rows were added when the compare workflow
 * gained an OpenAI provider (the old table predated it). Rates move — treat these
 * as estimates and revisit when providers reprice or a model is added to
 * ModelConfig::MODELS.
 */
final class ModelPricing
{
    /** full model ID => ['in' => $/1M input, 'out' => $/1M output]. */
    public const RATES = [
        // Anthropic
        'claude-haiku-4-5'  => ['in' => 1.0,  'out' => 5.0],
        'claude-sonnet-4-6' => ['in' => 3.0,  'out' => 15.0],
        'claude-sonnet-5'   => ['in' => 3.0,  'out' => 15.0],
        'claude-opus-4-8'   => ['in' => 5.0,  'out' => 25.0],

        // OpenAI
        'gpt-4o'            => ['in' => 2.5,  'out' => 10.0],
        'gpt-4o-mini'       => ['in' => 0.15, 'out' => 0.60],
        'gpt-4.1'           => ['in' => 2.0,  'out' => 8.0],
        'gpt-4.1-mini'      => ['in' => 0.40, 'out' => 1.60],
        'gpt-5.4'           => ['in' => 2.5,  'out' => 15.0],
        'gpt-5.4-mini'      => ['in' => 0.75, 'out' => 4.50],
        'gpt-5.4-nano'      => ['in' => 0.20, 'out' => 1.25],
        'gpt-5.4-pro'       => ['in' => 30.0, 'out' => 180.0],
    ];

    /**
     * Rate row for a model, or null when the model isn't priced here (callers
     * surface that rather than silently charging $0).
     *
     * @return array{in:float,out:float}|null
     */
    public static function rate(string $model): ?array
    {
        return self::RATES[$model] ?? null;
    }

    public static function isKnown(string $model): bool
    {
        return isset(self::RATES[$model]);
    }

    /**
     * Dollar cost of a call at the model's rate, or null when the model has no
     * published rate in the table.
     */
    public static function cost(string $model, int $inputTokens, int $outputTokens): ?float
    {
        $rate = self::rate($model);
        if ($rate === null) {
            return null;
        }
        return $inputTokens / 1e6 * $rate['in'] + $outputTokens / 1e6 * $rate['out'];
    }
}
