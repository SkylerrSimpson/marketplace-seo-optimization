<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Concurrent;

use Ige\Amazon\Ai\ProviderResult;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The raw-HTTP shape of one LLM provider for the concurrent path: build a PSR-7
 * request from a job, and parse the provider's PSR-7 response back into the same
 * ProviderResult the synchronous SDK providers return.
 *
 * This exists because the anthropic-ai/sdk and openai-php/client only expose
 * blocking calls; to run many prompts through a single GuzzleHttp\Pool we build
 * the requests ourselves — the same "raw payload in, raw response out" approach
 * the Batch providers already take. Request bodies and response extraction are
 * shared with the sync/batch paths (OpenAiProvider::requestBody, ParsesProviderJson)
 * so a completion behaves identically however it was dispatched.
 */
interface ProviderHttp
{
    /** Build the signed, ready-to-send request for one job. */
    public function request(ParallelJob $job): RequestInterface;

    /**
     * Parse a successful (2xx) provider response into a ProviderResult. A
     * non-JSON model body yields a null decoded field, mirroring the sync path.
     */
    public function parse(ParallelJob $job, ResponseInterface $response): ProviderResult;
}
