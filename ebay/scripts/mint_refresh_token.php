<?php

declare(strict_types=1);

/**
 * Mint a durable OAuth refresh token via the authorization-code grant.
 *
 * eBay's hosted token tool only hands out 2-hour ACCESS tokens. A refresh token
 * (~18 months) only comes from the auth-code flow, where a server exchanges a
 * one-time `code`. This is that server step, done by hand:
 *
 *   1. Add an eBay Redirect URL (RuName) to your keyset whose accept URL is one
 *      you can read — even a 404 on a domain you own. Put the RuName in
 *      .env as EBAY_API_RU_NAME (or EBAY_API_RU_NAME_IGE).
 *
 *   2. Get the consent URL:
 *        php ebay/scripts/mint_refresh_token.php --account=dows
 *      Open it, sign in as the seller, accept. eBay redirects your browser to
 *      your accept URL with `?code=...` — copy that code from the address bar.
 *
 *   3. Exchange it:
 *        php ebay/scripts/mint_refresh_token.php --account=dows --code='PASTE'
 *      The refresh token is written to .env (quoted) as EBAY_API_REFRESH_TOKEN.
 *
 * Read-only against your listings; only writes the token line in .env.
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

/** Scopes baked into the refresh token (fixed at consent time). Read + inventory/feed write. */
const SCOPES = [
    'https://api.ebay.com/oauth/api_scope',
    'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly',
    'https://api.ebay.com/oauth/api_scope/sell.inventory',
    'https://api.ebay.com/oauth/api_scope/sell.account.readonly',
    'https://api.ebay.com/oauth/api_scope/commerce.identity.readonly',
];

$opts    = getopt('', ['account:', 'mode:', 'code:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php mint_refresh_token.php --account=dows|ige [--mode=production|sandbox] [--code='<code>']\n");
    exit(0);
}

$account = strtolower((string) ($opts['account'] ?? 'dows'));
$client  = new EbayClient($account, $opts['mode'] ?? null);
$sandbox = $client->isSandbox();

$appId  = $client->cred('app_id');
$certId = $client->cred('cert_id');
$ruName = $client->cred('ru_name');

if ($appId === null || $certId === null) {
    fail("Missing app_id/cert_id in .env for account '{$account}'.");
}
if ($ruName === null) {
    fail("Missing RuName. Add an eBay Redirect URL to the keyset and set " .
        ($account === 'ige' ? 'EBAY_API_RU_NAME_IGE' : 'EBAY_API_RU_NAME') . " in .env.");
}

$authBase  = $sandbox ? 'https://auth.sandbox.ebay.com/oauth2/authorize' : 'https://auth.ebay.com/oauth2/authorize';
$tokenHost = $sandbox ? 'api.sandbox.ebay.com' : 'api.ebay.com';

// --- Step 2: no code yet -> print the consent URL ------------------------------
if (!isset($opts['code'])) {
    $url = $authBase . '?' . http_build_query([
        'client_id'     => $appId,
        'redirect_uri'  => $ruName,            // eBay wants the RuName here, not the literal URL
        'response_type' => 'code',
        'scope'         => implode(' ', SCOPES),
        'prompt'        => 'login',
    ]);

    fwrite(STDOUT, "\n1. Open this URL, sign in as the {$account} seller, and accept:\n\n{$url}\n\n");
    fwrite(STDOUT, "2. eBay redirects your browser to your accept URL with ?code=...\n");
    fwrite(STDOUT, "   Copy the value of `code` from the address bar (it's URL-encoded — that's fine).\n\n");
    fwrite(STDOUT, "3. Run again with it:\n");
    fwrite(STDOUT, "   php ebay/scripts/mint_refresh_token.php --account={$account} --code='PASTE_CODE_HERE'\n\n");
    exit(0);
}

// --- Step 3: exchange the code -> refresh token --------------------------------
$code = (string) $opts['code'];
// Codes copied from the address bar are URL-encoded (contain %); decode once so
// http_build_query re-encodes them correctly.
if (str_contains($code, '%')) {
    $code = urldecode($code);
}

$ch = curl_init("https://{$tokenHost}/identity/v1/oauth2/token");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Basic ' . base64_encode("{$appId}:{$certId}"),
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => $ruName,
    ]),
    CURLOPT_TIMEOUT => 30,
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($body === false) {
    fail("Token request failed: {$err}");
}

$data = json_decode((string) $body, true);
if (!is_array($data) || !isset($data['refresh_token'])) {
    $msg = $data['error_description'] ?? $data['error'] ?? (string) $body;
    fail("eBay did not return a refresh token: {$msg}");
}

$refresh    = $data['refresh_token'];
$refreshKey = $account === 'ige' ? 'EBAY_API_REFRESH_TOKEN_IGE' : 'EBAY_API_REFRESH_TOKEN';
envSet(REPO_ROOT . '/.env', $refreshKey, $refresh);

fwrite(STDOUT, "\n✓ Refresh token minted (" . strlen($refresh) . " chars, refresh_token_expires_in="
    . ($data['refresh_token_expires_in'] ?? '?') . "s).\n");
fwrite(STDOUT, "✓ Wrote {$refreshKey} to .env (quoted).\n");
fwrite(STDOUT, "  You can now remove the temporary EBAY_API_USER_TOKEN line.\n");
fwrite(STDOUT, "  Verify: php ebay/scripts/check_connection.php --account={$account}\n\n");
exit(0);

// --- helpers -------------------------------------------------------------------

function fail(string $msg): never
{
    fwrite(STDERR, "✗ {$msg}\n");
    exit(1);
}

/** Upsert KEY="value" in .env (quoted so '#' in tokens survives phpdotenv). */
function envSet(string $path, string $key, string $value): void
{
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $line  = $key . '="' . $value . '"';
    $found = false;
    foreach ($lines as &$l) {
        if (preg_match('/^' . preg_quote($key, '/') . '=/', $l)) {
            $l = $line;
            $found = true;
            break;
        }
    }
    unset($l);
    if (!$found) {
        $lines[] = $line;
    }
    file_put_contents($path, implode("\n", $lines) . "\n");
}
