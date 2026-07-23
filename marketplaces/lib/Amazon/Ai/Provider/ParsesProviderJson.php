<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Provider;

/**
 * Shared markdown-fence stripping + JSON decode for provider responses, so the
 * synchronous providers (AbstractProvider) and the batch providers extract model
 * output identically. Mirrors the extraction in draft_listings.php.
 */
trait ParsesProviderJson
{
    /**
     * Strip markdown fences and json_decode.
     *
     * @return array{0:string,1:array<string,mixed>|null} [trimmed raw, decoded or null]
     */
    protected function parseJson(string $text): array
    {
        $text    = preg_replace('/^```(?:json)?\s*/m', '', $text) ?? $text;
        $text    = trim(preg_replace('/\s*```$/m', '', $text) ?? $text);
        $decoded = json_decode($text, true);

        return [$text, is_array($decoded) ? $decoded : null];
    }
}
