<?php

declare(strict_types=1);

/**
 * Catalog / listing drift snapshot (READ ONLY, no API calls).
 *
 * The raw per-SKU snapshots under input/listings/ and input/catalog/ are NOT
 * committed — they're a re-pullable mirror of Amazon's state, too large and too
 * noisy (per-fetch timestamps, image CDN hashes) to track in git. This script
 * distills them into ONE small, deterministic, committable digest per account so
 * that catalog drift over time is exactly `git diff` / `git log -p` on that file.
 *
 * Design invariant: the snapshot is a pure function of the volatility-stripped
 * inputs. Two runs over identical inputs produce a byte-identical file, so an
 * empty git diff means zero meaningful drift. There is deliberately NO timestamp
 * inside the file — provenance is the git commit itself.
 *
 * Normalization (see VOLATILE_KEYS): volatile keys are dropped everywhere in the
 * tree, associative arrays are key-sorted, list order is preserved (bullet/point
 * order is meaningful, so reordering legitimately shows as drift).
 *
 * Three inline modes trade size against git-visible detail. In ALL modes the
 * per-SKU listing_hash is computed over the FULL normalized listing, so drift in
 * ANY field is always caught (and always shows at least as a changed hash line);
 * the mode only decides how much human-readable content sits next to that hash:
 *
 *   curated (default)  High-signal SEO/compliance fields inline (item_name,
 *                      status, bullet_point, product_description, generic_keyword,
 *                      brand, color/size/material/style, parent_skus, issues).
 *                      git diff shows WHAT changed; small enough to commit at
 *                      DOWS scale.
 *   --full             Every listing attribute inline. Complete but large
 *                      (~88 MB for DOWS) — rarely what you want to commit.
 *   --hashes-only      Per-SKU hashes only. A tripwire: git diff shows WHICH
 *                      SKUs drifted; re-pull input to see what.
 *
 * On each run, if a prior snapshot exists it is diffed BEFORE being overwritten,
 * so you get an added / removed / changed summary without needing git.
 *
 * Usage:
 *   php amazon/scripts/drift_snapshot.php [--account=IGE|DOWS] [OPTIONS]
 *
 * Flags:
 *   --account=IGE|DOWS   Seller account to snapshot. Default: IGE.
 *   --full               Inline every attribute (large). Default is curated.
 *   --hashes-only        Slim manifest: per-SKU hashes only, no inline content.
 *   --help               Show this help message.
 *
 * Inputs (all from disk, no API):
 *   amazon/data/{account}/input/listings/{sku}.json
 *   amazon/data/{account}/input/catalog/{asin}.json (+ catalog/errors/{asin}.json)
 *
 * Output (committed, stable filename — overwritten each run):
 *   amazon/data/{account}/drift/snapshot.json
 */

require __DIR__ . '/../../lib/bootstrap.php';

// ---------------------------------------------------------------------------
// Keys treated as volatile and stripped everywhere before hashing/serializing.
// These churn on every re-pull without a real content change, so including them
// would turn every snapshot into noise.
// ---------------------------------------------------------------------------

const VOLATILE_KEYS = [
    'createdDate',
    'lastUpdatedDate',
    'created_date',
    'last_updated_date',
    'mainImage',     // Amazon-rendered CDN block with hashed filenames
    'images',        // catalog image set — CDN hashes churn; we don't diff which render
    'salesRanks',    // changes daily; not catalog *content* drift
    'salesRank',
];

// High-signal listing attributes surfaced inline in the default (curated) mode.
// item_name is emitted separately (it's universal); these are the customer-facing
// SEO fields plus the variation-defining attributes most worth watching for drift.
const PROJECTED_ATTRS = [
    'brand',
    'bullet_point',
    'product_description',
    'generic_keyword',
    'item_type_keyword',
    'color',
    'size',
    'material',
    'style',
];

// ---------------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/drift_snapshot.php [--account=IGE|DOWS] [OPTIONS]

Flags:
  --account=IGE|DOWS   Seller account to snapshot. Default: IGE.
  --full               Inline every attribute (large). Default is curated.
  --hashes-only        Slim manifest: per-SKU hashes only, no inline content.
  --help               Show this help message.

Read-only. Distills the (uncommitted) input/listings + input/catalog snapshots
into one deterministic, committable digest at drift/snapshot.json. Track catalog
drift over time with `git diff` on that file. The listing_hash always covers the
full listing, so drift is caught in every mode regardless of what's shown inline.
HELP;
    echo PHP_EOL;
    exit(0);
}

$account    = 'IGE';
$hashesOnly = in_array('--hashes-only', $argv ?? [], true);
$fullMode   = in_array('--full', $argv ?? [], true);

$mode = $hashesOnly ? 'hashes-only' : ($fullMode ? 'full' : 'curated');

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    }
}

$paths = amazon_paths($account);

echo 'Account: ' . $account . PHP_EOL;

// ---------------------------------------------------------------------------
// Normalization helpers
// ---------------------------------------------------------------------------

/**
 * Recursively drop volatile keys and key-sort associative arrays. List arrays
 * keep their source order (order is meaningful for bullet points, etc.).
 */
function drift_normalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $out = [];
    foreach ($value as $k => $v) {
        if (is_string($k) && in_array($k, VOLATILE_KEYS, true)) {
            continue;
        }
        $out[$k] = drift_normalize($v);
    }

    if (drift_is_assoc($out)) {
        ksort($out);
    }

    return $out;
}

/** True for associative arrays (and non-empty maps); false for 0..n lists. */
function drift_is_assoc(array $a): bool
{
    if ($a === []) {
        return false;
    }
    return array_keys($a) !== range(0, count($a) - 1);
}

/** Deterministic JSON for hashing — sorted, no pretty-print, stable escaping. */
function drift_canonical(mixed $value): string
{
    return json_encode(
        drift_normalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
}

/**
 * Flatten an Amazon attribute (a list of {value, marketplace_id, language_tag}
 * objects) down to its bare value(s) for readable inline display. Returns a
 * scalar for single-valued attributes, a list for multi-valued ones, or null if
 * the attribute is absent.
 */
function drift_attr_values(array $attributes, string $key): mixed
{
    if (!isset($attributes[$key]) || !is_array($attributes[$key])) {
        return null;
    }

    $values = [];
    foreach ($attributes[$key] as $entry) {
        $values[] = (is_array($entry) && array_key_exists('value', $entry))
            ? $entry['value']
            : $entry;   // non-standard shape — keep as-is rather than lose it
    }

    if ($values === []) {
        return null;
    }

    return count($values) === 1 ? $values[0] : $values;
}

/** Recursively collect every scalar under any occurrence of $key (e.g. parentSkus). */
function drift_collect_key(mixed $node, string $key): array
{
    $out = [];
    if (!is_array($node)) {
        return $out;
    }
    foreach ($node as $k => $v) {
        if ($k === $key && is_array($v)) {
            foreach ($v as $x) {
                if (is_scalar($x)) {
                    $out[] = $x;
                }
            }
        }
        $out = array_merge($out, drift_collect_key($v, $key));
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Step 1: Load the prior snapshot (for the drift summary) before overwriting.
// ---------------------------------------------------------------------------

$snapshotFile = $paths['drift'] . '/snapshot.json';
$prevHashes   = [];   // sku => listing_hash from the previous run

if (file_exists($snapshotFile)) {
    $prev = json_decode(file_get_contents($snapshotFile), true) ?? [];
    foreach ($prev['skus'] ?? [] as $sku => $entry) {
        $prevHashes[$sku] = $entry['listing_hash'] ?? '';
    }
}

// ---------------------------------------------------------------------------
// Step 2: Walk listing files.
// ---------------------------------------------------------------------------

$listingFiles = glob($paths['listings'] . '/*.json') ?: [];

if (!$listingFiles) {
    fwrite(STDERR, "No listing files found in {$paths['listings']}.\n");
    fwrite(STDERR, "Run export_listings_items.php --account={$account} first.\n");
    exit(1);
}

echo 'Listings: ' . count($listingFiles) . PHP_EOL;

// ---------------------------------------------------------------------------
// Step 3: Build one normalized digest entry per SKU.
// ---------------------------------------------------------------------------

$skus = [];

foreach ($listingFiles as $listingFile) {
    $listing     = json_decode(file_get_contents($listingFile), true) ?? [];
    $sku         = $listing['sku'] ?? basename($listingFile, '.json');
    $summaries   = $listing['summaries'] ?? [];
    $summary     = reset($summaries) ?: [];
    $asin        = $summary['asin'] ?? '';
    $productType = $summary['productType'] ?? '';

    $status = $summary['status'] ?? [];
    sort($status);   // scalar list — sort so a reordered status[] isn't false drift

    $issues = array_map(
        fn($i) => ($i['severity'] ?? '') . ':' . ($i['code'] ?? ''),
        $listing['issues'] ?? [],
    );
    sort($issues);

    // The meaningful, hashable listing content (order matters for the hash, so
    // build it explicitly rather than hashing the whole raw file).
    $content = [
        'product_type'  => $productType,
        'status'        => $status,
        'item_name'     => $summary['itemName'] ?? '',
        'attributes'    => drift_normalize($listing['attributes'] ?? []),
        'issues'        => $issues,
        'relationships' => drift_normalize($listing['relationships'] ?? []),
    ];
    $listingHash = hash('sha256', drift_canonical($content));

    // --- Catalog side (Amazon's realized view) ---
    $catalogFile = $paths['catalog']        . '/' . $asin . '.json';
    $errorFile   = $paths['catalog_errors'] . '/' . $asin . '.json';

    if ($asin === '') {
        $catalogStatus = 'error';
        $catalogHash   = null;
    } elseif (file_exists($catalogFile)) {
        $catalog       = json_decode(file_get_contents($catalogFile), true) ?? [];
        $catalogStatus = 'ok';
        $catalogHash   = hash('sha256', drift_canonical($catalog));
    } elseif (file_exists($errorFile)) {
        $catalogStatus = 'error';
        $catalogHash   = null;
    } else {
        $catalogStatus = 'missing';
        $catalogHash   = null;
    }

    // Common header for every mode.
    $entry = [
        'asin'           => $asin,
        'product_type'   => $productType,
    ];

    if ($mode !== 'hashes-only') {
        $attributes = $listing['attributes'] ?? [];

        $entry['status']    = $status;
        $entry['item_name'] = $content['item_name'];

        if ($mode === 'full') {
            $entry['attributes'] = $content['attributes'];
        } else {
            // curated: only the high-signal fields, flattened to bare values.
            foreach (PROJECTED_ATTRS as $k) {
                $v = drift_attr_values($attributes, $k);
                if ($v !== null) {
                    $entry[$k] = $v;
                }
            }
            $parentSkus = array_values(array_unique(
                drift_collect_key($listing['relationships'] ?? [], 'parentSkus'),
            ));
            sort($parentSkus);
            if ($parentSkus !== []) {
                $entry['parent_skus'] = $parentSkus;
            }
        }

        $entry['issues'] = $issues;
    }

    // Hashes + catalog status close out every entry (full-fidelity, mode-agnostic).
    $entry['catalog_status'] = $catalogStatus;
    $entry['listing_hash']   = $listingHash;
    $entry['catalog_hash']   = $catalogHash;

    $skus[$sku] = $entry;
}

// Sort SKUs so the file order is stable regardless of filesystem glob order.
ksort($skus);

// Account-wide fingerprint: a single hash over every per-SKU hash pair. Changes
// only when real content changes, so it's a one-glance "did anything drift" id.
$fingerprintParts = [];
foreach ($skus as $sku => $entry) {
    $fingerprintParts[] = $sku . ':' . $entry['listing_hash'] . ':' . ($entry['catalog_hash'] ?? '');
}
$digest = hash('sha256', implode("\n", $fingerprintParts));

$snapshot = [
    'account'   => $account,
    'mode'      => $mode,
    'sku_count' => count($skus),
    'digest'    => $digest,
    'skus'      => $skus,
];

// ---------------------------------------------------------------------------
// Step 4: Diff against the prior snapshot (added / removed / changed).
// ---------------------------------------------------------------------------

$added   = array_diff(array_keys($skus), array_keys($prevHashes));
$removed = array_diff(array_keys($prevHashes), array_keys($skus));
$changed = [];
foreach ($skus as $sku => $entry) {
    if (isset($prevHashes[$sku]) && $prevHashes[$sku] !== $entry['listing_hash']) {
        $changed[] = $sku;
    }
}

// ---------------------------------------------------------------------------
// Step 5: Write the snapshot.
// ---------------------------------------------------------------------------

file_put_contents(
    $snapshotFile,
    json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo PHP_EOL;
echo 'Mode:      ' . $mode . PHP_EOL;
echo 'SKUs:      ' . count($skus) . PHP_EOL;
echo 'Digest:    ' . substr($digest, 0, 12) . PHP_EOL;

if ($prevHashes === []) {
    echo 'Drift:     baseline (no prior snapshot)' . PHP_EOL;
} else {
    echo 'Drift vs prior snapshot:' . PHP_EOL;
    echo '  added:   ' . count($added) . PHP_EOL;
    echo '  removed: ' . count($removed) . PHP_EOL;
    echo '  changed: ' . count($changed) . PHP_EOL;
    foreach (array_slice($changed, 0, 10) as $sku) {
        echo '    ~ ' . $sku . PHP_EOL;
    }
    if (count($changed) > 10) {
        echo '    … +' . (count($changed) - 10) . ' more' . PHP_EOL;
    }
}

echo PHP_EOL;
echo 'Snapshot → ' . $snapshotFile . PHP_EOL;
echo 'Commit this file to track catalog drift over time (git diff shows what changed).' . PHP_EOL;
