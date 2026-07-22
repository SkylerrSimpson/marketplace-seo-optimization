<?php

declare(strict_types=1);

/**
 * fetch_all_category_upc_support.php — a list of
 * ALL eBay US leaf category IDs (not just ones DOWS/IGE currently list in), with a
 * true/false column for whether UPC is actually REQUIRED (the red-star, must-fill
 * behavior) — not just whether it's in the "essential" field bucket at all.
 *
 * "Essential" (upc_support != DISABLED, i.e. ENABLED or REQUIRED) means UPC CAN be
 * set on ProductListingDetails for that category — a real, catalog-facing field —
 * as opposed to DISABLED, where the only place UPC can live is a custom ItemSpecific.
 * NOTE: despite the earlier docblock wording here, "required"
 * in his usage means "essential category", not eBay's stricter REQUIRED-only tier —
 * he knows not every essential category is actually mandatory. So this output
 * collapses ENABLED+REQUIRED into one YES/NO essential flag, not a 3-way split.
 * category_name is included as a convenience even though he has his own full
 * category-tree list already — costs nothing since we cache it locally anyway.
 *
 * Category tree: Taxonomy getACategoryTree (one call, ~15k leaf categories for
 * EBAY_US), cached locally since the full tree rarely changes and re-pulling it
 * is wasted work. --refresh forces a re-pull.
 *
 * Output: ebay/data/aspects/all_categories_upc_support.csv (shared, not account-specific
 *   — same reasoning as ebay/data/aspects/{cat}.json: this is a marketplace-level fact,
 *   not a DOWS/IGE fact)
 *   category_id, category_name, upc_essential (YES|NO)
 *
 * Usage: php ebay/scripts/fetch_all_category_upc_support.php [--refresh] [--limit=N]
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/EbayClient.php';

use DTS\eBaySDK\Taxonomy\Types\GetACategoryTreeRestRequest;

$opts    = getopt('', ['refresh', 'limit:']);
$refresh = isset($opts['refresh']);
$limit   = isset($opts['limit']) ? (int) $opts['limit'] : null;

$treeCache = EBAY_ASPECTS . '/_full_category_tree_leaves.json';
$client = new EbayClient('dows'); // marketplace-level data — account choice doesn't matter

if (!$refresh && is_file($treeCache)) {
    $leaves = json_decode((string) file_get_contents($treeCache), true);
    echo 'loaded ' . count($leaves) . " cached leaf categories from {$treeCache}\n";
} else {
    $req = new GetACategoryTreeRestRequest();
    $req->category_tree_id = '0'; // EBAY_US
    $res = $client->taxonomy()->getACategoryTree($req);
    if (count($res->errors ?? []) > 0) {
        fwrite(STDERR, "Taxonomy error fetching category tree\n");
        exit(1);
    }
    $leaves = [];
    $walk = function ($node) use (&$walk, &$leaves) {
        if ($node->leafCategoryTreeNode ?? false) {
            $leaves[] = [(string) $node->category->categoryId, (string) $node->category->categoryName];
        }
        foreach ($node->childCategoryTreeNodes ?? [] as $child) { $walk($child); }
    };
    $walk($res->rootCategoryNode);
    file_put_contents($treeCache, json_encode($leaves));
    echo 'fetched and cached ' . count($leaves) . " leaf categories -> {$treeCache}\n";
}

if ($limit !== null) { $leaves = array_slice($leaves, 0, $limit); }

$out = EBAY_ASPECTS . '/all_categories_upc_support.csv';
$fh = fopen($out, 'w');
fputcsv($fh, ['category_id', 'category_name', 'upc_essential']);

$batchSize = 40;
$stat = ['DISABLED' => 0, 'ENABLED' => 0, 'REQUIRED' => 0, 'unknown' => 0];
$done = 0;
for ($i = 0; $i < count($leaves); $i += $batchSize) {
    $batch = array_slice($leaves, $i, $batchSize);
    $ids = array_column($batch, 0);
    $names = array_combine($ids, array_column($batch, 1));
    $filter = 'categoryIds:%7B' . implode(',', $ids) . '%7D';
    $url = "https://api.ebay.com/sell/metadata/v1/marketplace/EBAY_US/get_category_policies?filter={$filter}";
    $res = $client->userSend('GET', $url, null, ['Accept' => 'application/json']);

    $found = [];
    if ($res['status'] === 200 && is_array($res['json'])) {
        foreach ($res['json']['categoryPolicies'] ?? [] as $cp) {
            $found[(string) $cp['categoryId']] = $cp['upcSupport'] ?? '';
        }
    } else {
        fwrite(STDERR, "  batch starting at {$ids[0]}: HTTP {$res['status']}\n");
    }

    foreach ($ids as $id) {
        $support = $found[$id] ?? 'unknown';
        $essential = in_array($support, ['ENABLED', 'REQUIRED'], true) ? 'YES' : 'NO';
        fputcsv($fh, [$id, $names[$id], $essential]);
        $stat[$support] = ($stat[$support] ?? 0) + 1;
    }

    $done += count($batch);
    // One line per completed batch, not every ~10th — the batch itself (one
    // API call per $batchSize category IDs) is the real efficiency-relevant
    // unit; printing every batch costs nothing extra over printing every
    // 10th, since the network call cadence is unchanged either way.
    fwrite(STDOUT, "  {$done}/" . count($leaves) . "\n");
    usleep(300000);
}
fclose($fh);

printf(
    "\ndone: %d categories -> %s\n  DISABLED: %d\n  ENABLED: %d\n  REQUIRED: %d\n  unknown: %d\n",
    count($leaves), $out, $stat['DISABLED'], $stat['ENABLED'], $stat['REQUIRED'], $stat['unknown']
);
