<?php

declare(strict_types=1);

/**
 * Shared reader for Usurper InventoryExport files.
 *
 * Both draft_listings.php (reads current product data to classify gaps) and
 * project_to_usurper.php (backfills un-authored cells so a blank never clears
 * an existing value on import) need to read the same export the same way:
 * newest CSV/TSV by mtime, delimiter auto-detected, keyed by sku. This keeps
 * that logic in one place so the two scripts can't drift apart.
 */

/**
 * Locate the newest Usurper export in a directory (by mtime).
 *
 * @return string|null Absolute path to the newest *.csv, or null if none.
 */
function usurper_latest_export(string $dir): ?string
{
    $files = glob(rtrim($dir, '/') . '/*.csv') ?: [];
    if (!$files) {
        return null;
    }
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0];
}

/**
 * Load a Usurper export keyed by sku.
 *
 * The export is streamed row-by-row. When $columns is provided the returned
 * records retain only those columns (plus sku), so callers that need just a
 * handful of columns out of the ~700-column export don't hold the whole thing
 * in memory — footprint is roughly (rows kept) x (columns kept).
 *
 * Rows whose field count doesn't match the header can't be trusted (a stray or
 * mis-escaped quote merges fields and shifts every column after it), so they are
 * NOT returned as records. Their SKUs are reported separately in `malformed` so
 * callers can surface the data-quality problem instead of silently dropping it.
 *
 * @param string             $file    Path to the export CSV/TSV.
 * @param list<string>|null   $columns Restrict retained columns to this set
 *                                     (sku is always kept). null keeps all.
 * @return array{
 *     records: array<string, array<string, string>>,
 *     header: list<string>,
 *     malformed: array<string, int>
 * } malformed maps a dropped row's sku => its (wrong) field count.
 */
function usurper_load_export(string $file, ?array $columns = null): array
{
    // Detect delimiter from the header line (Usurper exports are usually TSV).
    $probe     = fopen($file, 'r');
    $firstLine = (string) fgets($probe);
    fclose($probe);
    $delimiter = substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',';

    // Read as strict RFC-4180: enclosure '"', escape DISABLED. PHP's default
    // escape char is backslash, and Usurper values legitimately contain
    // backslashes (e.g. mangled inch marks like `Size: 3\"`); with the default
    // escape those desync the quote state, merge fields, and the row is dropped
    // on the width check below. Disabling the escape makes every row parse.
    $enclosure = '"';
    $escape    = '';

    $fh   = fopen($file, 'r');
    $head = fgetcsv($fh, 0, $delimiter, $enclosure, $escape);
    $head = array_map('trim', $head ?: []);

    // When projecting, precompute which header indexes to keep.
    $keep = null;
    if ($columns !== null) {
        $want        = array_flip($columns);
        $want['sku'] = true; // always retain the key column
        $keep        = [];
        foreach ($head as $i => $col) {
            if (isset($want[$col])) {
                $keep[$i] = $col;
            }
        }
    }

    $records   = [];
    $malformed = [];
    while (($row = fgetcsv($fh, 0, $delimiter, $enclosure, $escape)) !== false) {
        // A row whose width still doesn't match the header can't be aligned to
        // columns; report it (by its first field, usually sku) rather than drop
        // it silently, so the caller can surface the data problem.
        if (count($row) !== count($head)) {
            $malformed[trim($row[0] ?? '')] = count($row);
            continue;
        }

        if ($keep === null) {
            $rec = array_combine($head, $row);
            $sku = trim($rec['sku'] ?? '');
        } else {
            $rec = [];
            $sku = '';
            foreach ($keep as $i => $col) {
                $val       = $row[$i] ?? '';
                $rec[$col] = $val;
                if ($col === 'sku') {
                    $sku = trim($val);
                }
            }
        }

        if ($sku !== '') {
            $records[$sku] = $rec;
        }
    }
    fclose($fh);

    return ['records' => $records, 'header' => $head, 'malformed' => $malformed];
}
