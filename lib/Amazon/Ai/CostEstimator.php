<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * Accumulates projected input/output token counts per model over a dry run and
 * renders the "Estimated cost" summary both draft_listings.php and
 * generate_titles.php print. Rates come from ModelPricing; token counts are the
 * caller's own heuristics (real prompt length for input, per-task caps or
 * schema-derived estimates for output), so the dollar figures are rough.
 */
final class CostEstimator
{
    /** @var array<string,array{in:int,out:int}> full model ID => running totals. */
    private array $totals = [];

    /**
     * @param float $costMultiplier scales every dollar figure (token counts stay
     *                              actual). Pass 0.5 for the Batch API's discount.
     */
    public function __construct(
        private float $costMultiplier = 1.0,
    ) {
    }

    /**
     * Rough token count for a piece of prompt text (~4 chars/token), the same
     * heuristic the scripts used inline before this class existed.
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    public function add(string $model, int $inputTokens, int $outputTokens): void
    {
        $this->totals[$model] ??= ['in' => 0, 'out' => 0];
        $this->totals[$model]['in']  += $inputTokens;
        $this->totals[$model]['out'] += $outputTokens;
    }

    public function isEmpty(): bool
    {
        foreach ($this->totals as $t) {
            if ($t['in'] !== 0 || $t['out'] !== 0) {
                return false;
            }
        }
        return true;
    }

    /** Summed dollar cost across every priced model (unpriced models add $0). */
    public function total(): float
    {
        $total = 0.0;
        foreach ($this->totals as $model => $t) {
            $total += ModelPricing::cost($model, $t['in'], $t['out']) ?? 0.0;
        }
        return $total * $this->costMultiplier;
    }

    /**
     * Multi-line "Estimated cost" block (one row per model actually used, then a
     * TOTAL), or '' when nothing was accumulated. Includes a trailing newline so
     * callers can echo it directly.
     */
    public function report(string $heading = 'Estimated cost (rough — heuristic output tokens):'): string
    {
        $rows = array_filter($this->totals, static fn ($t): bool => $t['in'] !== 0 || $t['out'] !== 0);
        if (!$rows) {
            return '';
        }

        $width = max(8, ...array_map(static fn (string $m): int => strlen(self::label($m)), array_keys($rows)));

        $lines = [$heading];
        foreach ($rows as $model => $t) {
            $cost     = ModelPricing::cost($model, $t['in'], $t['out']);
            $cost     = $cost === null ? null : $cost * $this->costMultiplier;
            $costText = $cost === null ? 'n/a (no rate)' : sprintf('$%.4f', $cost);
            $lines[]  = sprintf(
                '  %-' . $width . 's  in~%-11s out~%-11s %s',
                self::label($model),
                number_format($t['in']),
                number_format($t['out']),
                $costText,
            );
        }
        $lines[] = sprintf('  %-' . $width . 's  %-28s $%.4f', 'TOTAL', '', $this->total());

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** Drop the redundant claude- prefix so rows read haiku/opus, not the full ID. */
    private static function label(string $model): string
    {
        return str_starts_with($model, 'claude-') ? substr($model, 7) : $model;
    }
}
