<?php

declare(strict_types=1);

require_once __DIR__ . '/AmazonOperationIds.php';

class AmazonRateLimits
{
    private const LIMITS = [
        AmazonOperationIds::GET_FEED             => ['rate' => 2,      'burst' => 15, 'decaySeconds' => 8],
        AmazonOperationIds::GET_FEEDS            => ['rate' => 0.0222, 'burst' => 10, 'decaySeconds' => 450],
        AmazonOperationIds::CREATE_FEED          => ['rate' => 0.0083, 'burst' => 15, 'decaySeconds' => 1800],
        AmazonOperationIds::CANCEL_FEED          => ['rate' => 2,      'burst' => 15, 'decaySeconds' => 7.5],
        AmazonOperationIds::CREATE_FEED_DOCUMENT => ['rate' => 0.5,    'burst' => 15, 'decaySeconds' => 30],
        AmazonOperationIds::GET_FEED_DOCUMENT    => ['rate' => 0.0222, 'burst' => 10, 'decaySeconds' => 450],

        AmazonOperationIds::GET_LISTINGS_ITEM    => ['rate' => 5, 'burst' => 10, 'decaySeconds' => 2],
        AmazonOperationIds::PUT_LISTINGS_ITEM    => ['rate' => 5, 'burst' => 10, 'decaySeconds' => 2],
        AmazonOperationIds::PATCH_LISTINGS_ITEM  => ['rate' => 5, 'burst' => 10, 'decaySeconds' => 2],
        AmazonOperationIds::DELETE_LISTINGS_ITEM => ['rate' => 5, 'burst' => 10, 'decaySeconds' => 2],

        AmazonOperationIds::GET_MARKETPLACE_PARTICIPATIONS   => ['rate' => 0.016, 'burst' => 15, 'decaySeconds' => 937.5],
        AmazonOperationIds::GET_DEFINITIONS_PRODUCT_TYPE     => ['rate' => 5,     'burst' => 10, 'decaySeconds' => 2],
        AmazonOperationIds::SEARCH_DEFINITIONS_PRODUCT_TYPES => ['rate' => 5,     'burst' => 10, 'decaySeconds' => 2],

        AmazonOperationIds::GET_CATALOG_ITEM        => ['rate' => 2,  'burst' => 2, 'decaySeconds' => 1],
        AmazonOperationIds::SEARCH_CATALOG_ITEMS    => ['rate' => 2,  'burst' => 2, 'decaySeconds' => 1],
        AmazonOperationIds::SEARCH_LISTINGS_ITEMS   => ['rate' => 5,  'burst' => 5, 'decaySeconds' => 0.2],

        AmazonOperationIds::CREATE_REPORT       => ['rate' => 0.0167, 'burst' => 15, 'decaySeconds' => 60],
        AmazonOperationIds::GET_REPORT          => ['rate' => 2,      'burst' => 15, 'decaySeconds' => 1],
        AmazonOperationIds::GET_REPORT_DOCUMENT => ['rate' => 0.0167, 'burst' => 15, 'decaySeconds' => 60],
    ];

    public static function getRate(string $operationId): ?array
    {
        return self::LIMITS[$operationId] ?? null;
    }

    /**
     * Sleeps for the minimum inter-request delay (1 / rate) for the given operation.
     */
    public static function throttle(string $operationId): void
    {
        $limits = self::getRate($operationId);
        if ($limits === null) {
            return;
        }
        usleep((int) (1_000_000 / $limits['rate']));
    }

    /**
     * Execute $fn(), retrying on 429 with exponential backoff.
     *
     * Handles the case where concurrent Usurper calls exhaust the shared
     * per-app rate limit before our throttle fires. On 429 the Retry-After
     * header value (if present) is preferred over the computed backoff.
     *
     * @param  callable(): array  $fn         Must return the decoded JSON array.
     * @param  string             $operationId Used to compute the base delay.
     * @param  int                $maxRetries
     * @return array
     */
    public static function retryWithBackoff(
        callable $fn,
        string $operationId,
        int $maxRetries = 4,
    ): array {
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $fn();
            } catch (\Saloon\Exceptions\Request\ClientException $e) {
                if ($e->getStatus() !== 429 || $attempt > $maxRetries) {
                    throw $e;
                }

                // Prefer Retry-After header; fall back to exponential backoff
                $retryAfter = (int) ($e->getResponse()->header('Retry-After') ?? 0);
                $backoff     = $retryAfter > 0
                    ? $retryAfter
                    : (int) pow(2, $attempt);   // 2s, 4s, 8s, 16s

                fwrite(STDERR, "  429 on {$operationId} (attempt {$attempt}/{$maxRetries}), backing off {$backoff}s...\n");
                sleep($backoff);
            }
        }
    }
}
