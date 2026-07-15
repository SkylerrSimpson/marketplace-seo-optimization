<?php

declare(strict_types=1);

/**
 * Pull a clean, human-reviewable CSV of the SEO / Google Shopping fields straight
 * from the live Shopify store (source of truth — NOT a CSV export, which only
 * shows product-level legacy columns and hides the variant-level data).
 *
 * One row per VARIANT (that's the grain where barcode/GTIN, MPN, gender,
 * age_group, condition, size_system, size_type all live). Product-level fields
 * (SEO title/description, GPC) are repeated on each of the product's variant rows.
 *
 * Columns:
 *   handle, product_title, variant_title, sku, gtin_barcode, mpn,
 *   seo_title, seo_description, gpc (google_product_category),
 *   gender, age_group, condition, size_system, size_type
 *
 * Read-only. Output: shopify/data/output/seo_review_live.csv
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$shopDomain  = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion  = $_ENV['API_VERSION']     ?? '2026-04';
if ($shopDomain === '' || $accessToken === '') { fwrite(STDERR, "Missing SHOP_DOMAIN/ADMIN_API_TOKEN\n"); exit(1); }

Context::initialize(
    apiKey: $_ENV['APP_API_KEY'] ?? 'custom-app',
    apiSecretKey: $_ENV['APP_API_SECRET'] ?? 'custom-app',
    scopes: ['read_products'],
    hostName: $shopDomain,
    sessionStorage: new FileSessionStorage('/tmp/php_sessions'),
    apiVersion: $apiVersion,
    isEmbeddedApp: false,
);
$client = new Graphql($shopDomain, $accessToken);

function gql(Graphql $client, string $query, array $vars): array
{
    $attempts = 0;
    while (true) {
        $attempts++;
        try {
            $body = $client->query(['query' => $query, 'variables' => $vars])->getDecodedBody();
        } catch (\Throwable $e) {
            if ($attempts < 8) { sleep(min(2 * $attempts, 10)); continue; }
            fwrite(STDERR, "HTTP: " . $e->getMessage() . "\n"); exit(1);
        }
        if (isset($body['errors'])) {
            $throttled = false;
            foreach ($body['errors'] as $e) { if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; } }
            if ($throttled && $attempts < 8) { sleep(2 * $attempts); continue; }
            fwrite(STDERR, "GraphQL: " . json_encode($body['errors']) . "\n"); exit(1);
        }
        return $body['data'] ?? [];
    }
}

const QUERY = <<<'GQL'
query($after:String){
  products(first:25, after:$after){
    pageInfo{ hasNextPage endCursor }
    nodes{
      title handle
      seo{ title description }
      gpc:metafield(namespace:"mm-google-shopping",key:"google_product_category"){ value }
      variants(first:100){
        nodes{
          title sku barcode
          mpn:metafield(namespace:"mm-google-shopping",key:"mpn"){ value }
          gender:metafield(namespace:"mm-google-shopping",key:"gender"){ value }
          age_group:metafield(namespace:"mm-google-shopping",key:"age_group"){ value }
          condition:metafield(namespace:"mm-google-shopping",key:"condition"){ value }
          size_system:metafield(namespace:"mm-google-shopping",key:"size_system"){ value }
          size_type:metafield(namespace:"mm-google-shopping",key:"size_type"){ value }
        }
      }
    }
  }
}
GQL;

$outPath = SHOPIFY_DATA . '/output/seo_review_live.csv';
$fh = fopen($outPath, 'w');
fputcsv($fh, [
    'handle', 'product_title', 'variant_title', 'sku', 'gtin_barcode', 'mpn',
    'seo_title', 'seo_description', 'gpc',
    'gender', 'age_group', 'condition', 'size_system', 'size_type',
]);

$after = null; $products = 0; $variants = 0;
do {
    $d = gql($client, QUERY, ['after' => $after]);
    foreach ($d['products']['nodes'] as $p) {
        $products++;
        $seoT = (string) ($p['seo']['title'] ?? '');
        $seoD = (string) ($p['seo']['description'] ?? '');
        $gpc  = (string) ($p['gpc']['value'] ?? '');
        foreach ($p['variants']['nodes'] as $v) {
            $variants++;
            fputcsv($fh, [
                $p['handle'],
                $p['title'],
                (string) ($v['title'] ?? ''),
                (string) ($v['sku'] ?? ''),
                (string) ($v['barcode'] ?? ''),
                (string) ($v['mpn']['value'] ?? ''),
                $seoT,
                $seoD,
                $gpc,
                (string) ($v['gender']['value'] ?? ''),
                (string) ($v['age_group']['value'] ?? ''),
                (string) ($v['condition']['value'] ?? ''),
                (string) ($v['size_system']['value'] ?? ''),
                (string) ($v['size_type']['value'] ?? ''),
            ]);
        }
    }
    $after = $d['products']['pageInfo']['hasNextPage'] ? $d['products']['pageInfo']['endCursor'] : null;
    usleep(200000);
} while ($after);

fclose($fh);
echo "Wrote {$outPath}\n";
echo "products: {$products}, variant rows: {$variants}\n";
