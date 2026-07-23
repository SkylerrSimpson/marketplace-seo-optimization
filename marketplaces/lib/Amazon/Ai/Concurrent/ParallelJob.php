<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Concurrent;

/**
 * One prompt to run concurrently: which provider/model answers it, the prompt
 * text, the visible output-token cap, and a caller-chosen custom_id used to look
 * the result back up after the pool drains.
 *
 * The batch counterpart is Batch\BatchProviderInterface's per-request array; this
 * is the immediate (full-price, no batch window) equivalent. Unlike the batch
 * custom_id, this one never leaves the process — it's a local lookup key — so it
 * carries no provider-side character restrictions.
 */
final class ParallelJob
{
    public function __construct(
        public readonly string $customId,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $prompt,
        public readonly int $maxTokens,
    ) {
    }
}
