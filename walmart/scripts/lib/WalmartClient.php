<?php

declare(strict_types=1);

use Walmart\Apis\MP\MarketplaceApi;
use Walmart\Configuration;
use Walmart\Enums\Country;
use Walmart\Walmart;

/**
 * Thin wrapper around highsidelabs/walmart-api.
 *
 * Responsibilities:
 *   - Resolve per-country credentials from .env (WALMART_CLIENT_ID_US/_CA + _SECRET).
 *   - Hand back a configured MarketplaceApi instance for that country. Token minting
 *     and caching is handled internally by Walmart\Configuration::getAccessToken()
 *     (OAuth2 client_credentials) — no manual refresh step needed, unlike eBay.
 *
 * Same host/auth flow serves both the legacy country APIs and the unified Global
 * APIs Walmart is migrating CA to by 2026-07-31 — this client needs no changes for
 * that migration, only new CA keys in .env.
 */
final class WalmartClient
{
    private string $country; // 'us' | 'ca'
    private Configuration $config;
    private ?MarketplaceApi $marketplace = null;

    public function __construct(string $country)
    {
        $this->country = strtolower(trim($country));
        if (!in_array($this->country, ['us', 'ca'], true)) {
            throw new InvalidArgumentException("Unknown Walmart country '{$country}' (expected us|ca).");
        }

        $clientId     = $this->envValue('WALMART_CLIENT_ID_' . strtoupper($this->country));
        $clientSecret = $this->envValue('WALMART_CLIENT_SECRET_' . strtoupper($this->country));

        if ($clientId === null || $clientSecret === null) {
            throw new RuntimeException(sprintf(
                "Missing Walmart credentials for '%s': set WALMART_CLIENT_ID_%s and WALMART_CLIENT_SECRET_%s in .env.",
                $this->country,
                strtoupper($this->country),
                strtoupper($this->country)
            ));
        }

        $this->config = new Configuration([
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret,
            'country'      => $this->country === 'us' ? Country::US : Country::CA,
        ]);
    }

    public function country(): string
    {
        return $this->country;
    }

    /** Configured Marketplace API entrypoint. Call ->items(), ->inventory(), ->prices(), etc. on it. */
    public function marketplace(): MarketplaceApi
    {
        return $this->marketplace ??= Walmart::marketplace($this->config);
    }

    /**
     * Raw authenticated GET, bypassing the generated SDK method — needed for query
     * params the SDK doesn't expose (e.g. includeDetails on /v3/items; the generated
     * getAllItems() signature is missing it even though Walmart's real API requires
     * it to return additionalAttributes). Mirrors EbayClient::userGet()'s escape
     * hatch for the same class of generated-SDK gap.
     *
     * @param array<string,string> $query
     * @return array decoded JSON body
     */
    public function rawGet(string $path, array $query = []): array
    {
        // Ensures marketplace() has been touched so getAccessToken() has a client to
        // mint against (Configuration::getAccessToken() calls Walmart::marketplace()
        // internally anyway, but this keeps the token cache keyed consistently).
        $this->marketplace();
        $token = $this->config->getAccessToken()->accessToken;

        $url = rtrim(Walmart::HOST, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'WM_SEC.ACCESS_TOKEN: ' . $token,
                'WM_SVC.NAME: WalmartMarketplace',
                'WM_QOS.CORRELATION_ID: ' . bin2hex(random_bytes(16)),
                'WM_MARKET: ' . strtoupper($this->country),
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Walmart REST request failed: {$err}");
        }

        $data = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            $msg = $data['errors'][0]['description'] ?? substr((string) $body, 0, 300);
            throw new RuntimeException("Walmart REST {$status}: {$msg}");
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Raw authenticated POST with a JSON body — same escape hatch as rawGet(), for
     * endpoints the generated SDK doesn't expose usably (e.g. /v3/items/spec).
     *
     * @param array<string,mixed> $body
     * @return array decoded JSON body
     */
    public function rawPost(string $path, array $body): array
    {
        $this->marketplace();
        $token = $this->config->getAccessToken()->accessToken;

        $url = rtrim(Walmart::HOST, '/') . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'WM_SEC.ACCESS_TOKEN: ' . $token,
                'WM_SVC.NAME: WalmartMarketplace',
                'WM_QOS.CORRELATION_ID: ' . bin2hex(random_bytes(16)),
                'WM_MARKET: ' . strtoupper($this->country),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $respBody = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($respBody === false) {
            throw new RuntimeException("Walmart REST request failed: {$err}");
        }

        $data = json_decode((string) $respBody, true);
        if ($status < 200 || $status >= 300) {
            $msg = $data['errors'][0]['description'] ?? substr((string) $respBody, 0, 300);
            throw new RuntimeException("Walmart REST {$status}: {$msg}");
        }

        return is_array($data) ? $data : [];
    }

    private function envValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (!is_string($value) || $value === '') {
            $fromEnv = getenv($key);
            $value = $fromEnv === false ? null : $fromEnv;
        }
        return (is_string($value) && trim($value) !== '') ? trim($value) : null;
    }
}
