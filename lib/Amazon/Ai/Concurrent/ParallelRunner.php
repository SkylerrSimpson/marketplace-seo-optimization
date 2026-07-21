<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Concurrent;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use Ige\Amazon\Ai\ModelConfig;
use Ige\Amazon\Ai\ProviderResult;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Runs many LLM completions concurrently through a single GuzzleHttp\Pool and
 * returns each result keyed by its job's custom_id.
 *
 * The immediate, full-price counterpart to Batch\BatchRunner: no batch window,
 * no manifest, no polling — every prompt is in flight at once (bounded by a
 * concurrency cap) so one slow request never blocks the others. A request that
 * fails outright (network error, or an HTTP error still failing after retries)
 * becomes a ProviderResult with a null decoded body — the same failure signal
 * the batch path uses — so a single bad call is reported per-SKU rather than
 * aborting the run.
 *
 * Transient overload (429 / 5xx / 529) is retried with exponential backoff,
 * honoring a Retry-After header when the provider sends one.
 */
final class ParallelRunner
{
    /** @var array<string,ProviderHttp> provider id => adapter */
    private array $adapters;

    private Client $client;

    /** @var callable(string):void */
    private $log;

    /**
     * @param array<string,string> $apiKeys provider id (ModelConfig::ANTHROPIC/OPENAI) => key
     */
    public function __construct(
        array $apiKeys,
        private int $concurrency = 10,
        ?callable $log = null,
        int $maxRetries = 3,
        int $timeoutSeconds = 120,
    ) {
        $this->concurrency = max(1, $concurrency);
        $this->log        = $log ?? static function (string $line): void {
            echo $line . PHP_EOL;
        };

        $this->adapters = [];
        foreach ($apiKeys as $provider => $key) {
            $this->adapters[$provider] = $provider === ModelConfig::ANTHROPIC
                ? new AnthropicHttp($key)
                : new OpenAiHttp($key);
        }

        $this->client = new Client([
            'handler'     => $this->handlerStack($maxRetries),
            'timeout'     => $timeoutSeconds,
            'http_errors' => true, // exhausted HTTP errors reject -> per-job error result
        ]);
    }

    /**
     * Dispatch every job concurrently and collect the results.
     *
     * @param  list<ParallelJob> $jobs
     * @return array<string,ProviderResult> custom_id => result
     */
    public function run(array $jobs): array
    {
        $jobs = array_values($jobs);
        if ($jobs === []) {
            return [];
        }

        $results = [];

        $pool = new Pool($this->client, (function () use ($jobs) {
            foreach ($jobs as $job) {
                yield $this->adapter($job->provider)->request($job);
            }
        })(), [
            'concurrency' => $this->concurrency,
            'fulfilled'   => function (ResponseInterface $response, int $index) use ($jobs, &$results): void {
                $job                     = $jobs[$index];
                $results[$job->customId] = $this->adapter($job->provider)->parse($job, $response);
            },
            'rejected'    => function (mixed $reason, int $index) use ($jobs, &$results): void {
                $job = $jobs[$index];
                $msg = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                ($this->log)("  [ERROR] {$job->provider}/{$job->customId}: {$msg}");
                $results[$job->customId] = new ProviderResult($job->provider, $job->model, $msg, null);
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }

    private function adapter(string $provider): ProviderHttp
    {
        return $this->adapters[$provider]
            ?? throw new \InvalidArgumentException("No API key configured for provider '{$provider}'.");
    }

    /** Handler stack with retry-on-overload (429/5xx/529, honoring Retry-After). */
    private function handlerStack(int $maxRetries): HandlerStack
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(
            static function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?\Throwable $e = null) use ($maxRetries): bool {
                if ($retries >= $maxRetries) {
                    return false;
                }
                if ($e instanceof ConnectException) {
                    return true;
                }

                return $response !== null
                    && in_array($response->getStatusCode(), [429, 500, 502, 503, 529], true);
            },
            static function (int $retries, ?ResponseInterface $response = null): int {
                // Milliseconds. Honor Retry-After (seconds) when present, else 1s,2s,4s…
                if ($response !== null && $response->hasHeader('Retry-After')) {
                    return 1000 * max(1, (int) $response->getHeaderLine('Retry-After'));
                }

                return (int) (1000 * 2 ** $retries);
            },
        ));

        return $stack;
    }
}
