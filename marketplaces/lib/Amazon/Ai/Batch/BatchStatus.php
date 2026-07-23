<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Batch;

/**
 * A point-in-time snapshot of one provider batch: whether it has reached a
 * terminal state, whether that terminal state is a wholesale failure, the raw
 * provider status string, and per-request counts for progress display.
 *
 * $processing / $succeeded / $errored come straight from the provider's
 * request_counts; providers that don't expose a given bucket report 0.
 */
final class BatchStatus
{
    public function __construct(
        public readonly bool $ended,
        public readonly bool $failed,
        public readonly string $rawStatus,
        public readonly int $processing = 0,
        public readonly int $succeeded = 0,
        public readonly int $errored = 0,
    ) {
    }

    /** Compact "status succeeded=/errored=/processing=" line for progress output. */
    public function summary(): string
    {
        return sprintf(
            '%s (succeeded=%d errored=%d processing=%d)',
            $this->rawStatus,
            $this->succeeded,
            $this->errored,
            $this->processing,
        );
    }
}
