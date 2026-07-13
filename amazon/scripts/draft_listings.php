<?php

declare(strict_types=1);

use Anthropic\Client;

/**
 * Phase 7 — AI-assisted draft generation.
 *
 * Ingests the gap-fill analysis (Phase 6 output) and fills each gap by this
 * precedence: Usurper (system of record) -> Catalog (this ASIN's own Amazon
 * catalog snapshot) -> AI (Anthropic, schema-constrained). Usurper and catalog
 * are free and authoritative; AI is the last resort for what neither can supply.
 * Catalog fills run across all gaps regardless of --include-recommended scope.
 *
 * Output: one draft JSON per SKU at amazon/data/{account}/drafts/{sku}.json
 *
 * Usage:
 *   php amazon/scripts/draft_listings.php [--account=IGE|DOWS] [OPTIONS]
 *
 * Flags:
 *   --account=IGE|DOWS        Seller account. Default: IGE.
 *   --model=MODEL             Anthropic model. Default: claude-haiku-4-5.
 *   --sku=SKU                 Process a single SKU only (for testing).
 *   --force                   Overwrite existing drafts.
 *   --dry-run                 Show what would be done; no API calls or writes.
 *   --help                    Show this help message.
 *
 * Environment:
 *   ANTHROPIC_API_KEY         Required in .env or shell.
 *
 * Inputs:
 *   amazon/data/{account}/output/listings_gap_fill.csv
 *   amazon/data/{account}/input/usurper/*.csv      (latest by mtime)
 *   amazon/data/{account}/input/listings/{sku}.json
 *   amazon/data/{account}/input/catalog/{asin}.json
 *   amazon/data/schemas/{PRODUCT_TYPE}.json
 *
 * Output:
 *   amazon/data/{account}/drafts/{sku}.json
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/UsurperExport.php';
require __DIR__ . '/../../lib/ComplianceResolvers.php';
require __DIR__ . '/../../lib/IdentifyingAttributes.php';
require __DIR__ . '/../../lib/HighValueAttributes.php';

// Compliance-critical attributes that must always be present (Phase 10, 4c).
$complianceAttrs = require __DIR__ . '/../../lib/ComplianceAttributes.php';

// Stakeholder default attributes (null document-only defaults + real value
// defaults like product_tax_code / unit_count). See lib/DefaultAttributes.php.
$defaultAttrs = require __DIR__ . '/../../lib/DefaultAttributes.php';

// US marketplace by default (both seller accounts are US); compliance values
// are marketplace-scoped so the fill needs an id even though drafting is offline.
$marketplaceId = $_ENV['AMAZON_SPAPI_MARKETPLACE_ID'] ?? 'ATVPDKIKX0DER';

// -NCX/-FBA placeholder templating (Phase 10.4, W6). A placeholder SKU is
// detected by this suffix; its "base" is the same SKU with the suffix stripped.
// The base shares the placeholder's ASIN/product_type/title, so copying its
// attributes is safe by definition. The same suffix gates the data-gate's
// base-context borrow: only these known-copy SKUs may inherit context.
const PLACEHOLDER_SUFFIX_RE = '/-(NCX|FBA)$/';

// Model tiers for --model=auto and cost estimation. $ per 1M tokens.
const MODEL_RATES = [
    'claude-haiku-4-5'  => ['in' => 1.0, 'out' => 5.0],
    'claude-sonnet-4-6' => ['in' => 3.0, 'out' => 15.0],
    'claude-opus-4-8'   => ['in' => 5.0, 'out' => 25.0],
];

// Never copied from a base into a placeholder: these are SKU-specific — the
// whole point of an FBA/FBM split — so they must stay per-SKU. Mirrors the
// restore_listings.php NON_RESTORABLE set (+ procurement) for consistency.
const TEMPLATE_NEVER_COPY = [
    'purchasable_offer', 'skip_offer', 'list_price', 'fulfillment_availability',
    'merchant_shipping_group', 'product_tax_code', 'condition_type',
    'condition_note', 'supplemental_condition_information', 'ships_globally',
    'website_shipping_weight', 'deprecated_offering_start_date', 'procurement',
];

// ---------------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/draft_listings.php [--account=IGE|DOWS] [OPTIONS]

Flags:
  --account=IGE|DOWS        Seller account. Default: IGE.
  --model=auto|MODEL        Model selection. Default: auto.
                              auto             — haiku for enum/short-string,
                                                 sonnet for prose, opus for the
                                                 marquee title/description set
                              claude-haiku-4-5 — haiku for all AI attributes
                              claude-sonnet-4-6— sonnet for all AI attributes
                              claude-opus-4-8  — opus for all AI attributes
  --include-recommended     Author the full optional schema tail. Default authors
                            only required + the curated high-value allowlist
                            (lib/HighValueAttributes.php).
  --no-data-gate            Do not skip context-less SKUs to needs_human; author
                            from whatever context exists (the old behavior).
  --full                    Umbrella: --include-recommended + --no-data-gate.
                            Reproduces the original exhaustive draft. Pair with
                            --model=opus for the literal old Opus-on-everything.
  --batch-size=N            Max attributes per API call. Default: 20. Larger values
                            reduce call count but degrade response quality for SKUs
                            with many missing attributes.
  --sku=SKU                 Process a single SKU only (for testing).
  --template-placeholders   Enable -NCX/-FBA placeholder templating: union-fill
                            each placeholder from its base SKU's Amazon listing
                            snapshot (fill-missing only; commercial/SKU-specific
                            attrs never copied). Also discovers placeholder SKUs
                            that have a listing snapshot but no gap-fill rows.
  --force                   Overwrite existing drafts.
  --dry-run                 Preview only; no API calls, no files written. Prints
                            an estimated cost breakdown.
  --help                    Show this help message.

Default is required + curated allowlist, tiered models, and a data-gate that
borrows context from a base SKU (-FBA/-NCX) or flags needs_human. Run --dry-run
first to see the cost estimate.
HELP;
    echo PHP_EOL;
    exit(0);
}

$account            = 'IGE';
$model              = 'auto';
$batchSize          = 20;
$singleSku          = null;
$force              = false;
$dryRun             = false;
$templateMode       = false;
$includeRecommended = false;
$noDataGate         = false;

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    } elseif (preg_match('/^--model=(.+)$/', $arg, $m)) {
        $model = $m[1];
    } elseif (preg_match('/^--batch-size=(\d+)$/', $arg, $m)) {
        $batchSize = max(1, (int) $m[1]);
    } elseif (preg_match('/^--sku=(.+)$/', $arg, $m)) {
        $singleSku = $m[1];
    } elseif ($arg === '--template-placeholders') {
        $templateMode = true;
    } elseif ($arg === '--include-recommended') {
        $includeRecommended = true;
    } elseif ($arg === '--no-data-gate') {
        $noDataGate = true;
    } elseif ($arg === '--full') {
        $includeRecommended = true;
        $noDataGate         = true;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$paths = amazon_paths($account);

echo 'Account    : ' . $account . PHP_EOL;
echo 'Model      : ' . ($model === 'auto'
    ? 'auto (haiku enum/short, sonnet prose, opus marquee)'
    : $model) . PHP_EOL;
echo 'Scope      : ' . ($includeRecommended
    ? 'required + all recommended (full tail)'
    : 'required + curated high-value allowlist') . PHP_EOL;
echo 'Data-gate  : ' . ($noDataGate ? 'OFF (author context-less SKUs)' : 'ON (base-SKU borrow / needs_human)') . PHP_EOL;
echo 'Batch size : ' . $batchSize . ' attrs/call' . PHP_EOL;
if ($templateMode) {
    echo 'Template   : -NCX/-FBA placeholder templating ON' . PHP_EOL;
}
if ($dryRun) {
    echo '[DRY RUN — no API calls, no files written]' . PHP_EOL;
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Anthropic client
// ---------------------------------------------------------------------------

$anthropic = null;
if (!$dryRun) {
    $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';
    if ($apiKey === '') {
        fwrite(STDERR, "ANTHROPIC_API_KEY is not set. Add it to .env or export it.\n");
        exit(1);
    }
    $anthropic = new Client(apiKey: $apiKey);
}

// ---------------------------------------------------------------------------
// Load gap-fill CSV (Phase 6 output)
// ---------------------------------------------------------------------------

$gapFile = $paths['output'] . '/listings_gap_fill.csv';
if (!file_exists($gapFile)) {
    fwrite(STDERR, "Gap-fill CSV not found: {$gapFile}\n");
    fwrite(STDERR, "Run analyze_gap_fill.php --account={$account} first.\n");
    exit(1);
}

$fhG    = fopen($gapFile, 'r');
$header = fgetcsv($fhG);
$gaps   = []; // [sku => [rows]]

while (($row = fgetcsv($fhG)) !== false) {
    if (!$header || count($row) !== count($header)) {
        continue;
    }
    $r   = array_combine($header, $row);
    $sku = $r['sku'];
    if ($singleSku !== null && $sku !== $singleSku) {
        continue;
    }
    $gaps[$sku][] = $r;
}
fclose($fhG);

// --- Template mode: discover placeholder SKUs not covered by the gap CSV -----
// A placeholder can be a valid draft target even with zero schema gaps — its
// draft comes from the base template, not gap-fill. Pull in any -NCX/-FBA SKU
// that has a listing snapshot so `--sku=FOO-FBA` works regardless of the CSV.
if ($templateMode) {
    $placeholders = glob($paths['listings'] . '/*.json') ?: [];
    foreach ($placeholders as $file) {
        $sku = basename($file, '.json');
        if (!preg_match(PLACEHOLDER_SUFFIX_RE, $sku)) {
            continue;
        }
        if ($singleSku !== null && $sku !== $singleSku) {
            continue;
        }
        if (!isset($gaps[$sku])) {
            $gaps[$sku] = []; // no schema-gap rows; base template supplies attrs
        }
    }
}

if (!$gaps) {
    $msg = $singleSku
        ? "SKU '{$singleSku}' not found in gap-fill CSV."
          . ($templateMode ? ' No -NCX/-FBA listing snapshot for it either.' : '')
        : 'No gaps found in gap-fill CSV.';
    echo $msg . PHP_EOL;
    exit(0);
}

echo 'SKUs with gaps : ' . count($gaps) . PHP_EOL;

// ---------------------------------------------------------------------------
// Load Usurper CSV (full values — gap-fill truncates at 120 chars)
// ---------------------------------------------------------------------------

$usurperDir  = $paths['input'] . '/usurper';
$usurperFile = usurper_latest_export($usurperDir);
$usurper     = []; // [sku => [col => val]]

if ($usurperFile !== null) {
    echo 'Usurper file   : ' . basename($usurperFile) . PHP_EOL;

    // Shared RFC-4180 reader (escape disabled). Prior to this, rows whose values
    // contained backslashes — mangled inch marks like `Size: 3\"` — desynced the
    // parser and were silently dropped, so those SKUs got no fillable resolution.
    $loaded   = usurper_load_export($usurperFile);
    $usurper  = $loaded['records'];
    echo 'Usurper records: ' . count($usurper) . PHP_EOL;

    if ($loaded['malformed']) {
        echo 'Warning: ' . count($loaded['malformed'])
            . ' export row(s) could not be aligned to the header and were skipped.' . PHP_EOL;
    }
} else {
    echo 'Warning: no Usurper CSV in ' . $usurperDir . ' — fillable resolution will be empty.' . PHP_EOL;
}

echo PHP_EOL;

$attrMap = require __DIR__ . '/../../lib/UsurperAttributeMap.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Pull a simple string value from a listing's attribute slots.
 */
function listingAttr(array $listing, string $attr): string
{
    $slots = $listing['attributes'][$attr] ?? [];
    if (!$slots) {
        return '';
    }
    $v = $slots[0]['value'] ?? '';
    if (is_array($v)) {
        return implode(', ', $v);
    }
    return (string) $v;
}

/**
 * Product type from a getListingsItem snapshot (used when a placeholder SKU
 * has no gap-fill rows to carry it).
 */
function listingProductType(array $listing): string
{
    return (string) ($listing['productTypes'][0]['productType'] ?? '');
}

/**
 * ASIN from a getListingsItem snapshot.
 */
function listingAsin(array $listing): string
{
    return (string) ($listing['summaries'][0]['asin'] ?? '');
}

/**
 * Chemicals a listing declares PRESENT via a California Proposition 65 chemical
 * warning. Amazon treats an accepted Prop 65 entry as canon, so AI-authored
 * substance-exclusion claims (e.g. material_type_free "BPA Free") must not
 * contradict it. Returns the declared chemical name tokens, deduped, verbatim
 * from the listing (e.g. "bisphenol_a_bpa"); the prompt tells the model to treat
 * common abbreviations as covered too.
 *
 * @return list<string>
 */
function declaredProp65Chemicals(array $listing): array
{
    $out = [];
    foreach ($listing['attributes']['california_proposition_65'] ?? [] as $entry) {
        foreach ($entry['chemical_names'] ?? [] as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $out[$name] = true;
            }
        }
    }
    return array_keys($out);
}

/**
 * Resolve a fillable attribute from the Usurper row using the attribute map.
 *
 * bullet_point is multi-source: collect ALL non-empty feature columns so the
 * draft captures every bullet, not just the first.
 *
 * @return array{value: string|list<string>, usurper_column: string}|null
 */
function resolveFromUsurper(string $attr, array $usurperRow, array $attrMap): ?array
{
    // Fall back to attr.amazon_{attr} — the round-trip convention for
    // Amazon-specific attributes with no explicit Usurper column mapping.
    $candidates = $attrMap[$attr] ?? ['attr.amazon_' . $attr];

    if ($attr === 'bullet_point') {
        $values = [];
        $cols   = [];
        foreach ($candidates as $col) {
            $val = trim($usurperRow[$col] ?? '');
            if ($val !== '') {
                $values[] = $val;
                $cols[]   = $col;
            }
        }
        if (!$values) {
            return null;
        }
        return ['value' => $values, 'usurper_column' => implode(', ', $cols)];
    }

    foreach ($candidates as $col) {
        $val = trim($usurperRow[$col] ?? '');
        if ($val !== '') {
            return ['value' => $val, 'usurper_column' => $col];
        }
    }
    return null;
}

/**
 * Load the SP-API catalog attributes for an ASIN (Phase 3 output), or [] if the
 * ASIN has no catalog snapshot. The `attributes` node is already in listing shape
 * ([{value, marketplace_id, language_tag}] / dimension objects), so values pass
 * through formatPatchValue unchanged.
 *
 * @return array<string, mixed> attribute name => SP-API value
 */
function loadCatalogAttributes(string $asin, string $catalogDir): array
{
    if ($asin === '') {
        return [];
    }
    $file = $catalogDir . '/' . $asin . '.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    $attrs = $data['attributes'] ?? null;
    return is_array($attrs) ? $attrs : [];
}

/**
 * Load this ASIN's `relationships` node from its catalog snapshot (Phase 3
 * output), or [] if absent. Used to detect variation membership and the exact
 * variation-theme attributes an item participates in when the SKU's own listing
 * snapshot has no relationships block.
 *
 * @return array<int, mixed> the raw relationships list
 */
function loadCatalogRelationships(string $asin, string $catalogDir): array
{
    if ($asin === '') {
        return [];
    }
    $file = $catalogDir . '/' . $asin . '.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    $rels = $data['relationships'] ?? null;
    return is_array($rels) ? $rels : [];
}

/**
 * Resolve an attribute from the catalog snapshot. Returns the SP-API-shaped value
 * for a settable schema attribute the catalog actually carries, else null.
 * The catalog is this exact ASIN's own record, so values are schema-valid.
 *
 * @return array<int, mixed>|null
 */
function resolveFromCatalog(string $attr, array $catalogAttrs, array $schemaProps): ?array
{
    // Only fill attributes the product-type schema actually defines (settable).
    if (!isset($schemaProps[$attr])) {
        return null;
    }
    $val = $catalogAttrs[$attr] ?? null;
    // Expect a non-empty list of attribute slots.
    if (!is_array($val) || $val === [] || !is_array($val[0] ?? null)) {
        return null;
    }
    return array_values($val);
}

/**
 * Amazon nests the real constraints one level down: the property is an array of
 * {value, marketplace_id, language_tag} objects, so enum/maxLength/examples live
 * under items.properties.value.*. Return that node (falling back to the property
 * itself for any already-flattened schema).
 */
function schemaValueNode(array $prop): array
{
    $node = $prop['items']['properties']['value'] ?? null;
    return is_array($node) ? $node : $prop;
}

/** Enum choices for an attribute (from the nested value node). */
function schemaEnum(array $prop): array
{
    return schemaValueNode($prop)['enum'] ?? [];
}

/** maxLength for a string attribute, or null. Drives modular-title caps. */
function schemaMaxLength(array $prop): ?int
{
    $len = schemaValueNode($prop)['maxLength'] ?? null;
    return is_int($len) ? $len : null;
}

/**
 * Pick the model tier for an attribute under --model=auto:
 *   enum or short-string  → haiku (cheap, accurate)
 *   marquee title/desc    → opus  (highest-value prose)
 *   everything else prose → sonnet
 */
function pickModel(string $attr, array $prop): string
{
    if (schemaEnum($prop)) {
        return 'claude-haiku-4-5';
    }
    if (HighValueAttributes::isMarquee($attr)) {
        return 'claude-opus-4-8';
    }
    $maxLen = schemaMaxLength($prop);
    if ($maxLen !== null && $maxLen <= 50) {
        return 'claude-haiku-4-5';
    }
    return 'claude-sonnet-4-6';
}

/** Rough output-token estimate per attribute, for the dry-run cost projection. */
function estOutputTokens(string $attr, array $prop): int
{
    if (schemaEnum($prop)) {
        return 15;
    }
    $maxLen = schemaMaxLength($prop);
    if ($maxLen !== null && $maxLen <= 60) {
        return (int) ceil($maxLen / 4) + 5;
    }
    return 120; // open-ended prose
}

/**
 * Format one attribute's schema constraints as a prompt hint.
 */
function schemaHint(string $attr, array $prop): string
{
    $lines = ['Attribute: ' . $attr];
    $node  = schemaValueNode($prop);

    $desc = $prop['description'] ?? ($node['description'] ?? '');
    if ($desc !== '') {
        $lines[] = 'Description: ' . $desc;
    }

    $enum      = $node['enum'] ?? [];
    $enumNames = $node['enumNames'] ?? [];
    if ($enum) {
        $pairs = [];
        foreach ($enum as $i => $val) {
            // Booleans must render as literal true/false, not PHP's ""/"1" cast.
            $display = is_bool($val) ? ($val ? 'true' : 'false') : (string) $val;
            $label   = $enumNames[$i] ?? '';
            $pairs[] = $label !== '' ? "{$display} ({$label})" : $display;
        }
        $lines[] = 'ALLOWED VALUES (return exactly one, verbatim): ' . implode(', ', $pairs);
    } elseif ($node['examples'] ?? ($prop['examples'] ?? [])) {
        $examples = array_slice((array) ($node['examples'] ?? $prop['examples']), 0, 5);
        $lines[]  = 'Examples: ' . implode(', ', $examples);
    }

    $maxLen = schemaMaxLength($prop);
    if ($maxLen !== null && $maxLen > 0 && $maxLen <= 1000) {
        $lines[] = 'Max length: ' . $maxLen . ' characters.';
    }

    return implode("\n", $lines);
}

/**
 * Build the Claude prompt for a batch of needs_authoring attributes.
 */
function buildPrompt(
    string $sku,
    string $productType,
    string $title,
    string $brand,
    string $description,
    array  $features,
    array  $attrs,
    array  $schemaProps,
    array  $prop65Chemicals = []
): string {
    $featuresText = '';
    foreach (array_filter($features) as $f) {
        $featuresText .= "- {$f}\n";
    }
    if ($featuresText === '') {
        $featuresText = "(none)\n";
    }

    $attrsBlock = '';
    foreach ($attrs as $attr) {
        $prop        = $schemaProps[$attr] ?? [];
        $attrsBlock .= "\n" . schemaHint($attr, $prop) . "\n";
    }

    $attrList = implode(', ', $attrs);

    // Declared compliance state (Amazon-accepted, authoritative). When the
    // listing carries a Prop 65 chemical warning, the product is CERTIFIED to
    // contain those chemicals — so the model must not author a contradictory
    // "free of X" claim (material_type_free's whole purpose is excluded
    // substances, e.g. "BPA Free"). Only emitted when chemicals are declared,
    // so the common case is unchanged.
    $complianceBlock = '';
    if ($prop65Chemicals !== []) {
        $chemList = implode(', ', $prop65Chemicals);
        $complianceBlock = "\n=== DECLARED COMPLIANCE STATE (authoritative — do not contradict) ==="
            . "\nThis listing carries a California Proposition 65 chemical warning declaring the "
            . "product CONTAINS: {$chemList}."
            . "\nDo NOT state or imply the product is free of, excludes, or does not contain any of "
            . "these substances — including their common abbreviations (e.g. \"BPA\" for "
            . "bisphenol_a_bpa). For example, do not answer \"BPA Free\" for material_type_free; "
            . "return null there instead of a contradictory claim.\n";
    }

    // Amazon modular titles (effective 2026-07-27): item_name <= 75 chars and a
    // supplementary title_differentiation <= 125 chars, only usable when
    // item_name is 75 chars or fewer, with the two totalling <= 200 chars.
    $titleRule = '';
    if (in_array('title_differentiation', $attrs, true) || in_array('item_name', $attrs, true)) {
        $titleRule = "\n- Titles are modular: item_name must be 75 characters or fewer. "
            . "title_differentiation is a supplementary highlight (<=125 chars) that is "
            . "only valid when item_name is <=75 chars; item_name + title_differentiation "
            . "must total 200 characters or fewer.";
    }

    // bullet_point is a list of highlights, not a single value: ask for an array
    // so each bullet lands in its own attr.featureXX column downstream.
    $bulletRule = '';
    if (in_array('bullet_point', $attrs, true)) {
        $bulletRule = "\n- For bullet_point, return a JSON array of up to 5 concise, "
            . "benefit-driven bullet strings (not a single string).";
    }

    return <<<PROMPT
You are an Amazon SP-API listing specialist. A product is missing the attributes listed below.
Using the product context provided, suggest appropriate values for each attribute.

=== PRODUCT CONTEXT ===
SKU: {$sku}
Product Type: {$productType}
Title: {$title}
Brand: {$brand}
Description: {$description}
Features:
{$featuresText}{$complianceBlock}
=== MISSING ATTRIBUTES ==={$attrsBlock}
=== INSTRUCTIONS ===
- Suggest one value per attribute based on the product context.
- For attributes with ALLOWED VALUES, return exactly one of those values verbatim.
- Respect any stated Max length; keep the value within it.{$titleRule}{$bulletRule}
- If you truly cannot determine a suitable value, return null for that key.
- Return ONLY a valid JSON object. Keys are attribute names, values are strings, null,
  or (only for bullet_point) an array of strings.
- Do not include markdown fences, code blocks, or any explanation — just the JSON object.

Required keys in your response: {$attrList}
PROMPT;
}

/**
 * Build the prompt for a standalone modular item_name suggestion (stakeholder 5).
 * Asks for a single <=75-char title for human review, seeded with the product's
 * existing title so the model condenses rather than invents.
 */
function buildItemNamePrompt(
    string $sku,
    string $productType,
    string $existingTitle,
    string $brand,
    string $description,
    array  $features
): string {
    $featuresText = '';
    foreach (array_slice(array_filter($features), 0, 5) as $f) {
        $featuresText .= "- {$f}\n";
    }
    if ($featuresText === '') {
        $featuresText = "(none)\n";
    }
    $existing = $existingTitle !== '' ? $existingTitle : '(none)';

    return <<<PROMPT
You are an Amazon SP-API listing specialist. Amazon's modular titles (effective
2026-07-27) require item_name to be 75 characters or fewer. Write ONE concise,
keyword-rich, SEO-optimized item_name of AT MOST 75 characters for the product
below — a shopper-facing title, not a part number.

Prefer condensing the existing title if one is provided; keep the brand and the
most important product identity. Do not exceed 75 characters.

=== PRODUCT CONTEXT ===
SKU: {$sku}
Product Type: {$productType}
Existing Title: {$existing}
Brand: {$brand}
Description: {$description}
Features:
{$featuresText}
=== INSTRUCTIONS ===
- Write a real, human-readable SEO title: brand + what the product IS + its most
  searchable attributes (material, size, color, use). Lead with the brand.
- Do NOT output the SKU, MPN, or a model/part number as the title, and do NOT
  repeat any identifier. If the existing title is just a code or model number,
  ignore it and describe the product from the type, brand, and features instead.
- Return ONLY a valid JSON object: {"item_name": "<= 75 char title"}.
- No markdown fences, no explanation.
PROMPT;
}

/**
 * Load (and cache) a schema JSON file by product type.
 */
function loadSchema(string $productType, string $schemasDir): array
{
    static $cache = [];
    if (!isset($cache[$productType])) {
        $file            = $schemasDir . '/' . $productType . '.json';
        $cache[$productType] = file_exists($file)
            ? (json_decode(file_get_contents($file), true) ?? [])
            : [];
    }
    return $cache[$productType];
}

// ---------------------------------------------------------------------------
// Process each SKU
// ---------------------------------------------------------------------------

$stats = [
    'skipped'             => 0,
    'written'             => 0,
    'api_calls'           => 0,
    'fillable_total'      => 0,
    'catalog_total'       => 0,
    'ai_total'            => 0,
    'ai_null'            => 0,
    'template_total'      => 0,
    'skipped_recommended' => 0,
    'needs_human'         => 0,
    'errors'              => 0,
];

// SKUs flagged for human enrichment (no context, no base match).
$needsHumanSkus = [];

// Dry-run cost projection: per-model input/output token + dollar accumulators.
$costEst = [];
foreach (array_keys(MODEL_RATES) as $mId) {
    $costEst[$mId] = ['in' => 0, 'out' => 0];
}

// Sort by priority desc so highest-value SKUs are drafted first
uasort($gaps, fn($a, $b) => (int) ($b[0]['priority'] ?? 0) <=> (int) ($a[0]['priority'] ?? 0));

foreach ($gaps as $sku => $rows) {
    $draftFile = $paths['drafts'] . '/' . $sku . '.json';

    if (file_exists($draftFile) && !$force) {
        echo "[SKIP] {$sku} — draft exists (use --force to overwrite)" . PHP_EOL;
        $stats['skipped']++;
        continue;
    }

    // Load listing JSON for existing product context
    $listingFile = $paths['listings'] . '/' . $sku . '.json';
    $listing     = file_exists($listingFile)
        ? (json_decode(file_get_contents($listingFile), true) ?? [])
        : [];

    // Placeholders discovered in template mode may have no gap rows; fall back
    // to the SKU's own listing snapshot for product_type/asin.
    $productType = $rows[0]['product_type'] ?? listingProductType($listing);
    $asin        = $rows[0]['asin'] ?? listingAsin($listing);

    echo "[{$sku}]" . ($productType ? " {$productType}" : '') . PHP_EOL;

    $schema      = loadSchema($productType, $paths['schemas']);
    $schemaProps = $schema['properties'] ?? [];

    // Authoritative Amazon catalog snapshot for this ASIN (fill source below AI).
    $catalogAttrs = loadCatalogAttributes($asin, $paths['catalog']);

    // This item's variation membership: prefer its own listing snapshot's
    // relationships, else the catalog snapshot. The variation-theme attributes
    // (e.g. material, or size+color) identify this ASIN's place in its family, so
    // AI must never author a NEW value for them (a wrong value can split/merge
    // the variation). Stakeholder rule 4.
    $relationships  = $listing['relationships'] ?? loadCatalogRelationships($asin, $paths['catalog']);
    $relationships  = is_array($relationships) ? $relationships : [];
    $variationAttrs = IdentifyingAttributes::itemVariationAttributes($relationships);
    if ($variationAttrs) {
        echo '  variation: member — theme attrs [' . implode(', ', $variationAttrs) . '] held from AI authoring' . PHP_EOL;
    }

    // Gather product context — listing first, Usurper as fallback
    $usurperRow  = $usurper[$sku] ?? null;
    $title       = listingAttr($listing, 'item_name');
    $brand       = listingAttr($listing, 'brand');
    $description = listingAttr($listing, 'product_description');
    $features    = array_column($listing['attributes']['bullet_point'] ?? [], 'value');

    if ($usurperRow !== null) {
        if ($title === '') {
            $title = trim($usurperRow['attr.title_amazon'] ?? $usurperRow['name'] ?? '');
        }
        if ($brand === '') {
            $brand = trim($usurperRow['attr.brand_amazon'] ?? $usurperRow['attr.brand'] ?? '');
        }
        if ($description === '') {
            $description = trim($usurperRow['attr.short_description'] ?? $usurperRow['attr.description'] ?? '');
        }
        if (!$features) {
            foreach (['attr.feature01', 'attr.feature02', 'attr.feature03', 'attr.feature04', 'attr.feature05'] as $col) {
                $v = trim($usurperRow[$col] ?? '');
                if ($v !== '') {
                    $features[] = $v;
                }
            }
        }
    }

    // Separate fillable vs needs_authoring
    $fillableRows  = array_filter($rows, fn($r) => $r['fillable'] === 'yes');
    $authoringRows = array_filter($rows, fn($r) => $r['fillable'] !== 'yes');

    // Compliance-critical attributes are NEVER AI-authored — the compliance pass
    // (below) fills them by deterministic rule or from Usurper. Remove them from
    // the authoring set here so AI can't clobber them with a null before that pass
    // runs (the pass fill-misses only, so an AI null would win).
    $authoringRows = array_filter(
        $authoringRows,
        fn($r) => !in_array($r['attribute'], $complianceAttrs, true),
    );

    // Stakeholder fixed defaults (those with a concrete value/shape, not the
    // null document-only ones) are NEVER AI-authored either — the defaults pass
    // below fills them deterministically. Without this, a required attribute like
    // supplier_declared_dg_hz_regulation (required on ~all product types) would be
    // AI-authored first and the fill-missing default would never apply. Fillable
    // values from Usurper/catalog still win (they populate $draftAttrs before the
    // defaults pass); only AI invention is barred.
    $deterministicDefaults = array_keys(array_filter(
        $defaultAttrs,
        fn($spec) => isset($spec['shape']) || ($spec['value'] ?? null) !== null,
    ));
    $authoringRows = array_filter(
        $authoringRows,
        fn($r) => !in_array($r['attribute'], $deterministicDefaults, true),
    );

    // Variation identifiers (stakeholder 4): never AI-author a variation-theme
    // attribute for a variation member. Several of these (material, color, size)
    // live in the high-value allowlist, so without this a variation member would
    // get an AI-invented value that could split/merge its family. The catalog
    // backfill below still supplies this ASIN's own authoritative value.
    if ($variationAttrs) {
        $authoringRows = array_filter(
            $authoringRows,
            fn($r) => !in_array(strtolower($r['attribute']), $variationAttrs, true),
        );
    }

    // Scope: author only required + curated high-value unless --include-recommended.
    // The long optional tail (~130 attrs/SKU) is the cost driver; skipping it is
    // the ~15x lever. --include-recommended (or --full) restores the full tail.
    if (!$includeRecommended) {
        $before        = count($authoringRows);
        $authoringRows = array_filter(
            $authoringRows,
            fn($r) => $r['is_required'] === 'yes'
                || HighValueAttributes::isHighValue($r['attribute'], $productType),
        );
        $stats['skipped_recommended'] += $before - count($authoringRows);
    }

    $draftAttrs = [];

    // Declared Prop 65 chemicals for this SKU — fed to the AI prompt so it never
    // authors a substance-exclusion claim that contradicts an Amazon-accepted
    // (canonical) warning. Computed once; empty for the common non-warning case.
    $prop65Chemicals = declaredProp65Chemicals($listing);

    // --- Resolve fillable attributes from Usurper ---
    foreach ($fillableRows as $row) {
        $attr = $row['attribute'];

        if ($usurperRow === null) {
            continue;
        }

        $resolved = resolveFromUsurper($attr, $usurperRow, $attrMap);
        if ($resolved === null) {
            continue;
        }

        $draftAttrs[$attr] = [
            'value'          => $resolved['value'],
            'is_required'    => $row['is_required'] === 'yes',
            'source'         => 'usurper',
            'usurper_column' => $resolved['usurper_column'],
        ];
        $stats['fillable_total']++;
    }

    // --- Catalog backfill (fill order: Usurper -> Catalog -> AI) --------------
    // For any gap Usurper couldn't fill, use this ASIN's own catalog record —
    // authoritative Amazon data, free (no API), and schema-valid — before falling
    // to AI. Runs across ALL gaps regardless of --include-recommended scope
    // (catalog costs nothing and never hallucinates). Compliance attrs are owned
    // by the compliance pass and left untouched here. Independent of the data-gate
    // below: catalog values are facts, not context-dependent guesses.
    $catalogFilled = 0;
    if ($catalogAttrs) {
        foreach ($rows as $row) {
            $attr = $row['attribute'];
            if (isset($draftAttrs[$attr]) || in_array($attr, $complianceAttrs, true)) {
                continue;
            }
            $catVal = resolveFromCatalog($attr, $catalogAttrs, $schemaProps);
            if ($catVal === null) {
                continue;
            }
            $entry = [
                'value'       => $catVal,
                'is_required' => $row['is_required'] === 'yes',
                'source'      => 'catalog',
                'asin'        => $asin,
                'raw_value'   => true, // already SP-API-shaped
            ];
            // Variation-theme attr on a variation member: keep this ASIN's own
            // authoritative value but mark it so patch_listings holds it back
            // (identifying) unless the operator opts in (stakeholder 4).
            if (in_array(strtolower($attr), $variationAttrs, true)) {
                $entry['identifying']      = true;
                $entry['variation_member'] = true;
            }
            $draftAttrs[$attr] = $entry;
            $catalogFilled++;
        }
        if ($catalogFilled > 0) {
            echo "  catalog: filled {$catalogFilled} attr(s) from {$asin}" . PHP_EOL;
        }
        $stats['catalog_total'] += $catalogFilled;
    }

    // Catalog-resolved attributes must not also go to AI.
    $authoringRows = array_filter($authoringRows, fn($r) => !isset($draftAttrs[$r['attribute']]));

    // --- Data-gate: require usable product context before spending on AI -------
    // A SKU with no title/description/features would make the model invent ~all
    // its attributes from a SKU string (wasted spend + hallucinated data). Only
    // -FBA/-NCX SKUs are known copies of a base we can borrow context from; any
    // other context-less SKU is flagged needs_human rather than guessed.
    $hasContext    = ($title !== '' || $description !== '' || array_filter($features) !== []);
    $needsHuman    = false;
    $contextSource = 'self';

    if (!$hasContext && !$noDataGate) {
        $borrowed = false;
        if (preg_match(PLACEHOLDER_SUFFIX_RE, $sku)) {
            $baseSku  = preg_replace(PLACEHOLDER_SUFFIX_RE, '', $sku);
            $baseFile = $paths['listings'] . '/' . $baseSku . '.json';
            if (is_file($baseFile)) {
                $baseListing = json_decode(file_get_contents($baseFile), true) ?? [];
                // Only trust the base if it plausibly IS the same product.
                $baseAsin = listingAsin($baseListing);
                $basePt   = listingProductType($baseListing);
                $asinOk   = ($asin === '' || $baseAsin === '' || $baseAsin === $asin);
                $ptOk     = ($productType === '' || $basePt === '' || strcasecmp($basePt, $productType) === 0);
                if ($asinOk && $ptOk) {
                    $bTitle = listingAttr($baseListing, 'item_name');
                    $bDesc  = listingAttr($baseListing, 'product_description');
                    $bFeat  = array_column($baseListing['attributes']['bullet_point'] ?? [], 'value');
                    if ($bTitle !== '' || $bDesc !== '' || array_filter($bFeat) !== []) {
                        if ($title === '')            { $title = $bTitle; }
                        if ($description === '')      { $description = $bDesc; }
                        if (!array_filter($features)) { $features = $bFeat; }
                        $borrowed      = true;
                        $contextSource = 'base_sku:' . $baseSku;
                        echo "  data-gate: borrowed context from base {$baseSku}" . PHP_EOL;
                    }
                }
            }
        }
        if (!$borrowed) {
            $needsHuman = true;
        }
    }

    // --- AI authoring ---
    $aiAttrs      = array_values(array_map(fn($r) => $r['attribute'], $authoringRows));
    $isReqMap     = [];
    foreach ($authoringRows as $row) {
        $isReqMap[$row['attribute']] = $row['is_required'] === 'yes';
    }

    if ($needsHuman) {
        $aiAttrs          = [];
        $needsHumanSkus[] = $sku;
        $stats['needs_human']++;
        echo "  data-gate: [NEEDS HUMAN] {$sku} — no product context, no usable base" . PHP_EOL;
    }

    if ($aiAttrs) {
        // Partition attributes into model batches.
        // auto: haiku for enum/short-string, sonnet for prose, opus for marquee.
        // explicit model: single batch with the override model.
        if ($model === 'auto') {
            $byModel = ['claude-haiku-4-5' => [], 'claude-sonnet-4-6' => [], 'claude-opus-4-8' => []];
            foreach ($aiAttrs as $a) {
                $byModel[pickModel($a, $schemaProps[$a] ?? [])][] = $a;
            }
            $batches    = array_filter($byModel); // drop empty tiers
            $batchLabel = 'auto ('
                . count($byModel['claude-haiku-4-5'])  . ' haiku / '
                . count($byModel['claude-sonnet-4-6']) . ' sonnet / '
                . count($byModel['claude-opus-4-8'])   . ' opus)';
        } else {
            $batches    = [$model => $aiAttrs];
            $batchLabel = $model;
        }

        echo '  fillable=' . count($fillableRows) . ' catalog=' . $catalogFilled . ' ai=' . count($aiAttrs) . ' model=' . $batchLabel . PHP_EOL;

        if ($dryRun) {
            foreach ($batches as $batchModel => $batchAttrs) {
                if (!$batchAttrs) {
                    continue;
                }
                $label      = $model === 'auto' ? basename(str_replace('claude-', '', $batchModel)) : $batchModel;
                $chunks     = array_chunk($batchAttrs, $batchSize);
                $callSuffix = count($chunks) > 1 ? ' (' . count($chunks) . ' calls)' : '';
                echo '  [DRY RUN] Would call ' . $label . $callSuffix . ' for: ' . implode(', ', $batchAttrs) . PHP_EOL;

                // Cost projection: real prompt for input tokens (~chars/4),
                // per-attribute heuristic for output tokens.
                if (isset($costEst[$batchModel])) {
                    foreach ($chunks as $chunk) {
                        $prompt = buildPrompt($sku, $productType, $title, $brand, $description, $features, $chunk, $schemaProps, $prop65Chemicals);
                        $costEst[$batchModel]['in'] += (int) ceil(strlen($prompt) / 4);
                        foreach ($chunk as $a) {
                            $costEst[$batchModel]['out'] += estOutputTokens($a, $schemaProps[$a] ?? []);
                        }
                    }
                }
            }
        } else {
            // $parsed accumulates results across all batches; $attrModels records
            // which model authored each attribute (stored in the draft when auto mode).
            $parsed     = [];
            $attrModels = [];

            foreach ($batches as $batchModel => $batchAttrs) {
                if (!$batchAttrs) {
                    continue;
                }

                foreach ($batchAttrs as $a) {
                    $attrModels[$a] = $batchModel;
                }

                $chunks     = array_chunk($batchAttrs, $batchSize);
                $chunkTotal = count($chunks);

                foreach ($chunks as $chunkIdx => $chunk) {
                    $chunkLabel = $chunkTotal > 1
                        ? ' [' . ($chunkIdx + 1) . '/' . $chunkTotal . ']'
                        : '';

                    $prompt = buildPrompt(
                        $sku,
                        $productType,
                        $title,
                        $brand,
                        $description,
                        $features,
                        $chunk,
                        $schemaProps,
                        $prop65Chemicals
                    );

                    try {
                        assert($anthropic !== null);
                        $message = $anthropic->messages->create(
                            model: $batchModel,
                            maxTokens: 1024,
                            messages: [['role' => 'user', 'content' => $prompt]],
                        );

                        $stats['api_calls']++;

                        $rawText = '';
                        foreach ($message->content as $block) {
                            if ($block->type === 'text') {
                                $rawText = $block->text;
                                break;
                            }
                        }

                        // Strip any markdown fences Claude might include
                        $rawText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
                        $rawText = preg_replace('/\s*```$/m', '', $rawText);
                        $rawText = trim($rawText ?? '');

                        $chunkParsed = json_decode($rawText, true);

                        if (!is_array($chunkParsed)) {
                            echo '  [WARN] Non-JSON response from ' . $batchModel . $chunkLabel . ' for ' . $sku . ': ' . substr($rawText, 0, 200) . PHP_EOL;
                            $stats['errors']++;
                        } else {
                            $parsed = array_merge($parsed, $chunkParsed);
                        }
                    } catch (\Throwable $e) {
                        echo '  [ERROR] API call failed (' . $batchModel . $chunkLabel . ') for ' . $sku . ': ' . $e->getMessage() . PHP_EOL;
                        $stats['errors']++;
                    }
                }
            }

            foreach ($aiAttrs as $attr) {
                $val        = $parsed[$attr] ?? null;
                $isRequired = $isReqMap[$attr] ?? false;
                $entry      = [
                    'value'       => $val,
                    'is_required' => $isRequired,
                    'source'      => 'ai',
                ];

                // Record which model authored this value when auto mode split the batches.
                if ($model === 'auto' && isset($attrModels[$attr])) {
                    $entry['model'] = $attrModels[$attr];
                }

                // Normalize + validate enum (nested value node). Boolean enums
                // arrive as "true"/"Yes"/etc.; map the answer back to the
                // canonical schema value (bool or exact string) before validating.
                $enum = schemaEnum($schemaProps[$attr] ?? []);
                if ($val !== null && $enum) {
                    $enumNames = schemaValueNode($schemaProps[$attr] ?? [])['enumNames'] ?? [];
                    $needle    = strtolower(is_bool($val) ? ($val ? 'true' : 'false') : (string) $val);
                    foreach ($enum as $i => $ev) {
                        $evStr = is_bool($ev) ? ($ev ? 'true' : 'false') : (string) $ev;
                        if ($needle === strtolower($evStr)
                            || (isset($enumNames[$i]) && $needle === strtolower((string) $enumNames[$i]))) {
                            $val            = $ev;
                            $entry['value'] = $ev;
                            break;
                        }
                    }
                    if (!in_array($val, $enum, true)) {
                        $entry['validation_error'] = "'" . var_export($val, true) . "' is not in the schema enum";
                        echo '  [WARN] Invalid enum for ' . $attr . ': ' . var_export($val, true) . PHP_EOL;
                    }
                }

                // Schema-driven max length (modular titles: item_name<=75,
                // title_differentiation<=125 once Amazon updates the schema).
                $maxLen = schemaMaxLength($schemaProps[$attr] ?? []);
                if ($val !== null && is_string($val) && $maxLen !== null && mb_strlen($val) > $maxLen) {
                    $entry['validation_error'] = "value exceeds maxLength {$maxLen} (" . mb_strlen($val) . ' chars)';
                    echo '  [WARN] ' . $attr . ' exceeds maxLength ' . $maxLen . ' (' . mb_strlen($val) . ')' . PHP_EOL;
                }

                $draftAttrs[$attr] = $entry;

                if ($val === null) {
                    $stats['ai_null']++;
                } else {
                    $stats['ai_total']++;
                }
            }
        }
    } else {
        echo '  fillable=' . count($fillableRows) . ' catalog=' . $catalogFilled . ' ai=0' . PHP_EOL;
    }

    // --- Modular item_name suggestion (stakeholder 5) -----------------------
    // Always produce a fresh <=75-char modular item_name for HUMAN REVIEW, stored
    // under a separate key so the live item_name is never touched. Seeded with the
    // best existing title (listing -> catalog -> usurper, via $title) so the model
    // condenses rather than invents. review_only: patch_listings never sends it to
    // Amazon. Skipped when the SKU has no product context (needs_human).
    $seedTitle = $title !== '' ? $title : listingAttr(['attributes' => $catalogAttrs], 'item_name');
    $canSuggestTitle = !$needsHuman
        && isset($schemaProps['item_name'])
        && ($seedTitle !== '' || $description !== '' || array_filter($features) !== []);
    if ($canSuggestTitle) {
        $titlePrompt = buildItemNamePrompt($sku, $productType, $seedTitle, $brand, $description, $features);
        if ($dryRun) {
            if (isset($costEst['claude-opus-4-8'])) {
                $costEst['claude-opus-4-8']['in']  += (int) ceil(strlen($titlePrompt) / 4);
                $costEst['claude-opus-4-8']['out'] += 30;
            }
            echo '  [DRY RUN] Would call opus for modular item_name suggestion' . PHP_EOL;
        } else {
            try {
                assert($anthropic !== null);
                $titleMsg = $anthropic->messages->create(
                    model: 'claude-opus-4-8',
                    maxTokens: 128,
                    messages: [['role' => 'user', 'content' => $titlePrompt]],
                );
                $stats['api_calls']++;

                $titleText = '';
                foreach ($titleMsg->content as $block) {
                    if ($block->type === 'text') {
                        $titleText = $block->text;
                        break;
                    }
                }
                $titleText = preg_replace('/^```(?:json)?\s*/m', '', $titleText);
                $titleText = trim(preg_replace('/\s*```$/m', '', $titleText ?? ''));

                $decoded   = json_decode($titleText, true);
                $suggested = is_array($decoded) ? ($decoded['item_name'] ?? null) : $titleText;

                if (is_string($suggested) && $suggested !== '') {
                    $entry = [
                        'value'       => $suggested,
                        'is_required' => false,
                        'source'      => 'ai',
                        'model'       => 'claude-opus-4-8',
                        'review_only' => true, // never patched — for human review
                        'note'        => 'modular <=75 item_name for human review',
                    ];
                    if (mb_strlen($suggested) > 75) {
                        $entry['validation_error'] = 'exceeds modular item_name cap of 75 chars ('
                            . mb_strlen($suggested) . ')';
                        echo '  [WARN] item_name suggestion exceeds 75 chars (' . mb_strlen($suggested) . ')' . PHP_EOL;
                    }
                    $draftAttrs['item_name_ai_suggested'] = $entry;
                    echo '  item_name: AI modular suggestion (' . mb_strlen($suggested) . ' chars) [review]' . PHP_EOL;
                }
            } catch (\Throwable $e) {
                echo '  [ERROR] item_name suggestion failed for ' . $sku . ': ' . $e->getMessage() . PHP_EOL;
                $stats['errors']++;
            }
        }
    }

    $schemaRequired = $schema['required'] ?? [];

    // --- Placeholder templating pass (Phase 10.4, W6) -----------------------
    // For an -NCX/-FBA SKU, union-fill attributes from its base SKU's Amazon
    // listing snapshot. The base shares this SKU's ASIN/product_type, so the
    // copy is safe by definition. Fill-missing only: anything the placeholder
    // already carries (on its listing or already drafted above) is untouched,
    // and SKU-specific commercial attrs are never copied. Identifying attrs
    // ARE copied into the draft but stay gated behind --include-identifying at
    // patch time (W4b). If the base has no snapshot, fall through: the Usurper
    // and AI passes above already ran as the normal draft path.
    if ($templateMode && preg_match(PLACEHOLDER_SUFFIX_RE, $sku)) {
        $baseSku  = preg_replace(PLACEHOLDER_SUFFIX_RE, '', $sku);
        $baseFile = $paths['listings'] . '/' . $baseSku . '.json';

        if (!file_exists($baseFile)) {
            echo "  template: base {$baseSku} has no listing snapshot — using Usurper/AI path" . PHP_EOL;
        } else {
            $baseListing = json_decode(file_get_contents($baseFile), true) ?? [];
            $baseAttrs   = $baseListing['attributes'] ?? [];
            $ownAttrs    = $listing['attributes'] ?? [];

            $copied     = 0;
            $copiedIdent = 0;
            foreach ($baseAttrs as $attr => $slots) {
                if (in_array($attr, TEMPLATE_NEVER_COPY, true)) {
                    continue; // SKU-specific — never copied
                }
                if (isset($draftAttrs[$attr])) {
                    continue; // already drafted (Usurper/AI/fillable)
                }
                if (!empty($ownAttrs[$attr])) {
                    continue; // placeholder already carries it
                }
                if (empty($slots)) {
                    continue;
                }

                $entry = [
                    'value'       => $slots, // already SP-API slot-shaped
                    'is_required' => in_array($attr, $schemaRequired, true),
                    'source'      => 'base_template',
                    'base_sku'    => $baseSku,
                    'raw_value'   => true, // pass through formatPatchValue unchanged
                ];

                if (IdentifyingAttributes::isIdentifying($attr, $productType, $paths['schemas'])) {
                    $entry['identifying'] = true; // traceable; still gated at patch time
                    $copiedIdent++;
                }

                $draftAttrs[$attr] = $entry;
                $copied++;
            }

            $stats['template_total'] += $copied;
            $identNote = $copiedIdent > 0 ? " ({$copiedIdent} identifying, gated at patch time)" : '';
            echo "  template: copied {$copied} attr(s) from base {$baseSku}{$identNote}" . PHP_EOL;
        }
    }

    // --- Compliance pass (Phase 10, W8) -------------------------------------
    // Guarantee compliance-critical attributes are present in the draft, so
    // "always filled" holds regardless of gap-fill classification. Fill-missing
    // only — an existing compliance value (on the listing or already drafted)
    // is never touched. AI never authors these.
    foreach ($complianceAttrs as $cAttr) {
        $hasResolver = ComplianceResolvers::has($cAttr);
        // In scope: a resolvable attr (prop 65) fills wherever the schema
        // DEFINES it (stakeholder: "always filled"). A no-resolver attr
        // (pesticide_marking) is only in scope where the schema REQUIRES it —
        // it is an optional property on ~half of product types, so defines-it
        // would flag it everywhere.
        $inScope = $hasResolver
            ? isset($schemaProps[$cAttr])
            : in_array($cAttr, $schemaRequired, true);
        if (!$inScope) {
            continue;
        }
        // Never overwrite an existing value (already drafted or on the listing).
        if (isset($draftAttrs[$cAttr])) {
            continue;
        }
        if (!empty($listing['attributes'][$cAttr])) {
            continue;
        }

        if ($hasResolver) {
            $draftAttrs[$cAttr] = [
                'value'      => ComplianceResolvers::resolve($cAttr, $productType, $marketplaceId),
                'is_required' => true,
                'source'     => 'compliance_rule',
                'raw_value'  => true, // already SP-API-shaped; not a plain string
            ];
            echo '  compliance: filled ' . $cAttr . ' by rule' . PHP_EOL;
        } else {
            // No resolver: try Usurper, else flag for human follow-up. Either
            // way patch_listings.php hard-blocks the SKU if it stays unresolved.
            $resolved = ($usurperRow !== null)
                ? resolveFromUsurper($cAttr, $usurperRow, $attrMap)
                : null;
            if ($resolved !== null) {
                $draftAttrs[$cAttr] = [
                    'value'          => $resolved['value'],
                    'is_required'    => true,
                    'source'         => 'usurper',
                    'usurper_column' => $resolved['usurper_column'],
                ];
                echo '  compliance: filled ' . $cAttr . ' from Usurper' . PHP_EOL;
            } else {
                $draftAttrs[$cAttr] = [
                    'value'          => null,
                    'is_required'    => true,
                    'source'         => 'needs_human',
                    'compliance_note' => 'no resolver and not in Usurper — patch will hard-block until sourced',
                ];
                echo '  compliance: [UNRESOLVED] ' . $cAttr . ' — flagged for human follow-up' . PHP_EOL;
            }
        }
    }

    // --- Defaults pass (stakeholder 2 & 3) ----------------------------------
    // Apply the stakeholder default attributes fill-missing only, in scope where
    // the product-type schema defines the attribute. NULL defaults are recorded
    // for review but never patched (patch_listings skips null). VALUE defaults
    // (product_tax_code, unit_count) flow through the normal candidate path.
    foreach ($defaultAttrs as $dAttr => $spec) {
        if (!isset($schemaProps[$dAttr])) {
            continue; // in scope = schema defines it
        }
        if (isset($draftAttrs[$dAttr])) {
            continue; // already drafted (Usurper/catalog/AI/template)
        }
        if (!empty($listing['attributes'][$dAttr])) {
            continue; // placeholder/listing already carries it
        }
        // unit_count (and any 'not_variation' default) is skipped for variation
        // members — its quantity identity belongs to the family, not this child.
        if (($spec['condition'] ?? null) === 'not_variation' && $variationAttrs) {
            echo '  default: skipped ' . $dAttr . ' (variation member)' . PHP_EOL;
            continue;
        }

        $val   = $spec['value'] ?? null;
        $entry = ['is_required' => false, 'source' => 'default'];
        $shape = $spec['shape'] ?? (isset($spec['unit']) ? 'unit' : null);

        switch ($shape) {
            case 'unit':
                // Shaped numeric+unit value (unit_count): pass through unchanged.
                $entry['value'] = [[
                    'value'          => $val,
                    'type'           => ['language_tag' => 'en_US', 'value' => $spec['unit']],
                    'marketplace_id' => $marketplaceId,
                ]];
                $entry['raw_value'] = true;
                break;
            case 'value':
                // Single-value slot (boolean or enum string), e.g. ships_globally.
                $entry['value']     = [['value' => $val, 'marketplace_id' => $marketplaceId]];
                $entry['raw_value'] = true;
                break;
            case 'localized':
                // Localized string slot; language_tag is schema-required here.
                $entry['value'] = [[
                    'value'          => $val,
                    'language_tag'   => 'en_US',
                    'marketplace_id' => $marketplaceId,
                ]];
                $entry['raw_value'] = true;
                break;
            case 'gift_options':
                // Two-boolean slot (gift message / gift wrap).
                $entry['value'] = [[
                    'can_be_messaged' => (bool) ($spec['can_be_messaged'] ?? false),
                    'can_be_wrapped'  => (bool) ($spec['can_be_wrapped'] ?? false),
                    'marketplace_id'  => $marketplaceId,
                ]];
                $entry['raw_value'] = true;
                break;
            default:
                $entry['value'] = $val; // scalar (e.g. A_GEN_TAX) or null
        }

        $entry['note'] = $spec['note']
            ?? ($val === null && $shape === null ? 'stakeholder default; not patched' : 'stakeholder default');

        $draftAttrs[$dAttr] = $entry;
        $shown = $shape === 'gift_options'
            ? 'shaped (gift_options)'
            : ($shape !== null ? 'shaped (' . var_export($val, true) . ')'
                : ($val === null ? 'null (document-only)' : (is_scalar($val) ? (string) $val : 'shaped')));
        echo '  default: ' . $dAttr . ' = ' . $shown . PHP_EOL;
    }

    // --- Modular-title coupling (Amazon 2026-07-27) -------------------------
    // title_differentiation is only valid when the effective item_name is <=75
    // chars, and the two together must be <=200. Flag violations so they never
    // reach the write path (mirrors the enum/maxLength validation above).
    if (isset($draftAttrs['title_differentiation'])
        && is_string($draftAttrs['title_differentiation']['value'] ?? null)) {
        $effItemName = (isset($draftAttrs['item_name']['value']) && is_string($draftAttrs['item_name']['value']))
            ? $draftAttrs['item_name']['value']
            : $title; // fall back to listing/usurper/base title
        $tdLen = mb_strlen($draftAttrs['title_differentiation']['value']);
        if ($effItemName !== '' && mb_strlen($effItemName) > 75) {
            $draftAttrs['title_differentiation']['validation_error'] = 'requires item_name <= 75 chars';
            echo '  [WARN] title_differentiation flagged — item_name > 75 chars' . PHP_EOL;
        } elseif ($effItemName !== '' && mb_strlen($effItemName) + $tdLen > 200) {
            $draftAttrs['title_differentiation']['validation_error'] = 'item_name + title_differentiation exceeds 200 chars';
            echo '  [WARN] title_differentiation flagged — combined title > 200 chars' . PHP_EOL;
        }
    }

    if ($dryRun) {
        continue;
    }

    // --- Write draft ---
    $draft = [
        'sku'            => $sku,
        'asin'           => $asin,
        'product_type'   => $productType,
        'account'        => $account,
        'generated_at'   => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'model'          => $model,
        'status'         => $needsHuman ? 'needs_human' : 'ok',
        'context_source' => $contextSource,
        'totals'         => [
            'fillable'      => count(array_filter($draftAttrs, fn($a) => $a['source'] === 'usurper')),
            'catalog'       => count(array_filter($draftAttrs, fn($a) => $a['source'] === 'catalog')),
            'ai_suggested'  => count(array_filter($draftAttrs, fn($a) => $a['source'] === 'ai' && $a['value'] !== null)),
            'ai_null'       => count(array_filter($draftAttrs, fn($a) => $a['source'] === 'ai' && $a['value'] === null)),
            'base_template' => count(array_filter($draftAttrs, fn($a) => $a['source'] === 'base_template')),
        ],
        'attributes'   => $draftAttrs,
    ];

    file_put_contents(
        $draftFile,
        json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    );

    $stats['written']++;
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo PHP_EOL;
echo '─────────────────────────────────────────' . PHP_EOL;

if ($dryRun) {
    echo 'DRY RUN complete — no files written, no API calls made.' . PHP_EOL;
    echo 'Catalog resolved (free)    : ' . $stats['catalog_total'] . PHP_EOL;
    echo 'Skipped (recommended tail) : ' . $stats['skipped_recommended'] . PHP_EOL;
    echo 'needs_human (no context)   : ' . $stats['needs_human'] . PHP_EOL;
    echo PHP_EOL;

    $total = 0.0;
    echo 'Estimated cost (rough — heuristic output tokens):' . PHP_EOL;
    foreach ($costEst as $mId => $t) {
        if ($t['in'] === 0 && $t['out'] === 0) {
            continue;
        }
        $rate = MODEL_RATES[$mId];
        $cost = $t['in'] / 1e6 * $rate['in'] + $t['out'] / 1e6 * $rate['out'];
        $total += $cost;
        printf(
            "  %-8s in~%-10s out~%-10s \$%.4f%s",
            basename(str_replace('claude-', '', $mId)),
            number_format($t['in']),
            number_format($t['out']),
            $cost,
            PHP_EOL,
        );
    }
    printf('  %-8s %27s$%.4f%s', 'TOTAL', '', $total, PHP_EOL);
    echo PHP_EOL;
    echo 'Remove --dry-run to generate drafts.' . PHP_EOL;
} else {
    echo 'Drafts written      : ' . $stats['written'] . PHP_EOL;
    echo 'Skipped (exist)     : ' . $stats['skipped'] . PHP_EOL;
    echo 'Skipped (rec. tail) : ' . $stats['skipped_recommended'] . PHP_EOL;
    echo 'needs_human         : ' . $stats['needs_human'] . PHP_EOL;
    echo 'API calls           : ' . $stats['api_calls'] . PHP_EOL;
    echo 'Fillable resolved   : ' . $stats['fillable_total'] . PHP_EOL;
    echo 'Catalog resolved    : ' . $stats['catalog_total'] . PHP_EOL;
    echo 'AI values suggested : ' . $stats['ai_total'] . PHP_EOL;
    echo 'AI returned null    : ' . $stats['ai_null'] . PHP_EOL;
    if ($templateMode) {
        echo 'Base-template copied: ' . $stats['template_total'] . PHP_EOL;
    }
    if ($stats['errors'] > 0) {
        echo 'Errors              : ' . $stats['errors'] . PHP_EOL;
    }
    if ($needsHumanSkus) {
        echo PHP_EOL;
        echo 'needs_human SKUs (no context, no base match):' . PHP_EOL;
        foreach (array_slice($needsHumanSkus, 0, 20) as $s) {
            echo '  - ' . $s . PHP_EOL;
        }
        if (count($needsHumanSkus) > 20) {
            echo '  … +' . (count($needsHumanSkus) - 20) . ' more' . PHP_EOL;
        }
    }
    echo PHP_EOL;
    echo 'Drafts dir: ' . $paths['drafts'] . PHP_EOL;
}
