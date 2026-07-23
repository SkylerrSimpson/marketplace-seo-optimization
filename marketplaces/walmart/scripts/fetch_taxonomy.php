<?php

declare(strict_types=1);

/**
 * fetch_taxonomy.php — dump Walmart's category/attribute schema (the item-aspects
 * equivalent of eBay's Taxonomy category-aspect cache). Tells us which attributes
 * exist per category/subcategory, so the aspects audit can distinguish "blank because
 * not applicable to this category" from "blank because it's missing" — same signal
 * eBay's aspect work depends on.
 *
 * getTaxonomyResponse() takes no params — it returns the seller's full category tree
 * in one call, not paginated per-category like eBay's Taxonomy API.
 *
 * Output: marketplaces/walmart/data/{country}/output/taxonomy_{country}.json (raw payload, for
 * scripts to consume — not meant to be hand-edited).
 *
 * Usage: php marketplaces/walmart/scripts/fetch_taxonomy.php --country=us
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/lib/WalmartClient.php';

$opts    = getopt('', ['country:']);
$country = strtolower((string) ($opts['country'] ?? 'us'));

$client = new WalmartClient($country);
$items  = $client->marketplace()->items();

try {
    $response = $items->getTaxonomyResponse();
} catch (\Throwable $e) {
    fwrite(STDERR, "EXCEPTION {$e->getMessage()}\n");
    exit(1);
}

$categories = [];
foreach ($response->getPayload() ?? [] as $cat) {
    $subcats = [];
    foreach ($cat->getSubcategory() ?? [] as $sub) {
        $subcats[] = json_decode((string) json_encode($sub), true);
    }
    $categories[] = [
        'category'    => (string) $cat->getCategory(),
        'subcategory' => $subcats,
    ];
}

$out = walmart_dir($country, 'output') . "/taxonomy_{$country}.json";
file_put_contents($out, json_encode([
    'status'     => (string) $response->getStatus(),
    'categories' => $categories,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf("done: %d categories -> %s\n", count($categories), $out);
