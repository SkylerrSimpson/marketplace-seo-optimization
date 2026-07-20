<?php

declare(strict_types=1);

/**
 * Phase 8 + Phase 10 — Guarded write-back to Amazon via SP-API patchListingsItem.
 *
 * Reads amazon/data/{account}/drafts/{sku}.json (Phase 7 output) and submits
 * the authored attributes to Amazon using the Listings Items PATCH API.
 *
 * Safety model (mirrors Shopify's guarded-write pattern):
 *   --apply is required to send any requests to Amazon. Without it the script
 *   runs in dry-run mode: it shows exactly what WOULD be patched per SKU
 *   (including everything the guards below would hold back) with no API calls.
 *
 * Guards (Phase 10):
 *   1. Identifying guard — attributes that define which product/variation an
 *      ASIN is (variation_theme, item/number quantity, product identifiers, the
 *      product type's variation-theme attrs) are HELD BACK unless the operator
 *      opts in with --include-identifying. A bad identifying write can silently
 *      detach a listing from its ASIN or merge/split a variation family.
 *   2. Shrink-guard — an array-valued attribute (e.g. bullet_point) whose draft
 *      has FEWER entries than the live/snapshot baseline is held back unless
 *      --allow-shrink, so a partial draft can't truncate live content.
 *   3. Compliance hard-block — if a compliance-critical attribute is in scope
 *      for the product type (its schema defines it) but resolves to no value on
 *      the live listing, in the draft, or via a deterministic rule, the ENTIRE
 *      SKU is blocked (nothing is written) unless --skip-compliance-block.
 *
 * Backup (Phase 10): on --apply, the live listing is fetched and written to
 *   data/{account}/backups/{sku}/{timestamp}.json BEFORE any patch, so every
 *   write is reversible via restore_listings.php. That same fetch is the
 *   authoritative baseline for the shrink/compliance guards.
 *
 * Attributes skipped automatically (unchanged from Phase 8):
 *   - value is null (Claude could not determine a value)
 *   - validation_error key is present (Phase 7 detected an invalid enum value)
 *   - review_only is set (the per-provider modular-title candidates)
 *
 * Modular titles (item_name / title_differentiation) are never patched from the
 * draft; with --include-titles the reviewer's chosen values are read from
 * output/title_decisions.csv (Phase 6.5 review) and patched, subject to the
 * coupling rule (item_name <= 75, pair <= 200).
 *
 * Usage:
 *   php amazon/scripts/patch_listings.php [--account=IGE|DOWS] [OPTIONS]
 *
 * Flags:
 *   --account=IGE|DOWS          Seller account. Default: IGE.
 *   --sku=SKU                   Process a single SKU only.
 *   --apply                     Submit patches to Amazon. Required to write.
 *   --include-titles            Patch the reviewed item_name / title_differentiation
 *                               from output/title_decisions.csv. Default: off.
 *   --include-identifying[=a,b] Allow identifying attributes. Bare = allow all;
 *                               =list allows only the named attributes.
 *   --allow-shrink              Allow array attrs shorter than the baseline.
 *   --skip-compliance-block     Do not hard-block SKUs with unresolved
 *                               compliance attributes (controlled/debug runs).
 *   --help                      Show this help message.
 *
 * Inputs:
 *   amazon/data/{account}/drafts/{sku}.json
 *   amazon/data/{account}/output/title_decisions.csv (with --include-titles)
 *   amazon/data/{account}/input/listings/{sku}.json  (dry-run baseline)
 *   amazon/data/schemas/{PRODUCT_TYPE}.json           (identifying + compliance scope)
 *
 * Output:
 *   amazon/data/{account}/output/patch_results_{timestamp}.csv
 *   amazon/data/{account}/backups/{sku}/{timestamp}.json  (on --apply)
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/AmazonClient.php';
require __DIR__ . '/../../lib/AmazonOperationIds.php';
require __DIR__ . '/../../lib/AmazonRateLimits.php';
require __DIR__ . '/../../lib/AmazonPatch.php';
require __DIR__ . '/../../lib/IdentifyingAttributes.php';
require __DIR__ . '/../../lib/ComplianceResolvers.php';

$complianceAttrs = require __DIR__ . '/../../lib/ComplianceAttributes.php';

// Data to pull for the pre-change backup / baseline fetch (mirror Phase 2).
const BACKUP_INCLUDED_DATA = [
    'attributes', 'issues', 'summaries', 'offers',
    'fulfillmentAvailability', 'procurement', 'relationships', 'productTypes',
];

// ---------------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/patch_listings.php [--account=IGE|DOWS] [OPTIONS]

Flags:
  --account=IGE|DOWS          Seller account. Default: IGE.
  --sku=SKU                   Process a single SKU only.
  --apply                     Submit patches to Amazon. Without this flag,
                              dry-run mode shows what would be patched (and what
                              the guards would hold back) but makes no API calls.
  --include-titles            Patch the reviewed item_name / title_differentiation
                              from output/title_decisions.csv. Default: off.
  --include-identifying[=a,b] Allow identifying attributes through the guard.
                              Bare allows ALL; =list allows only those named.
  --allow-shrink              Allow array attributes shorter than the baseline.
  --skip-compliance-block     Do not hard-block SKUs whose compliance-critical
                              attributes are unresolved (controlled/debug runs).
  --help                      Show this help message.

Safety:
  --apply is required to write. Review the dry-run output first.
  Identifying data is held back unless --include-identifying is passed.
  Modular titles are held back unless --include-titles is passed.
  Attributes with null values or validation_error flags are always skipped.

Rate limits:
  patchListingsItem: 5 req/s, burst 10. The script throttles between calls.
HELP;
    echo PHP_EOL;
    exit(0);
}

$account            = 'IGE';
$singleSku          = null;
$apply              = false;
$includeIdentifying = false;      // false | 'all' | array of allowed attr names
$allowShrink        = false;
$skipComplianceBlock = false;
$includeTitles      = false;

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    } elseif (preg_match('/^--sku=(.+)$/', $arg, $m)) {
        $singleSku = $m[1];
    } elseif ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--include-titles') {
        $includeTitles = true;
    } elseif ($arg === '--include-identifying') {
        $includeIdentifying = 'all';
    } elseif (preg_match('/^--include-identifying=(.+)$/', $arg, $m)) {
        $includeIdentifying = array_values(array_filter(array_map(
            fn($s) => strtolower(trim($s)),
            explode(',', $m[1]),
        )));
    } elseif ($arg === '--allow-shrink') {
        $allowShrink = true;
    } elseif ($arg === '--skip-compliance-block') {
        $skipComplianceBlock = true;
    }
}

$paths      = amazon_paths($account);
$schemasDir = $paths['schemas'];

echo 'Account  : ' . $account . PHP_EOL;
if (!$apply) {
    echo '[DRY RUN — no API calls will be made. Pass --apply to write to Amazon.]' . PHP_EOL;
}
echo 'Identifying: ' . (
    $includeIdentifying === 'all'
        ? 'INCLUDED (all)'
        : (is_array($includeIdentifying)
            ? 'INCLUDED (' . implode(', ', $includeIdentifying) . ')'
            : 'held back (default)')
) . PHP_EOL;
if ($allowShrink) {
    echo 'Shrink   : ALLOWED (--allow-shrink)' . PHP_EOL;
}
if ($skipComplianceBlock) {
    echo 'Compliance block: DISABLED (--skip-compliance-block)' . PHP_EOL;
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Load draft files
// ---------------------------------------------------------------------------

$pattern    = $singleSku
    ? $paths['drafts'] . '/' . $singleSku . '.json'
    : $paths['drafts'] . '/*.json';
$draftFiles = glob($pattern) ?: [];

if (!$draftFiles) {
    $loc = $singleSku ? "'{$singleSku}.json'" : 'any drafts';
    echo 'No draft files found (' . $loc . ') in ' . $paths['drafts'] . PHP_EOL;
    echo 'Run draft_listings.php --account=' . $account . ' first.' . PHP_EOL;
    exit(0);
}

echo 'Draft files found : ' . count($draftFiles) . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
// Amazon connector (init early to fail fast on missing credentials)
// ---------------------------------------------------------------------------

$amazon      = null;
$listingsApi = null;

if ($apply) {
    $amazon      = new AmazonClient($account);
    $listingsApi = $amazon->connector->listingsItemsV20210801();
}

// The marketplace ID is known from the connector in apply mode; use the US
// default for dry-run display so the patch envelope preview is accurate.
$marketplaceId = $apply ? $amazon->marketplaceId : ($_ENV['AMAZON_SPAPI_MARKETPLACE_ID'] ?? 'ATVPDKIKX0DER');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Cache of loaded schema `properties` maps, keyed by PRODUCT_TYPE. */
$schemaPropsCache = [];

/** Return a product type's schema `properties` map (or [] if unavailable). */
function schemaProperties(string $productType, string $schemasDir): array
{
    global $schemaPropsCache;
    $productType = strtoupper(trim($productType));
    if ($productType === '') {
        return [];
    }
    if (isset($schemaPropsCache[$productType])) {
        return $schemaPropsCache[$productType];
    }
    $file = rtrim($schemasDir, '/') . '/' . $productType . '.json';
    $props = [];
    if (is_file($file)) {
        $schema = json_decode((string) file_get_contents($file), true);
        if (is_array($schema)) {
            $props = $schema['properties'] ?? [];
        }
    }
    return $schemaPropsCache[$productType] = $props;
}

/** Cache of loaded schema `required` lists, keyed by PRODUCT_TYPE. */
$schemaRequiredCache = [];

/** Return a product type's schema `required` attribute list (or [] if none). */
function schemaRequired(string $productType, string $schemasDir): array
{
    global $schemaRequiredCache;
    $productType = strtoupper(trim($productType));
    if ($productType === '') {
        return [];
    }
    if (isset($schemaRequiredCache[$productType])) {
        return $schemaRequiredCache[$productType];
    }
    $file = rtrim($schemasDir, '/') . '/' . $productType . '.json';
    $req = [];
    if (is_file($file)) {
        $schema = json_decode((string) file_get_contents($file), true);
        if (is_array($schema)) {
            $req = $schema['required'] ?? [];
        }
    }
    return $schemaRequiredCache[$productType] = $req;
}

/**
 * Load the baseline listing for a SKU.
 *   --apply : authoritative live getListingsItem, and write a pre-change backup.
 *   dry-run : the on-disk Phase 2 snapshot (approximate — may be stale).
 * Returns ['listing'=>?array, 'source'=>'live'|'snapshot'|'none', 'backup'=>?string].
 */
function loadBaseline(
    string $sku,
    bool $apply,
    $listingsApi,
    ?AmazonClient $amazon,
    array $paths,
): array {
    if ($apply) {
        $item = AmazonRateLimits::retryWithBackoff(
            fn() => $listingsApi->getListingsItem(
                sellerId:       $amazon->sellerId,
                sku:            $sku,
                marketplaceIds: [$amazon->marketplaceId],
                includedData:   BACKUP_INCLUDED_DATA,
            )->json(),
            AmazonOperationIds::GET_LISTINGS_ITEM,
        );
        AmazonRateLimits::throttle(AmazonOperationIds::GET_LISTINGS_ITEM);

        $dir = $paths['data'] . '/backups/' . $sku;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $backup = $dir . '/' . date('Y-m-d-H-i-s') . '.json';
        file_put_contents($backup, json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['listing' => is_array($item) ? $item : null, 'source' => 'live', 'backup' => $backup];
    }

    $snap = $paths['listings'] . '/' . $sku . '.json';
    if (is_file($snap)) {
        $item = json_decode((string) file_get_contents($snap), true);
        return ['listing' => is_array($item) ? $item : null, 'source' => 'snapshot', 'backup' => null];
    }
    return ['listing' => null, 'source' => 'none', 'backup' => null];
}

/** Count the value slots a baseline listing holds for an attribute (0 if absent). */
function baselineSlotCount(?array $listing, string $attr): int
{
    $slots = $listing['attributes'][$attr] ?? null;
    return is_array($slots) ? count($slots) : 0;
}

/** True if a baseline listing carries a non-empty value for an attribute. */
function baselineHasValue(?array $listing, string $attr): bool
{
    $slots = $listing['attributes'][$attr] ?? null;
    return is_array($slots) && count($slots) > 0;
}

// ---------------------------------------------------------------------------
// Process drafts
// ---------------------------------------------------------------------------

$stats = [
    'accepted'         => 0,
    'with_warnings'    => 0,
    'invalid'          => 0,
    'failed'           => 0,
    'skipped'          => 0,
    'compliance_block' => 0,
];

$results = []; // rows for the results CSV

// Reviewed modular-title decisions (chosen item_name / title_differentiation).
// Only consulted with --include-titles; empty when the reviewer hasn't decided.
$titleDecisions = $includeTitles
    ? \Ige\Amazon\Ai\TitleDecisions::load($paths['output'] . '/title_decisions.csv')
    : [];

foreach ($draftFiles as $draftFile) {
    $draft       = json_decode(file_get_contents($draftFile), true) ?? [];
    $sku         = $draft['sku']          ?? basename($draftFile, '.json');
    $asin        = $draft['asin']         ?? '';
    $productType = $draft['product_type'] ?? '';

    // Collect candidate attributes (name => value), skipping nulls/invalids.
    $candidates   = [];
    $skippedAttrs = [];

    foreach ($draft['attributes'] ?? [] as $attr => $entry) {
        $value = $entry['value'] ?? null;
        if ($value === null) {
            $skippedAttrs[] = $attr . '(null)';
            continue;
        }
        if (isset($entry['validation_error'])) {
            $skippedAttrs[] = $attr . '(invalid)';
            continue;
        }
        // review_only entries (e.g. item_name_ai_suggested) are human-review
        // suggestions, not settable schema attributes — never sent to Amazon.
        if (!empty($entry['review_only'])) {
            $skippedAttrs[] = $attr . '(review-only)';
            continue;
        }
        $candidates[$attr] = $value;
    }

    // Inject the reviewed modular titles (behind --include-titles). The draft only
    // carries per-provider review-only candidates; the chosen final text lives in
    // title_decisions.csv. item_name's 75-char cap is enforced here; the
    // title_differentiation coupling (effective item_name <= 75, pair <= 200) is a
    // guard below, once the live baseline item_name is known.
    $titleSkipped = [];
    if ($includeTitles) {
        $decidedItemName = $titleDecisions[$sku]['item_name'] ?? null;
        if (is_string($decidedItemName) && $decidedItemName !== '') {
            if (mb_strlen($decidedItemName) > \Ige\Amazon\Ai\TitleDecisions::ITEM_NAME_MAX) {
                $titleSkipped[] = 'item_name(>' . \Ige\Amazon\Ai\TitleDecisions::ITEM_NAME_MAX . ')';
            } else {
                $candidates['item_name'] = $decidedItemName;
            }
        }
        $decidedTd = $titleDecisions[$sku]['title_differentiation'] ?? null;
        if (is_string($decidedTd) && $decidedTd !== '') {
            $candidates['title_differentiation'] = $decidedTd; // coupling guard below
        }
    }

    if (!$candidates) {
        echo '[SKIP] ' . $sku . ' — no patchable attributes (all null or invalid)' . PHP_EOL;
        $stats['skipped']++;
        continue;
    }

    // --- Baseline (live fetch + backup on --apply; disk snapshot on dry-run) ---
    try {
        $baseline = loadBaseline($sku, $apply, $listingsApi, $amazon, $paths);
    } catch (\Throwable $e) {
        echo '[SKIP] ' . $sku . ' — baseline fetch failed: ' . $e->getMessage() . PHP_EOL;
        $stats['failed']++;
        $results[] = AmazonPatch::resultRow($sku, $asin, $productType, 0, 'ERROR', '', 0, 'baseline fetch: ' . $e->getMessage())
            + ['skipped_identifying' => '', 'skipped_shrink' => '', 'compliance_block' => ''];
        continue;
    }
    $baseListing = $baseline['listing'];

    echo '[' . $sku . ']' . ($productType ? ' ' . $productType : '') . PHP_EOL;
    if (!$apply && $baseline['source'] !== 'snapshot') {
        echo '  note: no on-disk baseline snapshot — shrink/compliance preview limited' . PHP_EOL;
    } elseif (!$apply) {
        echo '  note: shrink/compliance preview based on possibly-stale snapshot' . PHP_EOL;
    }

    // --- Guard 0: modular-title coupling ---
    // title_differentiation is only valid when the effective item_name (the one
    // being patched, else the live listing's) is <= 75 chars and the pair is
    // <= 200. Drop title_differentiation (not the whole SKU) when it can't hold.
    if ($includeTitles && isset($candidates['title_differentiation'])) {
        $liveItemName = (string) ($baseListing['attributes']['item_name'][0]['value'] ?? '');
        $couplingErr  = \Ige\Amazon\Ai\TitleDecisions::couplingError(
            $candidates['item_name'] ?? null,
            $candidates['title_differentiation'],
            $liveItemName !== '' ? $liveItemName : null,
        );
        if ($couplingErr !== null) {
            unset($candidates['title_differentiation']);
            $titleSkipped[] = 'title_differentiation (' . $couplingErr . ')';
        }
    }
    if ($titleSkipped) {
        echo '  titles held back: ' . implode(', ', $titleSkipped) . PHP_EOL;
    }

    // --- Guard 1: identifying ---
    // Union the schema/curated identifying set with the theme attributes THIS
    // item actually varies on (from its own relationships) — so a variation
    // member's material / size+color are caught even if the schema parse misses
    // them (stakeholder 4). Still flag-gated: --include-identifying lets them
    // through exactly as before.
    $itemVariationAttrs = IdentifyingAttributes::itemVariationAttributes(
        $baseListing['relationships'] ?? [],
    );
    $skippedIdentifying = [];
    foreach (array_keys($candidates) as $attr) {
        $isIdentifying = IdentifyingAttributes::isIdentifying($attr, $productType, $schemasDir)
            || in_array(strtolower($attr), $itemVariationAttrs, true);
        if (!$isIdentifying) {
            continue;
        }
        $allow = $includeIdentifying === 'all'
            || (is_array($includeIdentifying) && in_array(strtolower($attr), $includeIdentifying, true));
        if (!$allow) {
            $skippedIdentifying[] = $attr;
            unset($candidates[$attr]);
        }
    }

    // --- Guard 2: shrink (array attrs shorter than baseline) ---
    $skippedShrink = [];
    if (!$allowShrink && $baseListing !== null) {
        foreach ($candidates as $attr => $value) {
            if (!is_array($value)) {
                continue;
            }
            $baseCount = baselineSlotCount($baseListing, $attr);
            if ($baseCount > 0 && count($value) < $baseCount) {
                $skippedShrink[] = $attr . ' (' . count($value) . '<' . $baseCount . ')';
                unset($candidates[$attr]);
            }
        }
    }

    // --- Guard 3: compliance hard-block (SKU-level) ---
    // A compliance attr blocks only when it is genuinely NEEDED but has no
    // value. Resolvable attrs (e.g. prop 65) auto-fill at draft time and never
    // block. For no-resolver attrs (e.g. pesticide_marking) the in-scope test
    // is schema-REQUIRES-it, not merely schema-defines-it: pesticide_marking is
    // an *optional* property on ~half of all product types, so defines-it would
    // block nearly every SKU. Requires-it limits the block to products that
    // truly need the marking.
    $complianceBlock = [];
    $schemaRequired  = schemaRequired($productType, $schemasDir);
    foreach ($complianceAttrs as $cAttr) {
        if (ComplianceResolvers::has($cAttr)) {
            continue; // auto-resolves at draft time — never blocks
        }
        if (!in_array($cAttr, $schemaRequired, true)) {
            continue; // not required for this product type — not in scope
        }
        $resolved = baselineHasValue($baseListing, $cAttr)          // present on live listing
            || array_key_exists($cAttr, $candidates)               // present in this patch
            || (isset($draft['attributes'][$cAttr]['value'])       // present in draft (even if guarded out)
                && $draft['attributes'][$cAttr]['value'] !== null);
        if (!$resolved) {
            $complianceBlock[] = $cAttr;
        }
    }

    if ($complianceBlock && !$skipComplianceBlock) {
        echo '  [COMPLIANCE BLOCK] unresolved: ' . implode(', ', $complianceBlock) . ' — SKU not patched' . PHP_EOL;
        if ($skippedIdentifying) {
            echo '  (also held back — identifying: ' . implode(', ', $skippedIdentifying) . ')' . PHP_EOL;
        }
        $stats['compliance_block']++;
        $results[] = AmazonPatch::resultRow($sku, $asin, $productType, 0, 'COMPLIANCE_BLOCK')
            + [
                'skipped_identifying' => implode('; ', $skippedIdentifying),
                'skipped_shrink'      => implode('; ', array_map(fn($s) => explode(' ', $s)[0], $skippedShrink)),
                'compliance_block'    => implode('; ', $complianceBlock),
            ];
        continue;
    }

    // --- Build final patch operations from surviving candidates ---
    $patches = [];
    foreach ($candidates as $attr => $value) {
        $patches[] = AmazonPatch::replaceOp($attr, $value, $marketplaceId);
    }

    if (!$patches) {
        echo '  [SKIP] nothing to patch after guards';
        if ($skippedIdentifying) {
            echo ' — held back identifying: ' . implode(', ', $skippedIdentifying);
        }
        if ($skippedShrink) {
            echo ' — held back shrink: ' . implode(', ', $skippedShrink);
        }
        echo PHP_EOL;
        $stats['skipped']++;
        $results[] = AmazonPatch::resultRow($sku, $asin, $productType, 0, $apply ? 'skipped' : 'dry_run')
            + [
                'skipped_identifying' => implode('; ', $skippedIdentifying),
                'skipped_shrink'      => implode('; ', array_map(fn($s) => explode(' ', $s)[0], $skippedShrink)),
                'compliance_block'    => '',
            ];
        continue;
    }

    $attrNames = array_map([AmazonPatch::class, 'opAttr'], $patches);
    echo '  patching ' . count($patches) . ' attribute(s): ' . implode(', ', $attrNames) . PHP_EOL;
    if ($skippedAttrs) {
        echo '  skipping (null/invalid): ' . implode(', ', $skippedAttrs) . PHP_EOL;
    }
    if ($skippedIdentifying) {
        echo '  held back (identifying): ' . implode(', ', $skippedIdentifying) . PHP_EOL;
    }
    if ($skippedShrink) {
        echo '  held back (shrink): ' . implode(', ', $skippedShrink) . PHP_EOL;
    }
    if ($apply && $baseline['backup']) {
        echo '  backup → ' . $baseline['backup'] . PHP_EOL;
    }

    $extra = [
        'skipped_identifying' => implode('; ', $skippedIdentifying),
        'skipped_shrink'      => implode('; ', array_map(fn($s) => explode(' ', $s)[0], $skippedShrink)),
        'compliance_block'    => '',
    ];

    if (!$apply) {
        $results[] = AmazonPatch::resultRow($sku, $asin, $productType, count($patches), 'dry_run') + $extra;
        continue;
    }

    // --- Live patch ---
    $patchRequest = AmazonPatch::buildPatchRequest($productType, $patches);

    try {
        $result = AmazonPatch::submitPatch(
            $listingsApi,
            $amazon->sellerId,
            $sku,
            $patchRequest,
            $marketplaceId,
        );

        $status        = $result['status'];
        $submissionId  = $result['submission_id'];
        $issuesSummary = $result['issues_summary'];

        echo '  → ' . $status . ($submissionId ? ' (' . $submissionId . ')' : '') . PHP_EOL;
        if ($issuesSummary !== '') {
            echo '  issues: ' . $issuesSummary . PHP_EOL;
        }

        match (strtoupper($status)) {
            'ACCEPTED'      => $stats['accepted']++,
            'WITH_WARNINGS' => $stats['with_warnings']++,
            'INVALID'       => $stats['invalid']++,
            default         => $stats['failed']++,
        };

        $results[] = AmazonPatch::resultRow(
            $sku, $asin, $productType, count($patches),
            $status, $submissionId, $result['issues_count'], $issuesSummary,
        ) + $extra;
    } catch (\Throwable $e) {
        echo '  [ERROR] ' . $e->getMessage() . PHP_EOL;
        $stats['failed']++;
        $results[] = AmazonPatch::resultRow(
            $sku, $asin, $productType, count($patches),
            'ERROR', '', 0, $e->getMessage(),
        ) + $extra;
    }
}

// ---------------------------------------------------------------------------
// Write results CSV
// ---------------------------------------------------------------------------

if ($results) {
    $ts      = date('Y-m-d-H-i-s');
    $outFile = $paths['output'] . '/patch_results_' . $ts . '.csv';
    $fh      = fopen($outFile, 'w');

    $cols = array_merge(
        AmazonPatch::RESULT_COLUMNS,
        ['skipped_identifying', 'skipped_shrink', 'compliance_block'],
    );
    // Escape disabled ('') for strict RFC-4180 output.
    fputcsv($fh, $cols, ',', '"', '');
    foreach ($results as $row) {
        fputcsv($fh, array_map(fn($c) => $row[$c] ?? '', $cols), ',', '"', '');
    }

    fclose($fh);
    echo PHP_EOL . 'Results → ' . $outFile . PHP_EOL;
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo PHP_EOL;
echo '─────────────────────────────────────────' . PHP_EOL;

if (!$apply) {
    $queued = count(array_filter($results, fn($r) => $r['status'] === 'dry_run'));
    echo 'DRY RUN complete.' . PHP_EOL;
    echo 'SKUs queued to patch : ' . $queued . PHP_EOL;
    echo 'SKUs skipped         : ' . $stats['skipped'] . PHP_EOL;
    echo 'Compliance-blocked   : ' . $stats['compliance_block'] . PHP_EOL;
    echo PHP_EOL;
    echo 'Pass --apply to submit to Amazon.' . PHP_EOL;
} else {
    echo 'Accepted             : ' . $stats['accepted'] . PHP_EOL;
    echo 'With warnings        : ' . $stats['with_warnings'] . PHP_EOL;
    echo 'Invalid              : ' . $stats['invalid'] . PHP_EOL;
    echo 'Failed/Error         : ' . $stats['failed'] . PHP_EOL;
    echo 'Skipped              : ' . $stats['skipped'] . PHP_EOL;
    echo 'Compliance-blocked   : ' . $stats['compliance_block'] . PHP_EOL;
}
