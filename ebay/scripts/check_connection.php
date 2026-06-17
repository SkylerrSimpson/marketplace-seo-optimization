<?php

declare(strict_types=1);

/**
 * Phase 0 — connection check.
 *
 * Proves we can talk to eBay for an account end-to-end:
 *   1. mint an application token (client-credentials)      -> app keys valid
 *   2. Taxonomy getDefaultCategoryTreeId (EBAY_US)          -> Taxonomy REST reachable
 *   3. mint a user token (refresh-token grant)             -> per-account refresh token valid
 *   4. Trading GeteBayOfficialTime                         -> Trading + OAuth IAF token valid
 *
 * Usage:
 *   php ebay/scripts/check_connection.php --account=dows
 *   php ebay/scripts/check_connection.php --account=ige --mode=sandbox
 *   php ebay/scripts/check_connection.php --all
 *
 * Read-only. No writes to any listing. Exit code 0 only if every requested
 * account passes every step.
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

$options = getopt('', ['account:', 'mode:', 'all', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php check_connection.php [--account=dows|ige] [--mode=production|sandbox] [--all]\n");
    exit(0);
}

$mode = $options['mode'] ?? null;
$accounts = isset($options['all'])
    ? ['dows', 'ige']
    : [strtolower((string) ($options['account'] ?? 'dows'))];

$allPassed = true;

foreach ($accounts as $account) {
    $allPassed = checkAccount($account, $mode) && $allPassed;
}

echo "\n";
echo $allPassed
    ? "All requested accounts connected.\n"
    : "One or more accounts failed — see above.\n";

exit($allPassed ? 0 : 1);

/**
 * Run the four-step check for one account. Returns true only if all steps pass.
 */
function checkAccount(string $account, ?string $mode): bool
{
    try {
        $client = new EbayClient($account, $mode);
    } catch (Throwable $e) {
        line($account, '(setup)', false, $e->getMessage());
        return false;
    }

    $envLabel = $client->isSandbox() ? 'SANDBOX' : 'PRODUCTION';
    echo "\n=== {$account} ({$envLabel}) ===\n";

    if (!$client->hasAppCreds()) {
        line($account, 'app token', false, 'no app credentials in .env (skipping remaining steps)');
        return false;
    }

    $ok = true;

    // 1 + 2: app token -> Taxonomy
    try {
        $treeId = $client->defaultCategoryTreeId('EBAY_US');
        line($account, 'app token', true, 'minted');
        line($account, 'taxonomy', true, "EBAY_US category tree id = {$treeId}");
    } catch (Throwable $e) {
        line($account, 'taxonomy', false, $e->getMessage());
        $ok = false;
    }

    // 3 + 4: user token -> REST identity (whoAmI).
    // We verify the user token with a modern REST call rather than the legacy
    // Trading XML endpoint, which eBay's Akamai edge blocks from some networks.
    if (!$client->hasUserCreds()) {
        line($account, 'user token', false, 'no refresh token / user token in .env (Sell APIs unavailable)');
        return false;
    }
    try {
        $who = $client->whoAmI();
        line($account, 'user token', true, 'minted');
        line($account, 'identity', true, "connected as {$who}");
    } catch (Throwable $e) {
        line($account, 'identity', false, $e->getMessage());
        $ok = false;
    }

    return $ok;
}

/**
 * Print a single aligned status line.
 */
function line(string $account, string $step, bool $ok, string $detail): void
{
    $mark = $ok ? '✓' : '✗';
    fwrite(
        $ok ? STDOUT : STDERR,
        sprintf("  %s %-11s %s\n", $mark, $step, $detail)
    );
}
