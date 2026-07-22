<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Scripts\CredentialEnvMapper;
use PHPUnit\Framework\TestCase;

final class CredentialEnvMapperTest extends TestCase
{
    private CredentialEnvMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new CredentialEnvMapper([
            'ebay' => [
                'env_prefix' => 'EBAY_API_',
                'account_env_suffix' => ['ige' => '_IGE'],
            ],
        ]);
    }

    public function test_dows_gets_unsuffixed_env_names(): void
    {
        $env = $this->mapper->envFor('ebay', 'dows', ['app_id' => 'X', 'cert_id' => 'Y']);

        $this->assertSame(['EBAY_API_APP_ID' => 'X', 'EBAY_API_CERT_ID' => 'Y'], $env);
    }

    public function test_ige_gets_suffixed_env_names(): void
    {
        $env = $this->mapper->envFor('ebay', 'ige', ['app_id' => 'X']);

        $this->assertSame(['EBAY_API_APP_ID_IGE' => 'X'], $env);
    }

    public function test_an_account_not_listed_in_account_env_suffix_gets_no_suffix(): void
    {
        $env = $this->mapper->envFor('ebay', 'some-future-account', ['app_id' => 'X']);

        $this->assertSame(['EBAY_API_APP_ID' => 'X'], $env);
    }

    public function test_unknown_marketplace_returns_empty_array_not_an_error(): void
    {
        $env = $this->mapper->envFor('walmart', 'us', ['client_id' => 'X']);

        $this->assertSame([], $env);
    }

    public function test_empty_credentials_produces_empty_env(): void
    {
        $this->assertSame([], $this->mapper->envFor('ebay', 'dows', []));
    }
}
