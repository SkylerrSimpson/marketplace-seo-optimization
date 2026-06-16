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
