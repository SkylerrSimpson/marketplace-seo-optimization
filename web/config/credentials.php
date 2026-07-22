<?php

declare(strict_types=1);

// Per marketplace: which fields the credentials UI shows/accepts, and how to turn
// them into the real env vars EbayClient.php actually reads. Grounded directly in
// ../marketplaces/ebay/scripts/lib/EbayClient.php: $this->creds keys (fields), its envValue()
// prefix, and its account-suffix precedence (ige -> EBAY_API_<BASE>_IGE, dows ->
// unsuffixed) — not guessed. A marketplace missing here just shows "no known
// credential fields yet" and gets no env injection — add its shape when its scripts
// actually get registered, not speculatively now.

return [
    'ebay' => [
        'fields' => ['app_id', 'cert_id', 'dev_id', 'ru_name', 'refresh_token'],
        'env_prefix' => 'EBAY_API_',
        'account_env_suffix' => ['ige' => '_IGE'],
        // Same roster as ScriptController::KNOWN_ACCOUNTS — only these accounts have
        // scripts registered against them, so the "Add an account" form offers a
        // dropdown of exactly these rather than free text (a typo'd account here
        // creates a dead credential row no script will ever match). A marketplace
        // with no 'accounts' key here falls back to free text — see
        // CredentialController::newForm().
        'accounts' => ['dows', 'ige'],
        // Plain-language steps for a non-developer to actually get these values —
        // mirrors marketplaces/ebay/scripts/mint_refresh_token.php's own docblock, which is
        // otherwise only visible to someone who thinks to open that file.
        'instructions' => [
            'app_id, cert_id, and dev_id come from your eBay Developer Account\'s "Application Keys" page (developer.ebay.com) — copy them as-is.',
            'ru_name (a "Redirect URL" name) is set up once on that same keyset — it just needs an accept URL you can read, even a 404 page on a domain you own.',
            'From a terminal in the repo, run: php marketplaces/ebay/scripts/mint_refresh_token.php --account=dows (use --account=ige for that account instead).',
            'Open the URL it prints, sign in as the seller, and approve. eBay redirects your browser to your accept URL with ?code=... in the address bar — copy that code.',
            'Run: php marketplaces/ebay/scripts/mint_refresh_token.php --account=dows --code=\'PASTE_CODE_HERE\' — it prints the refresh token. Paste that into the refresh_token field below and Save.',
        ],
    ],

    'shopify' => [
        'fields' => ['shop_domain', 'admin_api_token', 'api_version'],
        'env_prefix' => '',
        // No per-account suffixing — see 'accounts' below.
        'account_env_suffix' => [],
        // Enables the "Connect with Shopify" in-browser OAuth button on this
        // marketplace's credentials edit page (OAuthController). eBay has no
        // 'oauth' key yet — its RuName flow waits on a real domain.
        'oauth' => true,
        // Shopify is a single flat store, not a roster of seller accounts like
        // eBay — 'default' is a sentinel, not a real account name. It's the one
        // credential row RunScriptJob::buildEnv() falls back to for scripts that
        // have no --account param at all (every Shopify script). The "Add an
        // account" form skips asking for an account name when this list is
        // exactly ['default'] — see credentials/index.blade.php.
        'accounts' => ['default'],
        'instructions' => [
            'shop_domain is your store\'s *.myshopify.com admin domain (not your custom domain — check Shopify Admin > Settings > Domains for the myshopify.com one).',
            'From a terminal in the repo, run: php marketplaces/shopify/scripts/oauth_mint.php url — it prints a consent URL.',
            'Open it in a browser where you\'re logged into the store admin, and click Install/Update to approve.',
            'Your browser will try to load https://localhost/callback?... and fail to connect — that\'s expected. Copy the WHOLE url from the address bar.',
            'Run: php marketplaces/shopify/scripts/oauth_mint.php exchange "PASTE_FULL_URL_HERE" — it prints the access token. Paste that into the admin_api_token field below, and api_version can be left as whatever this repo\'s .env already uses (e.g. 2026-04) unless told otherwise.',
        ],
    ],
];
