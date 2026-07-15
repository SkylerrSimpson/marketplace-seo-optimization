<?php

declare(strict_types=1);

/**
 * Shared bootstrap for all marketplace tooling.
 *
 * Each script does:  require __DIR__ . '/../../lib/bootstrap.php';
 *
 * Responsibilities:
 *   - Composer autoload (Shopify SDK, phpdotenv, etc.)
 *   - Load .env from the repo root
 *   - Define path constants so scripts never hard-code data locations
 *
 * Add new marketplaces (amazon/, ebay/, walmart/) with their own data dirs and a
 * parallel set of constants here as they come online.
 */

define('REPO_ROOT', dirname(__DIR__));

require REPO_ROOT . '/vendor/autoload.php';

// Load environment (.env at repo root). safeLoad = don't error if a key is absent.
Dotenv\Dotenv::createImmutable(REPO_ROOT)->safeLoad();

// --- Shopify data directories ---------------------------------------------
define('SHOPIFY_DATA',   REPO_ROOT . '/shopify/data');
define('SHOPIFY_INPUT',  SHOPIFY_DATA . '/input');    // pulled-from-Shopify exports
define('SHOPIFY_DRAFTS', SHOPIFY_DATA . '/drafts');   // authored content (descriptions, alts)
define('SHOPIFY_OUTPUT', SHOPIFY_DATA . '/output');   // assembled review files + audits


// --- Amazon directories ------------------------------------------------------
define('AMAZON_ROOT',    REPO_ROOT . '/amazon');
define('AMAZON_DATA',    AMAZON_ROOT . '/data');
define('AMAZON_SCHEMAS', AMAZON_DATA . '/schemas'); // shared across all accounts

/**
 * Return account-scoped data paths and create directories on first use.
 *
 * Usage:  $paths = amazon_paths('IGE');
 *         $paths = amazon_paths('DOWS');
 *
 * @return array{data:string, input:string, reports:string, listings:string, catalog:string, drafts:string, output:string, drift:string, schemas:string}
 */
function amazon_paths(string $account): array
{
    $base = AMAZON_DATA . '/' . strtolower($account);

    $paths = [
        'data'           => $base,
        'input'          => $base . '/input',
        'reports'        => $base . '/input/reports',
        'listings'       => $base . '/input/listings',
        'catalog'        => $base . '/input/catalog',
        'catalog_errors' => $base . '/input/catalog/errors',
        'usurper'        => $base . '/input/usurper',
        'drafts'         => $base . '/drafts',
        'output'         => $base . '/output',
        'drift'          => $base . '/drift',    // committed drift snapshots (tracked over time)
        'schemas'        => AMAZON_SCHEMAS,
    ];

    foreach ($paths as $key => $path) {
        if ($key !== 'schemas' && !is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    return $paths;
}

// --- eBay data directories -------------------------------------------------
// eBay is PER-ACCOUNT (DOWS, IGE, ...), mirroring Scott's amazon/data/{account}.
// The category-aspect schema cache is SHARED across accounts (committed, reviewable),
// because aspects are a property of the eBay category tree, not of an account.
define('EBAY_DATA',    REPO_ROOT . '/ebay/data');
define('EBAY_ASPECTS', EBAY_DATA . '/aspects');       // cached Taxonomy aspect schemas {catId}.json (committed)

/**
 * Per-account eBay data path. Subdir is one of input|drafts|output.
 *   ebay_dir('dows', 'input')  => REPO_ROOT/ebay/data/dows/input
 * Creates the directory if missing so scripts can write without pre-checks.
 */
function ebay_dir(string $account, string $subdir): string
{
    $account = strtolower(trim($account));
    if (!in_array($subdir, ['input', 'drafts', 'output'], true)) {
        throw new InvalidArgumentException("ebay_dir subdir must be input|drafts|output, got: {$subdir}");
    }
    $path = EBAY_DATA . "/{$account}/{$subdir}";
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    return $path;
}

// --- Walmart data directories -----------------------------------------------
// Walmart is PER-COUNTRY (US, CA — separate seller accounts/credentials), mirroring
// the eBay per-account split. US is the higher-priority marketplace.
define('WALMART_DATA', REPO_ROOT . '/walmart/data');

/**
 * Per-country Walmart data path. Subdir is one of input|drafts|output.
 *   walmart_dir('us', 'input')  => REPO_ROOT/walmart/data/us/input
 * Creates the directory if missing so scripts can write without pre-checks.
 */
function walmart_dir(string $country, string $subdir): string
{
    $country = strtolower(trim($country));
    if (!in_array($country, ['us', 'ca'], true)) {
        throw new InvalidArgumentException("walmart_dir country must be us|ca, got: {$country}");
    }
    if (!in_array($subdir, ['input', 'drafts', 'output'], true)) {
        throw new InvalidArgumentException("walmart_dir subdir must be input|drafts|output, got: {$subdir}");
    }
    $path = WALMART_DATA . "/{$country}/{$subdir}";
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    return $path;
}

