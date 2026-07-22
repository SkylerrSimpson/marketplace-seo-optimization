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
            $value = $decoded['title_differentiation'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $normalized = self::normalize($value);
                $phrase     = $normalized !== '' ? $normalized : null;
            }
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

    /**
     * Enforce the house format on a raw item-highlight phrase, independent of the
     * provider that produced it:
     *
     *   1. Title-case — capitalize the first letter of every space-separated word
     *      while leaving the rest untouched, so acronyms like "US" survive
     *      ("natural wood grain" -> "Natural Wood Grain").
     *   2. Strip disallowed special characters, keeping only letters, numbers,
     *      whitespace, commas, and the allowed exceptions: hyphen, double quote,
     *      and apostrophe/single quote (straight or curly).
     *
     * The prompt is responsible for turning "&"/"=" into the right word for the
     * context; anything that still slips through is simply dropped here.
     */
    private static function normalize(string $phrase): string
    {
        $allowed = '\p{L}\p{N}\s,\-"\'\x{2018}\x{2019}\x{201C}\x{201D}';
        $phrase  = (string) preg_replace('/[^' . $allowed . ']+/u', '', $phrase);

        $phrase = (string) preg_replace('/\s+,/u', ',', $phrase);
        $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));

        $words = array_map(static function (string $word): string {
            return (string) preg_replace_callback(
                '/\p{L}/u',
                static fn (array $m): string => mb_strtoupper($m[0]),
                $word,
                1,
            );
        }, explode(' ', $phrase));

        return implode(' ', $words);
    }
}
