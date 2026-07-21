<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Provider;

/**
 * Shared name/model accessors and JSON extraction for the concrete providers.
 */
abstract class AbstractProvider implements ProviderInterface
{
    use ParsesProviderJson;

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
}
