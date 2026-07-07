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