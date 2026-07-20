<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * Extract and validate item_name (+ parsed tokens) from a modular-title
 * response. The provider call is made once by ModularTitleGenerator; this class
 * only parses/validates its attribute off the shared ProviderResult, so both
 * attributes come from a single request.
 *
 * char_count is recomputed here (authoritative) rather than trusted from the
 * model. validation_error is set when the title is missing or over the cap.
 */
final class ItemNameGenerator
{
    /** @return array<string,mixed> */
    public static function generate(ProviderResult $result, ProductContext $ctx): array
    {
        $decoded = $result->decoded;

        $itemName = null;
        $tokens   = null;
        if (is_array($decoded)) {
            $value    = $decoded['item_name'] ?? null;
            $itemName = is_string($value) && trim($value) !== '' ? trim($value) : null;
            $tokens   = isset($decoded['tokens']) && is_array($decoded['tokens']) ? $decoded['tokens'] : null;
        }

        $charCount = $itemName !== null ? mb_strlen($itemName) : null;

        $error = null;
        if ($itemName === null) {
            $error = 'no item_name returned';
        } elseif ($charCount > $ctx->itemNameMaxLen) {
            $error = "exceeds modular item_name cap of {$ctx->itemNameMaxLen} chars ({$charCount})";
        }

        return [
            'provider'         => $result->provider,
            'model'            => $result->model,
            'item_name'        => $itemName,
            'tokens'           => $tokens,
            'char_count'       => $charCount,
            'validation_error' => $error,
        ];
    }
}
