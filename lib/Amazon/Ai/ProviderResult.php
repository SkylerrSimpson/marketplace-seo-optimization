<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * The outcome of one provider completion: which provider/model answered, the
 * raw text, and the decoded JSON object (or null when the model returned
 * non-JSON). Generators read structured fields off $decoded.
 */
final class ProviderResult
{
    /**
     * @param array<string,mixed>|null $decoded
     * @param array<string,mixed>      $usage    token counts when the SDK exposes them
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $rawText,
        public readonly ?array $decoded,
        public readonly array $usage = [],
    ) {
    }
}
