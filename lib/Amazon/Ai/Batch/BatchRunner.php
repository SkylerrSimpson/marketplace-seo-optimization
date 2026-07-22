<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Batch;

use Ige\Amazon\Ai\ProviderResult;

/**
 * Drives one or more provider batches to completion in a single blocking run:
 * submit every provider's requests, poll them all on a fixed interval until each
 * reaches a terminal state, then collect the per-request results keyed by SKU.
 *
 * A provider that fails to submit, or whose batch ends in a wholesale failure,
 * is reported and skipped so the other provider's results still come back.
 *
 * The runner itself holds no state: persistence of the submitted batch ids (so a
 * later invocation can cancel or resume them) is the caller's job, wired through
 * the $onSubmit hook on run(). collect() is the poll+fetch half on its own, used
 * to resume batches whose ids were persisted by an earlier, interrupted run.
 */
final class BatchRunner
{
    /** @var callable(string):void */
    private $log;

    /**
     * @param (callable(string):void)|null $log line sink (default: echo + newline)
     */
    public function __construct(
        private int $pollIntervalSeconds = 30,
        ?callable $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {
            echo $line . PHP_EOL;
        };
    }

    /**
     * Submit every provider's batch, hand the resulting ids to $onSubmit (so the
     * caller can persist them before the blocking poll), then poll and collect.
     *
     * @param array<string,BatchProviderInterface>                             $providers          pid => provider
     * @param array<string,list<array{custom_id:string,prompt:string,maxTokens:int}>> $requestsByProvider pid => requests
     * @param (callable(array<string,string>):void)|null                       $onSubmit           pid => batchId, called once after submit
     * @return array<string,array<string,ProviderResult>>                      pid => (custom_id => result)
     */
    public function run(array $providers, array $requestsByProvider, ?callable $onSubmit = null): array
    {
        $batchIds = $this->submitAll($providers, $requestsByProvider);
        if ($onSubmit !== null && $batchIds !== []) {
            $onSubmit($batchIds);
        }

        return $this->collect($providers, $batchIds);
    }

    /**
     * Poll already-submitted batches to completion and read their results. This
     * is run()'s second half, exposed so a resumed run can attach to batches
     * whose ids were persisted by an earlier invocation.
     *
     * @param array<string,BatchProviderInterface> $providers pid => provider
     * @param array<string,string>                 $batchIds  pid => batchId
     * @return array<string,array<string,ProviderResult>>     pid => (custom_id => result)
     */
    public function collect(array $providers, array $batchIds): array
    {
        $this->pollToCompletion($providers, $batchIds);

        $results = [];
        foreach ($batchIds as $pid => $batchId) {
            $results[$pid] = $this->drain($providers[$pid], $batchId);
        }

        return $results;
    }

    /**
     * A single, non-blocking status pass over already-submitted batches: poll
     * each, immediately drain the results of any that have reached a terminal
     * state, and report which are still running. Unlike collect(), this never
     * waits — a provider that finished is read and returned even while a slower
     * provider on the same run is still processing, so one laggard can't hold
     * another's completed (already-paid) results hostage. Callers that see a
     * non-empty `pending` should keep the manifest and poll again later.
     *
     * @param array<string,BatchProviderInterface> $providers pid => provider
     * @param array<string,string>                 $batchIds  pid => batchId
     * @return array{results: array<string,array<string,ProviderResult>>, pending: list<string>}
     */
    public function poll(array $providers, array $batchIds): array
    {
        $results = [];
        $pending = [];
        foreach ($batchIds as $pid => $batchId) {
            $status = $providers[$pid]->pollStatus($batchId);
            ($this->log)(sprintf('  %-9s %s', $pid, $status->summary()));

            if (!$status->ended) {
                $pending[] = $pid;
                continue;
            }
            if ($status->failed) {
                ($this->log)("  [WARN] {$pid}: batch ended as '{$status->rawStatus}' — collecting any partial results");
            }
            $results[$pid] = $this->drain($providers[$pid], $batchId);
        }

        return ['results' => $results, 'pending' => $pending];
    }

    /**
     * Read every result of one ended batch into a custom_id => ProviderResult
     * map. A fetch failure is logged and yields an empty map rather than
     * aborting the run, so a sibling provider's results still come back.
     *
     * @return array<string,ProviderResult>
     */
    private function drain(BatchProviderInterface $provider, string $batchId): array
    {
        $out = [];
        try {
            foreach ($provider->fetchResults($batchId) as $customId => $result) {
                $out[$customId] = $result;
            }
        } catch (\Throwable $e) {
            ($this->log)("  [ERROR] {$provider->name()}: fetching results failed: {$e->getMessage()}");
        }

        return $out;
    }

    /**
     * @param array<string,BatchProviderInterface>                             $providers
     * @param array<string,list<array{custom_id:string,prompt:string,maxTokens:int}>> $requestsByProvider
     * @return array<string,string> pid => batchId (only providers that submitted)
     */
    private function submitAll(array $providers, array $requestsByProvider): array
    {
        $batchIds = [];
        foreach ($providers as $pid => $provider) {
            $requests = $requestsByProvider[$pid] ?? [];
            if ($requests === []) {
                continue;
            }
            try {
                $batchIds[$pid] = $provider->submitBatch($requests);
                ($this->log)(sprintf('  submitted %-9s batch %s (%d requests)', $pid, $batchIds[$pid], count($requests)));
            } catch (\Throwable $e) {
                ($this->log)("  [ERROR] {$pid}: submit failed: {$e->getMessage()}");
            }
        }

        return $batchIds;
    }

    /**
     * @param array<string,BatchProviderInterface> $providers
     * @param array<string,string>                 $batchIds  pid => batchId
     */
    private function pollToCompletion(array $providers, array $batchIds): void
    {
        $pending = array_keys($batchIds);

        while ($pending !== []) {
            $stillPending = [];
            foreach ($pending as $pid) {
                $status = $providers[$pid]->pollStatus($batchIds[$pid]);
                ($this->log)(sprintf('  %-9s %s', $pid, $status->summary()));

                if (!$status->ended) {
                    $stillPending[] = $pid;
                } elseif ($status->failed) {
                    ($this->log)("  [WARN] {$pid}: batch ended as '{$status->rawStatus}' — collecting any partial results");
                }
            }

            $pending = $stillPending;
            if ($pending !== []) {
                sleep($this->pollIntervalSeconds);
            }
        }
    }
}
