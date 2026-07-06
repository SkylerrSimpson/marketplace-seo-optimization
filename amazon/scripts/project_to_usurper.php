<?php

declare(strict_types=1);

/**
 * Phase 9 — Project AI-authored draft attributes back to Usurper CSV format.
 *
 * Reads  amazon/data/{account}/drafts/{sku}.json  (Phase 7 output) and emits
 * a Usurper-compatible CSV keyed by sku with attr.* column names. Import the
 * output CSV into Usurper to persist AI-authored values alongside native
 * product data.
 *
 * Column resolution rules (per attribute):
 *   source:ai
 *     1. UsurperAttributeMap entry exists → use the first candidate column
 *        (the one the gap-fill resolver would have checked first).
 *     2. No map entry → attr.amazon_{attribute_name} convention.
 *        Usurper finds the matching custom attribute model or creates it.
 *   source:usurper (default: skipped — values already exist in Usurper)
 *     Pass --include-usurper to also write these (useful for a full refresh).
 *
 * After import, the Usurper InventoryExport CSV will carry the new attr.*
 * columns on the next export. Subsequent gap-fill runs will then classify
 * those attributes as "fillable" instead of "needs_authoring", reducing
 * future AI API spend to near-zero for known products.
 *
 * Usage:
 *   php amazon/scripts/project_to_usurper.php [--account=IGE|DOWS] [OPTIONS]
 *
 * Flags:
 *   --account=IGE|DOWS    Seller account. Default: IGE.
 *   --sku=SKU             Process a single SKU only (for testing).
 *   --include-usurper     Also write usurper-sourced values (full refresh).
 *   --help                Show this help message.
 *
 * Inputs:
 *   amazon/data/{account}/drafts/{sku}.json
 *
 * Output:
 *   amazon/data/{account}/output/usurper_update_{timestamp}.csv
 */

require __DIR__ . '/../../lib/bootstrap.php';

// ---------------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php amazon/scripts/project_to_usurper.php [--account=IGE|DOWS] [OPTIONS]

Flags:
  --account=IGE|DOWS    Seller account. Default: IGE.
  --sku=SKU             Process a single SKU only.
  --include-usurper     Also write usurper-sourced values (full attribute refresh).
  --help                Show this help message.

Column naming convention:
  Known attributes (in UsurperAttributeMap) → existing attr.* column name
  Unknown attributes (AI-only)              → attr.amazon_{attribute_name}
HELP;
    echo PHP_EOL;
    exit(0);
}

$account        = 'IGE';
$singleSku      = null;
$includeUsurper = false;

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    } elseif (preg_match('/^--sku=(.+)$/', $arg, $m)) {
        $singleSku = $m[1];
    } elseif ($arg === '--include-usurper') {
        $includeUsurper = true;
    }
}

$paths   = amazon_paths($account);
$attrMap = require __DIR__ . '/../../lib/UsurperAttributeMap.php';

echo 'Account         : ' . $account . PHP_EOL;
echo 'Include usurper : ' . ($includeUsurper ? 'yes' : 'no (AI-authored only)') . PHP_EOL;
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Resolve the Usurper column name to write an attribute value into.
 *
 * For AI-authored attributes: check the attribute map first (existing Usurper
 * column), then fall back to the attr.amazon_* convention.
 * For usurper-sourced attributes: use the column the value came from (already
 * stored in the draft's usurper_column field).
 */
function usurperColumnFor(string $attr, array $attrMap): string
{
    $candidates = $attrMap[$attr] ?? [];
    // For multi-source attrs (bullet_point), write to the first/primary column.
    return $candidates ? $candidates[0] : 'attr.amazon_' . $attr;
}

// ---------------------------------------------------------------------------
// Load draft files
// ---------------------------------------------------------------------------

$pattern    = $singleSku
    ? $paths['drafts'] . '/' . $singleSku . '.json'
    : $paths['drafts'] . '/*.json';
$draftFiles = glob($pattern) ?: [];

if (!$draftFiles) {
    $loc = $singleSku ? "'{$singleSku}.json'" : 'any drafts';
    echo "No draft files found ({$loc}) in " . $paths['drafts'] . PHP_EOL;
    echo 'Run draft_listings.php --account=' . $account . ' first.' . PHP_EOL;
    exit(0);
}

echo 'Draft files found : ' . count($draftFiles) . PHP_EOL;

// ---------------------------------------------------------------------------
// Build projection rows
// ---------------------------------------------------------------------------

$rows    = []; // [['sku' => ..., 'attr.foo' => ..., ...], ...]
$allCols = ['sku']; // union of all column names, in encounter order

foreach ($draftFiles as $draftFile) {
    $draft = json_decode(file_get_contents($draftFile), true) ?? [];
    $sku   = $draft['sku'] ?? basename($draftFile, '.json');

    $row = ['sku' => $sku];

    foreach ($draft['attributes'] ?? [] as $attr => $entry) {
        $source = $entry['source'] ?? '';
        $value  = $entry['value'] ?? null;

        if ($source === 'usurper') {
            if (!$includeUsurper) {
                continue;
            }
            // For usurper-sourced values, use the column recorded at draft time.
            if (is_array($value)) {
                // bullet_point: spread across feature01..feature05
                $featureCols = [
                    'attr.feature01', 'attr.feature02', 'attr.feature03',
                    'attr.feature04', 'attr.feature05',
                ];
                foreach ($value as $i => $v) {
                    if (!isset($featureCols[$i])) {
                        break;
                    }
                    $row[$featureCols[$i]] = (string) $v;
                    if (!in_array($featureCols[$i], $allCols, true)) {
                        $allCols[] = $featureCols[$i];
                    }
                }
            } else {
                // Use usurper_column recorded in the draft (the authoritative source).
                $col = $entry['usurper_column'] ?? usurperColumnFor($attr, $attrMap);
                if ($value !== null) {
                    $row[$col] = (string) $value;
                    if (!in_array($col, $allCols, true)) {
                        $allCols[] = $col;
                    }
                }
            }
            continue;
        }

        if ($source === 'ai') {
            // Skip null values — Claude could not determine a value.
            if ($value === null) {
                continue;
            }

            if ($attr === 'bullet_point') {
                // AI suggested a single bullet string → write to feature01.
                $col = 'attr.feature01';
                $row[$col] = is_array($value) ? implode(' ', $value) : (string) $value;
            } else {
                $col = usurperColumnFor($attr, $attrMap);
                $row[$col] = (string) $value;
            }

            if (!in_array($col, $allCols, true)) {
                $allCols[] = $col;
            }
        }
    }

    // Only include the row if we have something beyond the sku key.
    if (count($row) > 1) {
        $rows[] = $row;
    }
}

if (!$rows) {
    echo PHP_EOL . 'Nothing to project.' . PHP_EOL;
    if (!$includeUsurper) {
        echo 'All draft values are usurper-sourced. Pass --include-usurper to write them too.' . PHP_EOL;
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Write CSV
// ---------------------------------------------------------------------------

$ts      = date('Y-m-d-H-i-s');
$outFile = $paths['output'] . '/usurper_update_' . $ts . '.csv';
$fh      = fopen($outFile, 'w');

fputcsv($fh, $allCols);

foreach ($rows as $row) {
    $line = [];
    foreach ($allCols as $col) {
        $line[] = $row[$col] ?? '';
    }
    fputcsv($fh, $line);
}

fclose($fh);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$skuCount = count($rows);
$colCount = count($allCols) - 1; // exclude sku column

$aiColCount     = 0;
$amazonColCount = 0;
foreach ($allCols as $col) {
    if ($col === 'sku') {
        continue;
    }
    if (str_starts_with($col, 'attr.amazon_')) {
        $amazonColCount++;
    } else {
        $aiColCount++;
    }
}

echo PHP_EOL;
echo '─────────────────────────────────────────' . PHP_EOL;
echo 'SKUs projected        : ' . $skuCount . PHP_EOL;
echo 'Columns written       : ' . $colCount . PHP_EOL;
echo '  Known attr.* cols   : ' . ($colCount - $amazonColCount) . PHP_EOL;
echo '  attr.amazon_* cols  : ' . $amazonColCount . PHP_EOL;
echo PHP_EOL;
echo 'CSV → ' . $outFile . PHP_EOL;
echo PHP_EOL;
echo 'Import this file into Usurper to persist AI-authored values.' . PHP_EOL;
echo 'attr.amazon_* columns will be created as custom attributes on first import.' . PHP_EOL;
