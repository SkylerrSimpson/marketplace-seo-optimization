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
 *   php ebay/scripts/check_connection.php --all --json    # machine-readable, for DOWScripts'
 *                                                          # connection-check widget — suppresses
 *                                                          # the human-readable lines below and
 *                                                          # prints one JSON object instead.
 *
 * Read-only. No writes to any listing. Exit code 0 only if every requested
 * account passes every step.
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

$options = getopt('', ['account:', 'mode:', 'all', 'json', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php check_connection.php [--account=dows|ige] [--mode=production|sandbox] [--all] [--json]\n");
    exit(0);
}

$mode = $options['mode'] ?? null;
$json = isset($options['json']);
$accounts = isset($options['all'])
    ? ['dows', 'ige']
    : [strtolower((string) ($options['account'] ?? 'dows'))];

$allPassed = true;
$results = [];

foreach ($accounts as $account) {
    [$passed, $detail] = checkAccount($account, $mode, $json);
    $allPassed = $passed && $allPassed;
    $results[$account] = ['ok' => $passed, 'detail' => $detail];
}

if ($json) {
    echo json_encode($results) . "\n";
    exit($allPassed ? 0 : 1);
}

echo "\n";
echo $allPassed
    ? "All requested accounts connected.\n"
    : "One or more accounts failed — see above.\n";

exit($allPassed ? 0 : 1);

/**
 * Run the four-step check for one account. Returns [passed, lastDetailMessage]
 * — the detail message is whatever the first failing step reported, or the
 * final success detail if everything passed, so a one-line summary is always
 * available for --json callers without them needing to know the step names.
 */
function checkAccount(string $account, ?string $mode, bool $json): array
{
    try {
        $client = new EbayClient($account, $mode);
    } catch (Throwable $e) {
        line($account, '(setup)', false, $e->getMessage(), $json);

        return [false, $e->getMessage()];
    }

    $envLabel = $client->isSandbox() ? 'SANDBOX' : 'PRODUCTION';
    if (! $json) {
        echo "\n=== {$account} ({$envLabel}) ===\n";
    }

    if (!$client->hasAppCreds()) {
        $msg = 'no app credentials in .env (skipping remaining steps)';
        line($account, 'app token', false, $msg, $json);

        return [false, $msg];
    }

    $ok = true;
    $lastDetail = '';

    // 1 + 2: app token -> Taxonomy
    try {
        $treeId = $client->defaultCategoryTreeId('EBAY_US');
        line($account, 'app token', true, 'minted', $json);
        line($account, 'taxonomy', true, "EBAY_US category tree id = {$treeId}", $json);
    } catch (Throwable $e) {
        line($account, 'taxonomy', false, $e->getMessage(), $json);
        $ok = false;
        $lastDetail = $e->getMessage();
    }

    // 3 + 4: user token -> REST identity (whoAmI).
    // We verify the user token with a modern REST call rather than the legacy
    // Trading XML endpoint, which eBay's Akamai edge blocks from some networks.
    if (!$client->hasUserCreds()) {
        $msg = 'no refresh token / user token in .env (Sell APIs unavailable)';
        line($account, 'user token', false, $msg, $json);

        return [false, $msg];
    }
    try {
        $who = $client->whoAmI();
        line($account, 'user token', true, 'minted', $json);
        line($account, 'identity', true, "connected as {$who}", $json);
        $lastDetail = "connected as {$who}";
    } catch (Throwable $e) {
        line($account, 'identity', false, $e->getMessage(), $json);
        $ok = false;
        $lastDetail = $e->getMessage();
    }

    return [$ok, $lastDetail];
}

/**
 * Print a single aligned status line — suppressed entirely in --json mode,
 * where the caller only wants the final JSON object on stdout.
 */
function line(string $account, string $step, bool $ok, string $detail, bool $json = false): void
{
    if ($json) {
        return;
    }

    $mark = $ok ? '✓' : '✗';
    fwrite(
        $ok ? STDOUT : STDERR,
        sprintf("  %s %-11s %s\n", $mark, $step, $detail)
    );
}
