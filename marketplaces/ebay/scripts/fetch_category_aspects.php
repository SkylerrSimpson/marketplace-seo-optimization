<?php

declare(strict_types=1);

/**
 * PHASE 4 — cache the eBay aspect SCHEMA for every leaf category our listings
 * use. For each category_id, Taxonomy getItemAspectsForCategory tells us what
 * Item Specifics that category supports: which are required/recommended, whether
 * the value is free text or must come from a fixed list, single vs multi, and
 * the allowed-value list for SELECTION_ONLY aspects.
 *
 * Aspect schemas are MARKETPLACE-level (EBAY_US) and fetched with the APP token,
 * so they're identical for DOWS and IGE — one pass over the union of both
 * accounts' category_coverage.csv covers everything. (Defaults to the union;
 * --account=dows|ige restricts to one account's categories.)
 *
 * Output (committed, reviewable — eBay analog of the Amazon product-type schemas):
 *   marketplaces/ebay/data/aspects/{categoryId}.json   normalized schema for that category
 *   marketplaces/ebay/data/aspects/_index.csv          per-category required/total aspect counts
 *
 * Read-only. Idempotent: skips categories already cached unless --refresh.
 *
 * Usage:
 *   php marketplaces/ebay/scripts/fetch_category_aspects.php --limit=5      # canary
 *   php marketplaces/ebay/scripts/fetch_category_aspects.php                # full (union)
 *   php marketplaces/ebay/scripts/fetch_category_aspects.php --refresh
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Taxonomy\Types\GetItemAspectsForCategoryRestRequest;

$opts = getopt('', ['account:', 'limit:', 'ids:', 'refresh', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php fetch_category_aspects.php [--account=dows|ige] [--limit=N] [--ids=ID,ID] [--refresh]\n");
    exit(0);
}
$account = isset($opts['account']) ? strtolower((string) $opts['account']) : null;
$limit   = isset($opts['limit']) ? (int) $opts['limit'] : null;
$refresh = isset($opts['refresh']);
$onlyIds = isset($opts['ids']) ? array_filter(array_map('trim', explode(',', (string) $opts['ids']))) : null;

if (!is_dir(EBAY_ASPECTS)) { mkdir(EBAY_ASPECTS, 0775, true); }

// --- gather the distinct category ids from coverage files ----------------------
$accounts = $account !== null ? [$account] : ['dows', 'ige'];
$catIds = [];
foreach ($accounts as $acct) {
    $cov = ebay_dir($acct, 'output') . '/category_coverage.csv';
    if (!is_file($cov)) {
        fwrite(STDERR, "Missing {$cov}. Run enrich_listings.php --account={$acct} first.\n");
        continue;
    }
    $fh = fopen($cov, 'r'); fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        $id = trim((string) ($r[0] ?? ''));
        if ($id !== '' && ctype_digit($id)) { $catIds[$id] = true; }
    }
    fclose($fh);
}
$catIds = array_keys($catIds);
if ($onlyIds !== null) { $catIds = array_values(array_intersect($catIds, $onlyIds)); }
sort($catIds, SORT_STRING);
if ($limit !== null) { $catIds = array_slice($catIds, 0, $limit); }

if ($catIds === []) { fwrite(STDERR, "No category ids to fetch.\n"); exit(1); }

// Taxonomy is app-level + marketplace-scoped; account choice is irrelevant to the
// result. Use dows for the app token unless one was named.
$client = new EbayClient($account ?? 'dows');
$treeId = $client->defaultCategoryTreeId('EBAY_US');   // "0" for EBAY_US
$taxonomy = $client->taxonomy('EBAY_US');

echo "=== Phase 4: fetch aspect schemas (tree {$treeId}) ===\n";
echo "categories to fetch: " . count($catIds) . ($refresh ? " (refresh)" : " (resumable)") . "\n";

$fetched = 0; $skipped = 0; $errors = 0; $index = [];
foreach ($catIds as $i => $catId) {
    $catId = (string) $catId;   // numeric-string keys come back as ints
    $path = EBAY_ASPECTS . "/{$catId}.json";

    if (!$refresh && is_file($path)) {
        $schema = json_decode((string) file_get_contents($path), true) ?: [];
        $skipped++;
    } else {
        $schema = fetchSchema($taxonomy, $treeId, $catId);
        if (isset($schema['__error'])) {
            fwrite(STDERR, "  [ERR] {$catId}: {$schema['__error']}\n");
            $errors++;
            continue;
        }
        file_put_contents($path, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $fetched++;
        usleep(150000);
    }

    $req = count(array_filter($schema['aspects'] ?? [], fn($a) => $a['required']));
    $sel = count(array_filter($schema['aspects'] ?? [], fn($a) => $a['mode'] === 'SELECTION_ONLY'));
    $index[$catId] = ['total' => count($schema['aspects'] ?? []), 'required' => $req, 'selection_only' => $sel];

    if (($i + 1) % 5 === 0) { echo "  " . ($i + 1) . "/" . count($catIds) . " (fetched {$fetched}, skipped {$skipped}, err {$errors})\n"; }
}

// --- index roll-up -------------------------------------------------------------
$idxPath = EBAY_ASPECTS . '/_index.csv';
$fh = fopen($idxPath, 'w');
fputcsv($fh, ['category_id', 'total_aspects', 'required_aspects', 'selection_only_aspects']);
ksort($index);
foreach ($index as $cid => $c) { fputcsv($fh, [$cid, $c['total'], $c['required'], $c['selection_only']]); }
fclose($fh);

echo "\n========================================\n";
echo "fetched {$fetched}, skipped(cached) {$skipped}, errors {$errors}\n";
echo "  schemas: " . EBAY_ASPECTS . "/{categoryId}.json\n  index: {$idxPath}\n";

// --- helpers -------------------------------------------------------------------

/**
 * @return array{category_id:string,aspect_count:int,aspects:list<array{name:string,required:bool,mode:string,data_type:string,cardinality:string,for_variations:bool,values:list<string>}>}|array{__error:string}
 */
function fetchSchema($taxonomy, string $treeId, string $catId): array
{
    $req = new GetItemAspectsForCategoryRestRequest();
    $req->category_tree_id = $treeId;
    $req->category_id      = $catId;

    $attempts = 0;
    while (true) {
        $attempts++;
        try {
            $res = $taxonomy->getItemAspectsForCategory($req);
        } catch (\Throwable $e) {
            if ($attempts < 6) { sleep(min(2 * $attempts, 10)); continue; }
            return ['__error' => 'HTTP: ' . $e->getMessage()];
        }
        if (count($res->errors) > 0) {
            $msgs = [];
            foreach ($res->errors as $er) { $msgs[] = '[' . ($er->errorId ?? '?') . '] ' . ($er->message ?? ''); }
            return ['__error' => implode(' | ', $msgs)];
        }
        break;
    }

    $aspects = [];
    foreach ($res->aspects as $a) {
        $c = $a->aspectConstraint;
        $values = [];
        foreach ($a->aspectValues ?? [] as $v) {
            $lv = (string) ($v->localizedValue ?? '');
            if ($lv !== '') { $values[] = $lv; }
        }
        $aspects[] = [
            'name'           => (string) $a->localizedAspectName,
            'required'       => (bool) ($c->aspectRequired ?? false),
            'mode'           => (string) ($c->aspectMode ?? ''),               // FREE_TEXT | SELECTION_ONLY
            'data_type'      => (string) ($c->aspectDataType ?? ''),           // STRING | NUMBER | DATE ...
            'cardinality'    => (string) ($c->itemToAspectCardinality ?? ''),  // SINGLE | MULTI
            'for_variations' => (bool) ($c->aspectEnabledForVariations ?? false),
            'values'         => $values,
        ];
    }
    return ['category_id' => $catId, 'aspect_count' => count($aspects), 'aspects' => $aspects];
}
