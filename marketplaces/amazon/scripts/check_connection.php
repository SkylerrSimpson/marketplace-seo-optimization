<?php

declare(strict_types=1);

/**
 * Phase 0 — SP-API connectivity probe (READ ONLY).
 *
 * Verifies that the Amazon credentials in .env wire up correctly by making a
 * lightweight catalog search against the configured endpoint (sandbox by default).
 *
 * Usage:
 *   php marketplaces/amazon/scripts/check_connection.php [--account=IGE|DOWS]
 *
 * Flags:
 *   --account=IGE|DOWS   Seller account to test. Default: IGE.
 *   --help               Show this help message.
 *
 * .env keys (Amazon block):
 *   AMAZON_SPAPI_CLIENT_ID=
 *   AMAZON_SPAPI_CLIENT_SECRET=
 *   AMAZON_SPAPI_REFRESH_TOKEN=
 *   AMAZON_SPAPI_SELLER_ID=
 *   AMAZON_SPAPI_SELLER_ID_DOWS=
 *   AMAZON_SPAPI_REFRESH_TOKEN_DOWS=   (optional — falls back to base refresh token)
 *   AMAZON_SPAPI_REGION=NA
 *   AMAZON_SPAPI_MARKETPLACE_ID=ATVPDKIKX0DER
 *   AMAZON_SPAPI_SANDBOX=true
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/AmazonClient.php';

$account = 'IGE';
foreach ($argv ?? [] as $arg) {
    if ($arg === '--help') {
        echo <<<'HELP'
Usage: php marketplaces/amazon/scripts/check_connection.php [--account=IGE|DOWS]

Flags:
  --account=IGE|DOWS   Seller account to test. Default: IGE.
  --help               Show this help message.
HELP;
        echo PHP_EOL;
        exit(0);
    }
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    }
}

$amazon = new AmazonClient($account);

echo 'Account   : ' . $amazon->account . PHP_EOL;
echo 'Endpoint  : ' . ($amazon->sandbox ? 'SANDBOX' : 'PRODUCTION') . PHP_EOL;
echo 'Marketplace: ' . $amazon->marketplaceId . PHP_EOL;
echo 'Seller ID  : ' . ($amazon->sellerId ?: '(not set)') . PHP_EOL;
echo 'Testing connection... ';

$result = $amazon->testConnection();

if ($result === true) {
    echo 'OK' . PHP_EOL;
    exit(0);
}

echo 'FAILED' . PHP_EOL;
foreach ($result as $error) {
    fwrite(STDERR, '  ' . $error . PHP_EOL);
}
exit(1);
