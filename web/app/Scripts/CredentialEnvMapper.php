<?php

declare(strict_types=1);

namespace App\Scripts;

/**
 * Turns a MarketplaceCredential's generic key/value bag into the real environment
 * variable names a wrapped script's own client actually reads — e.g. eBay's
 * ['app_id' => 'X'] becomes ['EBAY_API_APP_ID' => 'X'] for account 'dows', or
 * ['EBAY_API_APP_ID_IGE' => 'X'] for 'ige'. Shape (env_prefix, account_env_suffix)
 * comes from config/credentials.php, grounded in EbayClient.php's own envValue().
 */
final class CredentialEnvMapper
{
    /**
     * @param  array<string, array{env_prefix?: string, account_env_suffix?: array<string, string>}>|null  $config
     *                                                                                                              Injectable so tests never need to boot the framework just to call
     *                                                                                                              config() — same reasoning as ScriptRegistry's constructor-injected
     *                                                                                                              $configDir in Phase 1. Defaults to the real config/credentials.php.
     */
    public function __construct(private readonly ?array $config = null) {}

    /**
     * @param  array<string, string>  $credentials
     * @return array<string, string>
     */
    public function envFor(string $marketplace, string $account, array $credentials): array
    {
        $marketplaceConfig = ($this->config ?? config('credentials'))[$marketplace] ?? null;
        if (! is_array($marketplaceConfig)) {
            return [];
        }

        $prefix = (string) ($marketplaceConfig['env_prefix'] ?? '');
        $suffix = (string) ($marketplaceConfig['account_env_suffix'][$account] ?? '');

        $env = [];
        foreach ($credentials as $field => $value) {
            $env[$prefix.mb_strtoupper($field).$suffix] = $value;
        }

        return $env;
    }
}
