<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Provider;

/**
 * Shared name/model accessors and JSON extraction for the concrete providers.
 */
abstract class AbstractProvider implements ProviderInterface
{
    public function __construct(
        private string $provider,
        private string $model,
    ) {
    }

    public function name(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Strip markdown fences and json_decode, mirroring the extraction in
     * draft_listings.php.
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
