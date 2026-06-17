<?php

declare(strict_types=1);

use DTS\eBaySDK\Taxonomy\Services\TaxonomyService;
use DTS\eBaySDK\Taxonomy\Types\GetADefaultCategoryTreeIdRestRequest;
use DTS\eBaySDK\Trading\Services\TradingService;
use DTS\eBaySDK\Trading\Types\GeteBayOfficialTimeRequestType;
use EbayOauthToken\EbayOauthToken;

/**
 * Thin, framework-free wrapper around the company-standard eBay stack
 * (benmorel/ebay-sdk-php + dvicklund/ebay-oauth-php-client).
 *
 * Responsibilities:
 *   - Resolve per-account credentials from .env (DOWS = base block, IGE = _IGE
 *     overrides with fallback to base; sandbox = _SB block).
 *   - Mint OAuth tokens: an application token (client-credentials) for the
 *     Taxonomy API, and a user token (refresh-token grant) for Trading/Feed.
 *   - Hand back configured benmorel service objects (Taxonomy, Trading).
 *
 * Auth model (matches Usurper): app token -> Taxonomy REST; user token ->
 * Trading as the X-EBAY-API-IAF-TOKEN (OAuth, not Auth'n'Auth).
 */
final class EbayClient
{
    /** Minimal scope sufficient for Taxonomy + read Trading. */
    public const SCOPE_BASE = 'https://api.ebay.com/oauth/api_scope';

    /**
     * Taxonomy API version. The bundled SDK defaults to the stale 'v1_beta'
     * resource path, which now 404s ("[2002] Resource not found"); the GA
     * version is 'v1'. Pin it here for every Taxonomy call.
     */
    public const TAXONOMY_API_VERSION = 'v1';

    private string $account;            // 'dows' | 'ige'
    private string $env;                // 'PRODUCTION' | 'SANDBOX' (dvicklund's vocabulary)
    private bool $sandbox;
    /** @var array{app_id:?string,cert_id:?string,dev_id:?string,ru_name:?string,refresh_token:?string,user_token:?string} */
    private array $creds;

    private ?string $appToken = null;
    private ?string $userToken = null;

    public function __construct(string $account, ?string $mode = null)
    {
        $this->account = strtolower(trim($account));
        if (!in_array($this->account, ['dows', 'ige'], true)) {
            throw new InvalidArgumentException("Unknown eBay account '{$account}' (expected dows|ige).");
        }

        $mode = strtolower($mode ?? ($_ENV['EBAY_API_MODE'] ?? 'production'));
        $this->sandbox = ($mode === 'sandbox');
        $this->env = $this->sandbox ? 'SANDBOX' : 'PRODUCTION';

        $this->creds = [
            'app_id'        => $this->envValue('APP_ID'),
            'cert_id'       => $this->envValue('CERT_ID'),
            'dev_id'        => $this->envValue('DEV_ID'),
            'ru_name'       => $this->envValue('RU_NAME'),
            'refresh_token' => $this->envValue('REFRESH_TOKEN'),
            // Escape hatch: a directly-supplied user access token (the ~2h OAuth
            // token shown by eBay's hosted sign-in tool). Lets us exercise
            // Trading/Feed before a durable refresh token exists. Expires fast.
            'user_token'    => $this->envValue('USER_TOKEN'),
        ];
    }

    public function account(): string
    {
        return $this->account;
    }

    /** Read a resolved credential (app_id|cert_id|dev_id|ru_name|refresh_token|user_token). */
    public function cred(string $name): ?string
    {
        return $this->creds[$name] ?? null;
    }

    /** 'PRODUCTION' | 'SANDBOX' (dvicklund's vocabulary). */
    public function env(): string
    {
        return $this->env;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /** True only if app-level keys (app_id + cert_id) are present. */
    public function hasAppCreds(): bool
    {
        return $this->creds['app_id'] !== null && $this->creds['cert_id'] !== null;
    }

    /**
     * True if we can obtain a user token — either a per-account refresh token
     * (durable) or a directly-supplied user access token (the temporary hatch).
     */
    public function hasUserCreds(): bool
    {
        return $this->hasAppCreds()
            && ($this->creds['refresh_token'] !== null || $this->creds['user_token'] !== null);
    }

    /**
     * Application access token via the client-credentials grant. Used to call
     * the Taxonomy API. Cached for the life of the object.
     */
    public function appToken(): string
    {
        if ($this->appToken !== null) {
            return $this->appToken;
        }
        $this->requireCreds(['app_id', 'cert_id']);

        $json = $this->oauth()->getApplicationToken($this->env, [self::SCOPE_BASE]);

        return $this->appToken = $this->extractAccessToken($json, 'application');
    }

    /**
     * User access token via the refresh-token grant. Used as the OAuth IAF
     * token for Trading/Feed. Cached for the life of the object.
     */
    public function userToken(): string
    {
        if ($this->userToken !== null) {
            return $this->userToken;
        }

        // Prefer a directly-supplied user access token if present (the hatch).
        if ($this->creds['user_token'] !== null) {
            return $this->userToken = $this->creds['user_token'];
        }

        $this->requireCreds(['app_id', 'cert_id', 'refresh_token']);

        // Pass scope=null so the request OMITS the scope param entirely. dvicklund
        // otherwise defaults to ['api_scope'], which NARROWS the access token to
        // just the base scope and strips the granted Sell/Identity scopes (-> 403).
        // Omitting scope makes eBay inherit the refresh token's full scope set.
        $json = $this->oauth()->getAccessToken($this->env, $this->creds['refresh_token'], null);

        return $this->userToken = $this->extractAccessToken($json, 'user');
    }

    /** Configured Taxonomy (REST) service, authorized with the app token. */
    public function taxonomy(string $marketplaceId = 'EBAY_US'): TaxonomyService
    {
        return new TaxonomyService([
            'authorization' => $this->appToken(),
            'marketplaceId' => $marketplaceId,
            'apiVersion'    => self::TAXONOMY_API_VERSION,
            'sandbox'       => $this->sandbox,
        ]);
    }

    /** Configured Trading (XML) service, authorized with the user OAuth token. */
    public function trading(): TradingService
    {
        return new TradingService([
            'credentials' => [
                'appId'  => (string) $this->creds['app_id'],
                'certId' => (string) $this->creds['cert_id'],
                'devId'  => (string) ($this->creds['dev_id'] ?? ''),
            ],
            'authorization' => $this->userToken(), // sent as X-EBAY-API-IAF-TOKEN
            'siteId'        => (int) ($_ENV['EBAY_API_SITE_ID'] ?? 0),
            'sandbox'       => $this->sandbox,
        ]);
    }

    /** Convenience ping: the default category tree id for a marketplace (e.g. "0" for EBAY_US). */
    public function defaultCategoryTreeId(string $marketplaceId = 'EBAY_US'): string
    {
        $request = new GetADefaultCategoryTreeIdRestRequest();
        $request->marketplace_id = $marketplaceId;

        $response = $this->taxonomy($marketplaceId)->getADefaultCategoryTreeId($request);

        if (count($response->errors) > 0) {
            throw new RuntimeException('Taxonomy error: ' . $this->formatRestErrors($response->errors));
        }
        // Note: EBAY_US's tree id is the string "0" — don't use empty()/falsy checks here.
        if ($response->categoryTreeId === null || $response->categoryTreeId === '') {
            throw new RuntimeException('Taxonomy returned no categoryTreeId.');
        }

        return $response->categoryTreeId;
    }

    /**
     * Convenience ping: eBay official time (ISO-8601), via the legacy Trading
     * XML API. NOTE: eBay's Akamai edge blocks POSTs to /ws/api.dll from some
     * networks (returns 503 "Zero size object"); when that happens this throws
     * even though the token is valid. Prefer whoAmI() (REST) to verify the user
     * token. Kept for environments where Trading is reachable.
     */
    public function officialTime(): string
    {
        $response = $this->trading()->geteBayOfficialTime(new GeteBayOfficialTimeRequestType());

        $ack = (string) $response->Ack;
        if ($ack !== 'Success' && $ack !== 'Warning') {
            throw new RuntimeException('Trading error: ' . $this->formatTradingErrors($response));
        }

        return $response->Timestamp instanceof DateTimeInterface
            ? $response->Timestamp->format(DATE_ATOM)
            : (string) $response->Timestamp;
    }

    /**
     * Authenticated REST GET with the user token. Returns decoded JSON.
     * This is the modern surface (Sell/Commerce/Taxonomy) — reachable where the
     * legacy Trading endpoint is edge-blocked.
     */
    public function userGet(string $url): array
    {
        $token = $this->userToken();

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("eBay REST request failed: {$err}");
        }

        $data = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            $msg = $data['errors'][0]['message'] ?? substr((string) $body, 0, 200);
            throw new RuntimeException("eBay REST {$status}: {$msg}");
        }

        return is_array($data) ? $data : [];
    }

    /**
     * "Who am I" via the Commerce Identity API — proves the user token end to
     * end and tells us which seller we're acting as. Returns the seller's
     * display name (e.g. "Deals Only Web Store, Inc.").
     */
    public function whoAmI(): string
    {
        $me = $this->userGet('https://apiz.ebay.com/commerce/identity/v1/user/');

        return $me['businessAccount']['name']
            ?? $me['individualAccount']['firstName']
            ?? $me['username']
            ?? 'unknown account';
    }

    // --- internals ---------------------------------------------------------

    private function oauth(): EbayOauthToken
    {
        return new EbayOauthToken([
            'clientId'     => $this->creds['app_id'],
            'clientSecret' => $this->creds['cert_id'],
            'env'          => $this->env,
            'redirectUri'  => $this->creds['ru_name'] ?? '',
        ]);
    }

    /**
     * Resolve an EBAY_API_<BASE> value with account/mode precedence:
     *   sandbox -> EBAY_API_<BASE>_SB
     *   prod + ige -> EBAY_API_<BASE>_IGE, then EBAY_API_<BASE>
     *   prod + dows -> EBAY_API_<BASE>
     * Empty strings are treated as absent.
     */
    private function envValue(string $base): ?string
    {
        $keys = [];
        if ($this->sandbox) {
            $keys[] = "EBAY_API_{$base}_SB";
        } else {
            if ($this->account === 'ige') {
                $keys[] = "EBAY_API_{$base}_IGE";
            }
            $keys[] = "EBAY_API_{$base}";
        }

        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
            if (!is_string($value) || $value === '') {
                $fromEnv = getenv($key);
                $value = $fromEnv === false ? null : $fromEnv;
            }
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param string[] $required */
    private function requireCreds(array $required): void
    {
        $missing = array_values(array_filter(
            $required,
            fn(string $k): bool => ($this->creds[$k] ?? null) === null
        ));

        if ($missing !== []) {
            $envKeys = array_map(fn(string $k) => $this->exampleEnvKey($k), $missing);
            throw new RuntimeException(sprintf(
                "Missing eBay credentials for account '%s' (%s): set %s in .env.",
                $this->account,
                $this->env,
                implode(', ', $envKeys)
            ));
        }
    }

    private function exampleEnvKey(string $credKey): string
    {
        $base = strtoupper($credKey);
        if ($this->sandbox) {
            return "EBAY_API_{$base}_SB";
        }
        return $this->account === 'ige' ? "EBAY_API_{$base}_IGE" : "EBAY_API_{$base}";
    }

    private function extractAccessToken(?string $json, string $kind): string
    {
        if ($json === null || $json === '') {
            throw new RuntimeException("Empty response minting {$kind} token (network/credentials?).");
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException("Unparseable {$kind}-token response: {$json}");
        }
        if (isset($data['access_token']) && $data['access_token'] !== '') {
            return $data['access_token'];
        }

        $err = $data['error_description'] ?? $data['error'] ?? $json;
        throw new RuntimeException("eBay rejected the {$kind}-token request: {$err}");
    }

    /** @param iterable<object> $errors */
    private function formatRestErrors(iterable $errors): string
    {
        $parts = [];
        foreach ($errors as $e) {
            $parts[] = trim(sprintf('[%s] %s', $e->errorId ?? '?', $e->message ?? ''));
        }
        return $parts === [] ? 'unknown error' : implode(' | ', $parts);
    }

    private function formatTradingErrors(object $response): string
    {
        if (count($response->Errors) === 0) {
            return (string) ($response->Ack ?? 'unknown error');
        }
        $parts = [];
        foreach ($response->Errors as $e) {
            $parts[] = trim(sprintf('[%s] %s', $e->ErrorCode ?? '?', $e->LongMessage ?? $e->ShortMessage ?? ''));
        }
        return implode(' | ', $parts);
    }
}
