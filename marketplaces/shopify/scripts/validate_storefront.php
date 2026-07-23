<?php

declare(strict_types=1);

/**
 * Phase 4 — downstream storefront validation.
 *
 * Fetches the PUBLIC product pages (raw HTML, not via a markdown converter, so
 * <script> JSON-LD survives) and verifies what actually renders to crawlers /
 * AI agents matches what we applied:
 *   - <meta name="description">      == our new_meta_description (exact)
 *   - og:description                 == our new_meta_description (exact)  [social/AI]
 *   - Product JSON-LD present         (structured data — the key GEO lever)
 *   - our new image alt text appears  (featured image alt rendered)
 *
 * Read-only. Pulls handles from the Admin API, then curls the storefront.
 *
 * Prerequisites: data/output/phase2_output.json (from assemble_output.php) must
 * exist, and the changes it describes must have already been applied and published.
 *
 * Usage:
 *   php marketplaces/shopify/scripts/validate_storefront.php           # sample (~12 spread across catalog)
 *   php marketplaces/shopify/scripts/validate_storefront.php --all     # every product (slower)
 *   php marketplaces/shopify/scripts/validate_storefront.php --ids=ID,ID
 */

require __DIR__ . '/../../lib/bootstrap.php';

use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Shopify\Clients\Graphql;

$doAll = in_array('--all', $argv, true);
$ids = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--ids=')) {
        $ids = array_map('strval', array_filter(array_map('trim', explode(',', substr($a, 6)))));
    }
}

$shopDomain  = $_ENV['SHOP_DOMAIN']     ?? '';
$accessToken = $_ENV['ADMIN_API_TOKEN'] ?? '';
$apiVersion  = $_ENV['API_VERSION']     ?? '2026-04';

$intended = json_decode((string) file_get_contents(SHOPIFY_OUTPUT . '/phase2_output.json'), true);
$want = [];
foreach ($intended as $r) {
    $want[(string) $r['numeric_id']] = [
        'desc' => trim((string) ($r['new_meta_description'] ?? '')),
        'alt'  => trim((string) ($r['new_image_alt'] ?? '')),
        'title' => (string) ($r['title'] ?? ''),
    ];
}

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

// Pull id -> {handle, url} for the whole catalog (paginated).
echo "Resolving storefront URLs...\n";
$handles = [];
$liveUrls = [];
$cursor = null;
do {
    $after = $cursor ? ", after: \"{$cursor}\"" : '';
    $q = "query { products(first: 100{$after}) { pageInfo { hasNextPage endCursor } nodes { id handle onlineStoreUrl } } }";
    $page = $client->query(['query' => $q])->getDecodedBody()['data']['products'];
    foreach ($page['nodes'] as $n) {
        $numeric = preg_replace('/\D/', '', (string) $n['id']);
        $handles[$numeric] = (string) $n['handle'];
        if (!empty($n['onlineStoreUrl'])) {
            $liveUrls[$numeric] = (string) $n['onlineStoreUrl'];
        }
    }
    $cursor = $page['pageInfo']['hasNextPage'] ? $page['pageInfo']['endCursor'] : null;
} while ($cursor);

// Derive the real storefront base (e.g. https://asroutdoor.com) from any product
// that has a published onlineStoreUrl — never the bogus myshopify fallback.
$base = '';
foreach ($liveUrls as $u) {
    $p = parse_url($u);
    if (!empty($p['scheme']) && !empty($p['host'])) { $base = $p['scheme'] . '://' . $p['host']; break; }
}
if ($base === '') { $base = 'https://' . $shopDomain; } // last resort
echo "Storefront base: {$base}\n";

// Build a URL for every product from the derived base + handle.
$urls = [];
foreach ($handles as $id => $h) {
    $urls[$id] = $liveUrls[$id] ?? ($base . '/products/' . $h);
}

// Decide which products to test.
$allIds = array_keys($want);
if (!empty($ids)) {
    $targets = $ids;
} elseif ($doAll) {
    $targets = $allIds;
} else {
    // Representative sample: the 3 canaries + an even spread across the catalog.
    $sample = ['8521682682156', '9512980218156', '9516229722412'];
    $step = max(1, intdiv(count($allIds), 9));
    for ($i = 0; $i < count($allIds); $i += $step) {
        $sample[] = $allIds[$i];
    }
    $targets = array_values(array_unique($sample));
}

echo "Validating " . count($targets) . " product page(s) on the live storefront\n";
echo str_repeat('-', 70) . "\n";

// Polite fetch: throttled by the caller, with retries/backoff on non-200 (the
// live store rate-limits aggressive crawling, so we go slow and back off).
function fetchHtml(string $url): ?string
{
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($html !== false && $code === 200) {
            return (string) $html;
        }
        if ($code === 429 || $code >= 500) {
            sleep(2 * $attempt); // back off on rate-limit / server error and retry
            continue;
        }
        return null; // 404 etc. — don't retry
    }
    return null;
}

function metaContent(string $html, string $attr, string $val): ?string
{
    // match <meta ... attr="val" ... content="X"> in either attribute order
    if (preg_match('/<meta[^>]*\b' . preg_quote($attr, '/') . '="' . preg_quote($val, '/') . '"[^>]*\bcontent="([^"]*)"/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
    if (preg_match('/<meta[^>]*\bcontent="([^"]*)"[^>]*\b' . preg_quote($attr, '/') . '="' . preg_quote($val, '/') . '"/i', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
    return null;
}

function hasProductJsonLd(string $html): bool
{
    if (preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#is', $html, $mm)) {
        foreach ($mm[1] as $block) {
            if (stripos($block, '"Product"') !== false && stripos($block, '"@type"') !== false) {
                return true;
            }
        }
    }
    return false;
}

$pass = 0; $fail = 0;
$cMetaOk = 0; $cOgOk = 0; $cLdOk = 0; $cAltOk = 0;

foreach ($targets as $id) {
    $w = $want[$id] ?? null;
    $url = $urls[$id] ?? null;
    if (!$w || !$url) {
        echo "  SKIP {$id} (no intended data or URL)\n";
        continue;
    }
    usleep(700000); // ~0.7s between requests — polite, avoids bot mitigation
    $html = fetchHtml($url);
    if ($html === null) {
        echo "  FAIL {$id} — could not fetch {$url}\n";
        $fail++;
        continue;
    }

    $meta = metaContent($html, 'name', 'description');
    $og   = metaContent($html, 'property', 'og:description');
    $ld   = hasProductJsonLd($html);
    $altPresent = $w['alt'] !== '' && (
        str_contains($html, 'alt="' . htmlspecialchars($w['alt'], ENT_QUOTES) . '"')
        || str_contains($html, $w['alt'])
    );

    $metaOk = ($meta !== null && trim($meta) === $w['desc']);
    $ogOk   = ($og !== null && trim($og) === $w['desc']);

    if ($metaOk) $cMetaOk++;
    if ($ogOk)   $cOgOk++;
    if ($ld)     $cLdOk++;
    if ($altPresent) $cAltOk++;

    $rowOk = $metaOk && $ld; // meta + structured data are the must-haves
    $rowOk ? $pass++ : $fail++;

    printf("  %s %-15s meta:%s og:%s json-ld:%s alt:%s\n",
        $rowOk ? 'PASS' : 'FAIL',
        $id,
        $metaOk ? 'OK' : 'X',
        $ogOk ? 'OK' : 'X',
        $ld ? 'OK' : 'X',
        $altPresent ? 'OK' : 'X'
    );
    if (!$metaOk) {
        echo "       meta got: " . ($meta === null ? '(none)' : $meta) . "\n";
    }
}

$tot = $pass + $fail;
echo str_repeat('-', 70) . "\n";
echo "Pages tested:            {$tot}\n";
echo "meta description match:  {$cMetaOk}/{$tot}\n";
echo "og:description match:    {$cOgOk}/{$tot}\n";
echo "Product JSON-LD present: {$cLdOk}/{$tot}\n";
echo "image alt rendered:      {$cAltOk}/{$tot}\n";
echo str_repeat('-', 70) . "\n";
echo ($fail === 0)
    ? "RESULT: Storefront PASS — meta + structured data render correctly.\n"
    : "RESULT: {$fail} page(s) need attention — see above.\n";

exit($fail === 0 ? 0 : 1);
