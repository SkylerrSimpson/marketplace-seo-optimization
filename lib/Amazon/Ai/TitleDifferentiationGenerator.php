<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * Extract and validate title_differentiation (item highlight) from a
 * modular-title response. Parses its attribute off the shared ProviderResult
 * produced by ModularTitleGenerator's single call.
 *
 * char_count is recomputed here; validation_error is set when the phrase is
 * missing or over the schema maxLength.
 */
final class TitleDifferentiationGenerator
{
    /** @return array<string,mixed> */
    public static function generate(ProviderResult $result, ProductContext $ctx): array
    {
        $decoded = $result->decoded;

        $phrase = null;
        if (is_array($decoded)) {
            $value  = $decoded['title_differentiation'] ?? null;
            $phrase = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        $charCount = $phrase !== null ? mb_strlen($phrase) : null;

        $error = null;
        if ($phrase === null) {
            $error = 'no title_differentiation returned';
        } elseif ($charCount > $ctx->titleDiffMaxLen) {
            $error = "value exceeds maxLength {$ctx->titleDiffMaxLen} ({$charCount} chars)";
        }

        return [
            'provider'              => $result->provider,
            'model'                 => $result->model,
            'title_differentiation' => $phrase,
            'char_count'            => $charCount,
            'validation_error'      => $error,
        ];
    }
}
