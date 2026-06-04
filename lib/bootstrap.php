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
