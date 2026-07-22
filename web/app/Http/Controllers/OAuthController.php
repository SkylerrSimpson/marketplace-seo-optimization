<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MarketplaceCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * In-browser OAuth connect flow. DOWScripts owns the browser redirect dance,
 * the CSRF state, and where the resulting token is stored (its own DB) — but
 * every actual call to the marketplace's OAuth endpoints is delegated to the
 * existing CLI script (shopify/scripts/oauth_mint.php), per dowscripts/CLAUDE.md's
 * rule that this app never talks to a marketplace API directly. The script's
 * client_secret never leaves the server and is never handed to DOWScripts.
 *
 * Shopify only, for now — eBay's RuName indirection may not accept a localhost
 * accept URL on a production keyset, so it waits until the app has a real domain.
 */
class OAuthController extends Controller
{
    // Every registered Shopify script runs under this interpreter (config/scripts/
    // shopify.php) — the OAuth helper is the same PHP CLI, so match it.
    private const INTERPRETER = 'php8.2';

    private const SCRIPT = 'marketplaces/shopify/scripts/oauth_mint.php';

    public function authorize(string $marketplace, Request $request): RedirectResponse
    {
        abort_unless($marketplace === 'shopify', 404);

        // Single-use CSRF nonce — validated (and consumed) in callback(). Standard
        // OAuth2 state protection: ties the eventual callback to this browser
        // session so a forged callback URL can't complete someone else's connect.
        $state = Str::random(40);
        $request->session()->put('oauth.shopify.state', $state);

        $redirectUri = route('oauth.callback', ['marketplace' => 'shopify']);

        $result = Process::path(config('paths.repo_root'))
            ->timeout(30)
            ->run([
                self::INTERPRETER, self::SCRIPT, 'authorize-url',
                "--redirect-uri={$redirectUri}",
                "--state={$state}",
            ]);

        if ($result->failed()) {
            return redirect()->route('credentials.index')
                ->with('status', 'oauth-failed');
        }

        // away() — this leaves the app entirely for Shopify's consent screen; it is
        // not one of our own named routes.
        return redirect()->away(trim($result->output()));
    }

    public function callback(string $marketplace, Request $request): RedirectResponse
    {
        abort_unless($marketplace === 'shopify', 404);

        // User clicked "Cancel" on Shopify's consent screen (or any OAuth error) —
        // no code will arrive; bail cleanly rather than treating it as tampering.
        if ($request->has('error')) {
            return redirect()->route('credentials.index')->with('status', 'oauth-declined');
        }

        // pull() = read-and-forget, so the nonce is strictly single-use (a captured
        // callback URL can't be replayed). hash_equals guards against timing attacks.
        $expected = $request->session()->pull('oauth.shopify.state');
        $received = (string) $request->query('state', '');
        abort_unless(is_string($expected) && $expected !== '' && hash_equals($expected, $received), 403);

        $code = (string) $request->query('code', '');
        abort_unless($code !== '', 400);

        $result = Process::path(config('paths.repo_root'))
            ->timeout(30)
            ->run([self::INTERPRETER, self::SCRIPT, 'web-exchange', "--code={$code}", '--json']);

        $data = json_decode($result->output(), true);
        if ($result->failed() || ! is_array($data) || empty($data['access_token'])) {
            return redirect()->route('credentials.index')->with('status', 'oauth-failed');
        }

        // Merge, never replace: only the three OAuth-derived fields are touched, and
        // only this user's own shopify/default row — no other user, marketplace, or
        // account is affected.
        $userId = $request->user()->id;
        $existing = MarketplaceCredential::forUser($userId)->forAccount('shopify', 'default')->first();
        $merged = array_merge($existing?->credentials ?? [], array_filter([
            'admin_api_token' => $data['access_token'] ?? null,
            'shop_domain' => $data['shop'] ?? null,
            'api_version' => $data['api_version'] ?? null,
        ], fn (mixed $v): bool => filled($v)));

        MarketplaceCredential::updateOrCreate(
            ['user_id' => $userId, 'marketplace' => 'shopify', 'account' => 'default'],
            ['credentials' => $merged],
        );

        return redirect()->route('credentials.index')->with('status', 'oauth-connected');
    }
}
