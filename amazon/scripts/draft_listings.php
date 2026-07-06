<?php

declare(strict_types=1);

use Anthropic\Client;

/**
 * Phase 7 — AI-assisted draft generation.
 *
 * Ingests the gap-fill analysis (Phase 6 output), resolves "fillable"
 * attributes directly from the Usurper catalog, and calls the Anthropic API
 * to suggest values for "needs_authoring" attributes using schema constraints.
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
 *   amazon/data/schemas/{PRODUCT_TYPE}.json
 *
 * Output:
 *   amazon/data/{account}/drafts/{sku}.json
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/ComplianceResolvers.php';
require __DIR__ . '/../../lib/IdentifyingAttributes.php';

// Compliance-critical attributes that must always be present (Phase 10, 4c).
$complianceAttrs = require __DIR__ . '/../../lib/ComplianceAttributes.php';

// US marketplace by default (both seller accounts are US); compliance values
// are marketplace-scoped so the fill needs an id even though drafting is offline.
$marketplaceId = $_ENV['AMAZON_SPAPI_MARKETPLACE_ID'] ?? 'ATVPDKIKX0DER';

// -NCX/-FBA placeholder templating (Phase 10.4, W6). A placeholder SKU is
// detected by this suffix; its "base" is the same SKU with the suffix stripped.
// The base shares the placeholder's ASIN/product_type/title, so copying its
// attributes is safe by definition.
const PLACEHOLDER_SUFFIX_RE = '/-(NCX|FBA)$/';

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
                              auto             — haiku for enum-constrained attributes,
                                                 opus for open-ended prose (recommended)
                              claude-haiku-4-5 — haiku for all AI attributes
                              claude-opus-4-8  — opus for all AI attributes
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
  --dry-run                 Preview only; no API calls, no files written.
  --help                    Show this help message.

Cost guidance (approximate, per-SKU):
  auto               mix of haiku + opus (cheapest correct default)
  claude-haiku-4-5   ~$0.002  (use when catalog is mostly enum-constrained)
  claude-opus-4-8    ~$0.012  (use when catalog is mostly open-ended prose)
HELP;
    echo PHP_EOL;
    exit(0);
}

$account      = 'IGE';
$model        = 'auto';
$batchSize    = 20;
$singleSku    = null;
$force        = false;
$dryRun       = false;
$templateMode = false;

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
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$paths = amazon_paths($account);

echo 'Account    : ' . $account . PHP_EOL;
echo 'Model      : ' . ($model === 'auto'
    ? 'auto (haiku for enum-constrained, opus for open-ended)'
    : $model) . PHP_EOL;
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

$usurperDir   = $paths['input'] . '/usurper';
$usurperFiles = glob($usurperDir . '/*.csv') ?: [];
$usurper      = []; // [sku => [col => val]]

if ($usurperFiles) {
    usort($usurperFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $usurperFile = $usurperFiles[0];
    echo 'Usurper file   : ' . basename($usurperFile) . PHP_EOL;

    $probe     = fopen($usurperFile, 'r');
    $firstLine = fgets($probe);
    fclose($probe);
    $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';

    $fhU   = fopen($usurperFile, 'r');
    $uHead = fgetcsv($fhU, 0, $delimiter);
    $uHead = array_map('trim', $uHead ?: []);

    while (($row = fgetcsv($fhU, 0, $delimiter)) !== false) {
        if (count($row) !== count($uHead)) {
            continue;
        }
        $rec = array_combine($uHead, $row);
        $s   = trim($rec['sku'] ?? '');
        if ($s !== '') {
            $usurper[$s] = $rec;
        }
    }
    fclose($fhU);
    echo 'Usurper records: ' . count($usurper) . PHP_EOL;
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
 * Format one attribute's schema constraints as a prompt hint.
 */
function schemaHint(string $attr, array $prop): string
{
    $lines = ['Attribute: ' . $attr];

    $desc = $prop['description'] ?? '';
    if ($desc !== '') {
        $lines[] = 'Description: ' . $desc;
    }

    $enum      = $prop['enum'] ?? [];
    $enumNames = $prop['enumNames'] ?? [];
    if ($enum) {
        $pairs = [];
        foreach ($enum as $i => $val) {
            $label   = $enumNames[$i] ?? '';
            $pairs[] = $label !== '' ? "{$val} ({$label})" : $val;
        }
        $lines[] = 'ALLOWED VALUES (pick exactly one): ' . implode(', ', $pairs);
    } elseif ($prop['examples'] ?? []) {
        $examples = array_slice((array) $prop['examples'], 0, 5);
        $lines[]  = 'Examples: ' . implode(', ', $examples);
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
    array  $schemaProps
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
{$featuresText}
=== MISSING ATTRIBUTES ==={$attrsBlock}
=== INSTRUCTIONS ===
- Suggest one value per attribute based on the product context.
- For attributes with ALLOWED VALUES, return exactly one of those values verbatim.
- If you truly cannot determine a suitable value, return null for that key.
- Return ONLY a valid JSON object. Keys are attribute names, values are strings or null.
- Do not include markdown fences, code blocks, or any explanation — just the JSON object.

Required keys in your response: {$attrList}
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
    'skipped'       => 0,
    'written'       => 0,
    'api_calls'     => 0,
    'fillable_total' => 0,
    'ai_total'      => 0,
    'ai_null'       => 0,
    'template_total' => 0,
    'errors'        => 0,
];

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

    $draftAttrs = [];

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

    // --- AI authoring ---
    $aiAttrs      = array_values(array_map(fn($r) => $r['attribute'], $authoringRows));
    $isReqMap     = [];
    foreach ($authoringRows as $row) {
        $isReqMap[$row['attribute']] = $row['is_required'] === 'yes';
    }

    if ($aiAttrs) {
        // Partition attributes into model batches.
        // auto: haiku for enum-constrained (cheap, accurate), opus for open-ended prose.
        // explicit model: single batch with the override model.
        if ($model === 'auto') {
            $batches = [
                'claude-haiku-4-5' => array_values(array_filter($aiAttrs, fn($a) =>  isset($schemaProps[$a]['enum']))),
                'claude-opus-4-8'  => array_values(array_filter($aiAttrs, fn($a) => !isset($schemaProps[$a]['enum']))),
            ];
            $batchLabel = 'auto ('
                . count($batches['claude-haiku-4-5']) . ' haiku / '
                . count($batches['claude-opus-4-8'])  . ' opus)';
        } else {
            $batches    = [$model => $aiAttrs];
            $batchLabel = $model;
        }

        echo '  fillable=' . count($fillableRows) . ' ai=' . count($aiAttrs) . ' model=' . $batchLabel . PHP_EOL;

        if ($dryRun) {
            foreach ($batches as $batchModel => $batchAttrs) {
                if (!$batchAttrs) {
                    continue;
                }
                $label      = $model === 'auto' ? basename(str_replace('claude-', '', $batchModel)) : 'Claude';
                $callCount  = (int) ceil(count($batchAttrs) / $batchSize);
                $callSuffix = $callCount > 1 ? ' (' . $callCount . ' calls)' : '';
                echo '  [DRY RUN] Would call ' . $label . $callSuffix . ' for: ' . implode(', ', $batchAttrs) . PHP_EOL;
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
                        $schemaProps
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

                // Validate enum constraints
                $enum = $schemaProps[$attr]['enum'] ?? [];
                if ($val !== null && $enum && !in_array($val, $enum, true)) {
                    $entry['validation_error'] = "'{$val}' is not in the schema enum";
                    echo '  [WARN] Invalid enum for ' . $attr . ': ' . $val . PHP_EOL;
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
        echo '  fillable=' . count($fillableRows) . ' ai=0' . PHP_EOL;
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

    if ($dryRun) {
        continue;
    }

    // --- Write draft ---
    $draft = [
        'sku'          => $sku,
        'asin'         => $asin,
        'product_type' => $productType,
        'account'      => $account,
        'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'model'        => $model,
        'totals'       => [
            'fillable'      => count(array_filter($draftAttrs, fn($a) => $a['source'] === 'usurper')),
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
    echo 'Remove --dry-run to generate drafts.' . PHP_EOL;
} else {
    echo 'Drafts written      : ' . $stats['written'] . PHP_EOL;
    echo 'Skipped (exist)     : ' . $stats['skipped'] . PHP_EOL;
    echo 'API calls           : ' . $stats['api_calls'] . PHP_EOL;
    echo 'Fillable resolved   : ' . $stats['fillable_total'] . PHP_EOL;
    echo 'AI values suggested : ' . $stats['ai_total'] . PHP_EOL;
    echo 'AI returned null    : ' . $stats['ai_null'] . PHP_EOL;
    if ($templateMode) {
        echo 'Base-template copied: ' . $stats['template_total'] . PHP_EOL;
    }
    if ($stats['errors'] > 0) {
        echo 'Errors              : ' . $stats['errors'] . PHP_EOL;
    }
    echo PHP_EOL;
    echo 'Drafts dir: ' . $paths['drafts'] . PHP_EOL;
}
