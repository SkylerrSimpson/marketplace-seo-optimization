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
 *   source:catalog (authoritative Amazon catalog backfill; written by default)
 *     Same column resolution as source:ai, but the draft value is SP-API-shaped
 *     (a list of {value,…} slots). catalogCells() flattens it to scalar cell(s),
 *     decomposing composite dimension slots (item_dimensions) into their per-axis
 *     item_length/width/height columns. Shapes with no flat cell are held out.
 *   source:usurper (default: skipped — values already exist in Usurper)
 *     Pass --include-usurper to also write these (useful for a full refresh).
 *
 * After import, the Usurper InventoryExport CSV will carry the new attr.*
 * columns on the next export. Subsequent gap-fill runs will then classify
 * those attributes as "fillable" instead of "needs_authoring", reducing
 * future AI API spend to near-zero for known products.
 *
 * Non-destructive by construction:
 *   Usurper writes any present header with a blank cell AS blank, so a union
 *   header (columns one SKU authored but another didn't) would clear live data.
 *   To prevent that, every un-authored cell is backfilled with the SKU's current
 *   value from the latest export (a no-op on import). A SKU that can't be
 *   confirmed in the export — genuinely new, or an export row that couldn't be
 *   parsed — is held out of the update file entirely and written to a separate
 *   review-only file, since we can't safely fill its un-authored cells.
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
 *   amazon/data/{account}/input/usurper/*.csv   (latest by mtime; for backfill)
 *
 * Output:
 *   amazon/data/{account}/output/usurper_update_{timestamp}.csv          (import)
 *   amazon/data/{account}/output/usurper_new_or_unresolved_{ts}.csv      (review)
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/UsurperExport.php';

/**
 * Headers Usurper requires on every import row. These must be present (and
 * populated) or the import is rejected, so we always carry them and backfill
 * their values from the current export.
 */
const REQUIRED_USURPER_HEADERS = ['sku', 'name', 'sku_type'];

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

/**
 * Set a cell on a row and register the column in the union header.
 */
function setCol(array &$row, array &$allCols, string $col, string $val): void
{
    $row[$col] = $val;
    if (!in_array($col, $allCols, true)) {
        $allCols[] = $col;
    }
}

/**
 * Sequential feature columns: attr.feature01, attr.feature02, ... attr.feature{n}.
 * Used as a fallback when no source columns were recorded (AI-authored bullets).
 * The count is driven by the data — Usurper products carry an arbitrary number
 * of featureXX bullets, not a fixed five.
 *
 * @return list<string>
 */
function featureColumns(int $n): array
{
    $cols = [];
    for ($i = 1; $i <= $n; $i++) {
        $cols[] = sprintf('attr.feature%02d', $i);
    }
    return $cols;
}

/**
 * Write a list of bullet values into target columns, 1:1 by index. Empty
 * bullets are skipped so we never emit a blank feature cell (which Usurper
 * would treat as clearing that bullet). $cols supplies the destination column
 * for each bullet; when it runs short we extend with sequential feature columns.
 */
function writeBullets(array $bullets, array $cols, array &$row, array &$allCols): void
{
    $bullets = array_values($bullets);
    $cols    = array_values($cols);
    if (count($cols) < count($bullets)) {
        $cols = featureColumns(count($bullets));
    }
    foreach ($bullets as $i => $v) {
        $v = (string) $v;
        if ($v === '' || !isset($cols[$i])) {
            continue;
        }
        setCol($row, $allCols, $cols[$i], $v);
    }
}

/**
 * Amazon attribute → Usurper column for the sub-axes of a composite dimension
 * value (item_dimensions / package_dimensions). Each axis is routed through the
 * attribute map so it lands in its own numeric column (attr.length_inches, …).
 */
const DIMENSION_SUBKEY_ATTR = [
    'length'   => 'item_length',
    'width'    => 'item_width',
    'height'   => 'item_height',
    'depth'    => 'item_display_depth',
    'diameter' => 'item_display_diameter',
];

/**
 * Flatten a catalog (SP-API) attribute value — a list of slot objects — into a
 * map of Usurper column => scalar string, ready for setCol(). Catalog values are
 * this ASIN's own authoritative Amazon record, new to Usurper, so they are
 * written by default (like AI) to persist them for the next gap-fill.
 *
 *   simple / single-measurement slot  {value, unit?, language_tag?, marketplace_id?}
 *       → one cell in the attribute's own column
 *   composite dimension slot          {length:{value,unit}, width:{…}, height:{…}}
 *       → one cell per axis, each routed to its item_{axis} column
 *
 * The first non-empty value wins per column; a shape we can't flatten (no scalar
 * and no known sub-axis) yields no cells and is held out by the caller.
 *
 * @param  list<mixed> $slots
 * @return array<string, string>
 */
function catalogCells(string $attr, array $slots, array $attrMap): array
{
    $cells = [];
    $put   = static function (string $col, mixed $v) use (&$cells): void {
        if (isset($cells[$col])) {
            return; // first slot wins
        }
        $s = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        if ($s !== '') {
            $cells[$col] = $s; // never emit a blank (would clear the live cell)
        }
    };

    foreach ($slots as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        // Simple scalar or single measurement: the slot carries the value directly.
        if (array_key_exists('value', $slot) && !is_array($slot['value'])) {
            $put(usurperColumnFor($attr, $attrMap), $slot['value']);
            continue;
        }
        // Composite: pull each known sub-axis {value, unit} into its own column.
        foreach (DIMENSION_SUBKEY_ATTR as $subKey => $subAttr) {
            $sub = $slot[$subKey] ?? null;
            if (is_array($sub) && isset($sub['value']) && !is_array($sub['value'])) {
                $put(usurperColumnFor($subAttr, $attrMap), $sub['value']);
            }
        }
    }

    return $cells;
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

$rows           = []; // [['sku' => ..., 'attr.foo' => ..., ...], ...]
$allCols        = ['sku']; // union of all column names, in encounter order
$skippedCatalog = []; // [sku => [attr, ...]] catalog values with no flat cell

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
            // For usurper-sourced values, use the column(s) recorded at draft time.
            if (is_array($value)) {
                // bullet_point: spread each bullet back to the exact featureXX
                // column it came from (usurper_column records them in order).
                $cols = array_values(array_filter(
                    array_map('trim', explode(',', (string) ($entry['usurper_column'] ?? '')))
                ));
                writeBullets($value, $cols, $row, $allCols);
            } elseif ($value !== null) {
                // Use usurper_column recorded in the draft (the authoritative source).
                $col = $entry['usurper_column'] ?? usurperColumnFor($attr, $attrMap);
                setCol($row, $allCols, $col, (string) $value);
            }
            continue;
        }

        if ($source === 'catalog') {
            // Authoritative Amazon data for this ASIN, new to Usurper — write it
            // by default (like AI) so the next gap-fill resolves it from Usurper
            // instead of re-fetching. Values are SP-API slot lists; flatten to
            // scalar cell(s), decomposing composite shapes (item_dimensions) into
            // their per-axis columns. Shapes we can't flatten are held out.
            $cells = is_array($value) ? catalogCells($attr, $value, $attrMap) : [];
            if (!$cells) {
                $skippedCatalog[$sku][] = $attr;
                continue;
            }
            foreach ($cells as $col => $val) {
                setCol($row, $allCols, $col, $val);
            }
            continue;
        }

        if ($source === 'ai') {
            // Skip null values — Claude could not determine a value.
            if ($value === null) {
                continue;
            }

            if ($attr === 'bullet_point') {
                // Spread AI-authored bullets across sequential feature columns.
                // (AI only authors bullet_point when the product has none, so
                // there are no recorded source columns to preserve.)
                $bullets = is_array($value) ? $value : [$value];
                writeBullets($bullets, [], $row, $allCols);
            } else {
                setCol($row, $allCols, usurperColumnFor($attr, $attrMap), (string) $value);
            }
        }
    }

    // Only include the row if we have something beyond the sku key.
    if (count($row) > 1) {
        $rows[] = $row;
    }
}

if ($skippedCatalog) {
    $skuList = implode(', ', array_keys($skippedCatalog));
    $attrSet = array_unique(array_merge(...array_values($skippedCatalog)));
    echo 'Note            : held out ' . count($attrSet)
        . ' catalog attr(s) with no flat cell (' . implode(', ', $attrSet)
        . ') on ' . count($skippedCatalog) . ' SKU(s): ' . $skuList . PHP_EOL;
}

if (!$rows) {
    echo PHP_EOL . 'Nothing to project.' . PHP_EOL;
    if (!$includeUsurper) {
        echo 'All draft values are usurper-sourced. Pass --include-usurper to write them too.' . PHP_EOL;
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Backfill un-authored cells from the current Usurper export
// ---------------------------------------------------------------------------
//
// Usurper writes any present column with a blank cell AS blank — a blank clears
// the existing value. Because $allCols is the union of every column any SKU
// authored, a SKU that didn't author a given column would otherwise emit a
// blank there and wipe its live value on import. To make the import a no-op for
// un-authored cells, we fill each one with that SKU's current export value.

// Guarantee Usurper's required headers exist and lead the column order.
$ordered = REQUIRED_USURPER_HEADERS;
foreach ($allCols as $col) {
    if (!in_array($col, $ordered, true)) {
        $ordered[] = $col;
    }
}
$allCols = $ordered;

// Load only the columns we will write, for only the SKUs being projected — keeps
// the footprint tiny even against the ~700-column export.
$exportFile = usurper_latest_export($paths['usurper']);
if ($exportFile === null) {
    echo PHP_EOL;
    echo 'ERROR: no Usurper export CSV in ' . $paths['usurper'] . PHP_EOL;
    echo 'Un-authored cells cannot be backfilled, so every blank cell would CLEAR' . PHP_EOL;
    echo 'the existing value on import. Aborting to avoid data loss.' . PHP_EOL;
    exit(1);
}

echo 'Usurper export  : ' . basename($exportFile) . PHP_EOL;
$loaded    = usurper_load_export($exportFile, $allCols);
$existing  = $loaded['records'];
$malformed = $loaded['malformed'];
echo 'Export records  : ' . count($existing) . PHP_EOL;

// Split rows by whether we have a current export row to backfill from. A SKU we
// can't confirm in the export must NOT go into the shared-header update file: its
// un-authored cells would be written blank and clear live data. Distinguish two
// held-out cases so a data problem doesn't hide behind "new SKU":
//   - unparsable: present in the export but its row couldn't be aligned
//     (data-quality issue to fix at the source),
//   - new: genuinely absent from the export (a new product to create).
$backfillRows = [];
$unresolved   = [];
$unparsable   = [];
foreach ($rows as $row) {
    $sku = $row['sku'];
    if (isset($existing[$sku])) {
        $backfillRows[] = $row;
    } else {
        $unresolved[] = $row;
        if (isset($malformed[$sku])) {
            $unparsable[] = $sku;
        }
    }
}
if ($unresolved) {
    echo 'Held out        : ' . count($unresolved)
        . ' SKU(s) not in the update file (' . count($unparsable)
        . ' unparsable export row, ' . (count($unresolved) - count($unparsable))
        . ' new to Usurper).' . PHP_EOL;
}

// ---------------------------------------------------------------------------
// Write CSV
// ---------------------------------------------------------------------------

$ts      = date('Y-m-d-H-i-s');
$outFile = $paths['output'] . '/usurper_update_' . $ts . '.csv';
$fh      = fopen($outFile, 'w');

// Write strict RFC-4180: enclosure '"', escape DISABLED. This must mirror how
// UsurperExport.php READS (escape=''), or the round-trip desyncs: a value with a
// backslash (e.g. a mangled inch mark `Size: 3\"`) written with PHP's default
// backslash escape re-parses one field short. Empty escape doubles quotes and
// leaves backslashes literal — the shape Usurper's import and our own reader expect.
fputcsv($fh, $allCols, ',', '"', '');

$backfilled    = 0;
$blankRequired = []; // update rows missing a required header even after backfill
foreach ($backfillRows as $row) {
    $exportRow = $existing[$row['sku']];
    $line      = [];
    $cells     = [];
    foreach ($allCols as $col) {
        if (array_key_exists($col, $row)) {
            $cells[$col] = $row[$col];
            continue;
        }
        // Un-authored cell: write the SKU's current value back unchanged (a blank
        // here would clear it). Missing column in the export → '' (a new
        // attr.amazon_* attribute created on import; nothing existed to clear).
        $val         = $exportRow[$col] ?? '';
        $cells[$col] = $val;
        if ($val !== '') {
            $backfilled++;
        }
    }
    foreach ($allCols as $col) {
        $line[] = $cells[$col];
    }
    fputcsv($fh, $line, ',', '"', '');

    // A required header still blank means Usurper already has it blank (no data
    // loss), but the row may be rejected on import — surface it.
    foreach (REQUIRED_USURPER_HEADERS as $req) {
        if (($cells[$req] ?? '') === '') {
            $blankRequired[$row['sku']] = true;
            break;
        }
    }
}

fclose($fh);

if ($blankRequired) {
    echo 'Note            : ' . count($blankRequired)
        . ' update row(s) have a blank required header (e.g. a parent SKU with no'
        . ' name) and may be rejected on import: ' . implode(', ', array_keys($blankRequired)) . PHP_EOL;
}

// Held-out SKUs → review-only file. Columns are the union of what these rows
// authored, so an individual row still has blanks for columns other held-out
// rows authored; required headers lead the order but are left blank. This file
// is NOT safe to blind-import — it exists so these SKUs can be handled
// individually (new-product creation, or fixing an unparsable export row first).
$unresolvedFile = null;
if ($unresolved) {
    $uCols = REQUIRED_USURPER_HEADERS;
    foreach ($unresolved as $row) {
        foreach (array_keys($row) as $col) {
            if (!in_array($col, $uCols, true)) {
                $uCols[] = $col;
            }
        }
    }

    $unresolvedFile = $paths['output'] . '/usurper_new_or_unresolved_' . $ts . '.csv';
    $ufh            = fopen($unresolvedFile, 'w');
    fputcsv($ufh, $uCols, ',', '"', '');
    foreach ($unresolved as $row) {
        $line = [];
        foreach ($uCols as $col) {
            $line[] = $row[$col] ?? '';
        }
        fputcsv($ufh, $line, ',', '"', '');
    }
    fclose($ufh);
}

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
echo '  In update file      : ' . count($backfillRows) . PHP_EOL;
echo '  Held out            : ' . count($unresolved) . PHP_EOL;
echo 'Columns written       : ' . $colCount . PHP_EOL;
echo '  Known attr.* cols   : ' . ($colCount - $amazonColCount) . PHP_EOL;
echo '  attr.amazon_* cols  : ' . $amazonColCount . PHP_EOL;
echo 'Cells backfilled      : ' . $backfilled . ' (existing values written back unchanged)' . PHP_EOL;
echo PHP_EOL;
echo 'Update CSV → ' . $outFile . PHP_EOL;
if ($unresolvedFile !== null) {
    echo 'Held-out   → ' . $unresolvedFile . PHP_EOL;
}
echo PHP_EOL;
echo 'Import the update file into Usurper to persist AI-authored values.' . PHP_EOL;
echo 'attr.amazon_* columns will be created as custom attributes on first import.' . PHP_EOL;
if ($unresolvedFile !== null) {
    echo 'The held-out file is for review only (authored columns; required headers' . PHP_EOL;
    echo 'left blank) — do NOT blind-import it; handle those SKUs individually.' . PHP_EOL;
}
