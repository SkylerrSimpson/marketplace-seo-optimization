<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Batch;

use Ige\Amazon\Ai\ProviderResult;

/**
 * One LLM provider's Batch API capability: submit many prompts as a single
 * asynchronous job, poll it to completion, then read the per-request results.
 *
 * The batch counterpart to Provider\ProviderInterface. Each request carries a
 * caller-chosen custom_id (the SKU) that flows through unchanged so results can
 * be matched back without any local state file. Implementations own the
 * provider-specific submit/poll/fetch shape (Anthropic's inline requests vs.
 * OpenAI's JSONL file upload) and return the same ProviderResult the generators
 * already consume.
 */
interface BatchProviderInterface
{
    /** Provider id (ModelConfig::ANTHROPIC / ::OPENAI). */
    public function name(): string;

    /** Model ID this instance batches. */
    public function model(): string;

    /**
     * Submit a batch and return the provider's batch id.
     *
     * @param list<array{custom_id:string,prompt:string,maxTokens:int}> $requests
     * @throws \Throwable on submit failure (callers handle per provider)
     */
    public function submitBatch(array $requests): string;

    /** Current status + request counts for a submitted batch. */
    public function pollStatus(string $batchId): BatchStatus;

    /**
     * Cancel an in-flight batch. Idempotent-ish: providers no-op or error softly
     * if the batch has already reached a terminal state.
     *
     * @throws \Throwable on cancel failure (callers report per provider)
     */
    public function cancelBatch(string $batchId): void;

    /**
     * Read every result of an ended batch, keyed by the custom_id supplied at
     * submit time. Errored/expired requests yield a ProviderResult with a null
     * decoded body so the generators surface them as validation errors.
     *
     * @return iterable<string,ProviderResult>
     */
    public function fetchResults(string $batchId): iterable;
}
