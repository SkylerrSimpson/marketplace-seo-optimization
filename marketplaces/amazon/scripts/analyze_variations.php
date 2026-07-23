<?php

declare(strict_types=1);

/**
 * Phase 11 — Variation reconciliation (READ ONLY, no API calls).
 *
 * Three-way reconciliation of each SKU's variation relationship across the
 * three layers Amazon exposes, which are NOT interchangeable:
 *
 *   Intended  (Usurper)  — parent.sku / sku_type / attr.variation_theme_amazon
 *   Submitted (Listing)  — what we sent: relationships[].parentSkus + theme
 *   Realized  (Catalog)  — what Amazon shows publicly: parentAsins/childAsins
 *
 * Emits one row per SKU tagged with discrepancy categories and which layer(s)
 * disagree, plus the listing's own issues[] (Amazon's compliance verdict).
 * No writes — diagnosis only. Relationship repair is a separate, guarded step.
 *
 * Discrepancy categories:
 *   orphaned_child            Usurper has a parent; listing shows no VARIATION.
 *   wrong_parent              A layer's parent != the intended parent.sku.
 *   unexpected_child          Listing shows VARIATION; Usurper has no parent.
 *   theme_mismatch            Intended theme != realized/submitted theme
 *                             (heuristic — Usurper themes are free-form).
 *   parent_without_theme      Has a parent but no resolvable theme.
 *   dangling_parent           Intended parent SKU has no Amazon listing/ASIN.
 *   listing_catalog_divergence  Listing asserts a parent the catalog doesn't
 *                             reflect (or vice versa / different parent).
 *
 * Usage:
 *   php marketplaces/amazon/scripts/analyze_variations.php [--account=IGE|DOWS] [OPTIONS]
 *
 * Flags:
 *   --account=IGE|DOWS   Seller account to analyze. Default: IGE.
 *   --sku=SKU            Analyze a single SKU only.
 *   --limit=N            Process at most N listings (canary runs).
 *   --all                Emit every SKU row, not just those with discrepancies.
 *   --help               Show this help message.
 *
 * Inputs (all from disk, no API):
 *   marketplaces/amazon/data/{account}/input/listings/{sku}.json
 *   marketplaces/amazon/data/{account}/input/catalog/{asin}.json (+ catalog/errors/{asin}.json)
 *   marketplaces/amazon/data/{account}/input/usurper/InventoryExport_*.csv (latest by mtime)
 *
 * Output:
 *   marketplaces/amazon/data/{account}/output/variation_analysis_{timestamp}.csv
 *     One row per SKU. Key columns: discrepancies (semicolon-joined tags),
 *     disagreeing_layers, catalog_confirms_child (self|parent_record|no —
 *     how the realized catalog binds this child), issues_summary.
 *   marketplaces/amazon/data/{account}/output/variation_analysis_summary_{timestamp}.txt
 *     The bucketed analysis (category counts, actionable-vs-backlog tiers,
 *     divergence/theme/parent breakdowns) — regenerable, no external tooling.
 */

require __DIR__ . '/../../lib/bootstrap.php';
require __DIR__ . '/../../lib/UsurperExport.php';

// ---------------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------------

if (in_array('--help', $argv ?? [], true)) {
    echo <<<'HELP'
Usage: php marketplaces/amazon/scripts/analyze_variations.php [--account=IGE|DOWS] [OPTIONS]

Flags:
  --account=IGE|DOWS   Seller account to analyze. Default: IGE.
  --sku=SKU            Analyze a single SKU only.
  --limit=N            Process at most N listings (canary runs).
  --all                Emit every SKU row, not just those with discrepancies.
  --help               Show this help message.

Read-only: reconciles Usurper (intended) vs Listing (submitted) vs Catalog
(realized) variation relationships. Makes no API calls and writes no changes
to Amazon.
HELP;
    echo PHP_EOL;
    exit(0);
}

$account   = 'IGE';
$singleSku = null;
$limit     = 0;
$emitAll   = in_array('--all', $argv ?? [], true);

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--account=(.+)$/i', $arg, $m)) {
        $account = strtoupper($m[1]);
    } elseif (preg_match('/^--sku=(.+)$/', $arg, $m)) {
        $singleSku = $m[1];
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$paths = amazon_paths($account);

echo 'Account: ' . $account . PHP_EOL;

// ---------------------------------------------------------------------------
// Step 1: Load Usurper catalog CSV (intended layer)
// ---------------------------------------------------------------------------

$usurperDir  = $paths['input'] . '/usurper';
$usurperFile = is_dir($usurperDir) ? usurper_latest_export($usurperDir) : null;

if ($usurperFile === null) {
    fwrite(STDERR, "No CSV found in {$usurperDir}.\n");
    fwrite(STDERR, "Drop the Usurper InventoryExport CSV there and re-run.\n");
    exit(1);
}

echo 'Usurper file: ' . basename($usurperFile) . PHP_EOL;

// Load via the shared reader (strict RFC-4180: escape disabled). The previous
// hand-rolled fgetcsv used the default backslash escape, which desynced quote
// state on rows containing backslashes and silently dropped them on the width
// check. Keep only the columns this script needs (the export is ~1,000 wide).
$wanted    = ['sku', 'parent.sku', 'sku_type', 'attr.variation_theme_amazon'];
$loaded    = usurper_load_export($usurperFile, $wanted);
$malformed = $loaded['malformed'];

$usurper = []; // [sku => [parent_sku, sku_type, theme]]
foreach ($loaded['records'] as $sku => $rec) {
    $usurper[$sku] = [
        'parent_sku' => trim($rec['parent.sku'] ?? ''),
        'sku_type'   => trim($rec['sku_type'] ?? ''),
        'theme'      => trim($rec['attr.variation_theme_amazon'] ?? ''),
    ];
}

echo 'Usurper records loaded: ' . count($usurper) . PHP_EOL;

if ($malformed) {
    fwrite(STDERR, sprintf(
        "WARNING: %d Usurper row(s) could not be parsed (field count != header) and were skipped.\n",
        count($malformed),
    ));
    foreach ($malformed as $badSku => $count) {
        fwrite(STDERR, "  - {$badSku} ({$count} fields)\n");
    }
}

// ---------------------------------------------------------------------------
// Step 2: Index all listing snapshots (SKU <-> ASIN maps + known-SKU set)
// ---------------------------------------------------------------------------

$listingFiles = glob($paths['listings'] . '/*.json') ?: [];
if (!$listingFiles) {
    fwrite(STDERR, "No listing files in {$paths['listings']}.\n");
    fwrite(STDERR, "Run export_listings_items.php --account={$account} first.\n");
    exit(1);
}

$sku2asin  = [];
$asin2sku  = [];
foreach ($listingFiles as $f) {
    $d       = json_decode(file_get_contents($f), true) ?? [];
    $sku     = $d['sku'] ?? basename($f, '.json');
    $summary = reset($d['summaries']) ?: [];
    $asin    = $summary['asin'] ?? '';
    if ($asin !== '') {
        $sku2asin[$sku] = $asin;
        $asin2sku[$asin] ??= $sku;
    }
}
$knownSkus = array_flip(array_keys($sku2asin));
echo 'Listings indexed: ' . count($listingFiles) . PHP_EOL;

// ---------------------------------------------------------------------------
// Step 2b: Index the catalog once — cache each ASIN's realized variation info
// and build a reverse parent index from every parent record's childAsins.
// This lets us read realized parentage from BOTH directions: the child's own
// parentAsins and (authoritatively) the parent's childAsins.
// ---------------------------------------------------------------------------

$catInfo   = []; // [asin => ['parent'=>asin, 'theme'=>str, 'is_parent'=>bool]]
$parentOf  = []; // [childAsin => parentAsin]  (from parent records' childAsins)
$catFiles  = glob($paths['catalog'] . '/*.json') ?: [];
foreach ($catFiles as $cf) {
    $asin = basename($cf, '.json');
    $cat  = json_decode(file_get_contents($cf), true) ?? [];
    $info = ['parent' => '', 'theme' => '', 'is_parent' => false];
    foreach ($cat['relationships'] ?? [] as $mk) {
        foreach ($mk['relationships'] ?? [] as $r) {
            if (($r['type'] ?? '') !== 'VARIATION') {
                continue;
            }
            if (!empty($r['parentAsins'])) {
                $info['parent'] = $r['parentAsins'][0] ?? '';
                $info['theme']  = $r['variationTheme']['theme'] ?? $info['theme'];
            } elseif (!empty($r['childAsins'])) {
                $info['is_parent'] = true;
                $info['theme']     = $info['theme'] ?: ($r['variationTheme']['theme'] ?? '');
                foreach ($r['childAsins'] as $ch) {
                    $parentOf[$ch] = $asin;
                }
            }
        }
    }
    $catInfo[$asin] = $info;
}
echo 'Catalog indexed: ' . count($catFiles) . PHP_EOL;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Reduce a variation-theme label to a canonical token set so free-form
 * Usurper values ('StyleName-Color', 'ColorSize', 'itempackagequantity-size')
 * can be compared with Amazon's ('STYLE_NAME/COLOR_NAME', 'COLOR/SIZE',
 * 'ITEM_PACKAGE_QUANTITY/SIZE'). Heuristic by design.
 *
 * Run-together Usurper tokens with no delimiter ('itempackagequantity') are
 * greedily split against a vocabulary of the atoms Amazon themes are built
 * from, so they line up with Amazon's delimited form. A token that can't be
 * cleanly split against the vocab is left intact (safe fallback).
 */
function themeTokens(string $s): array
{
    if (trim($s) === '') {
        return [];
    }

    // Canonical theme atoms, longest first so greedy matching prefers
    // 'package' over any shorter prefix and 'items' over 'item'.
    static $atoms = null;
    if ($atoms === null) {
        $atoms = [
            'configuration', 'orientation', 'quantity', 'material', 'wattage', 'voltage',
            'package', 'pattern', 'edition', 'flavor', 'number', 'length', 'height',
            'display', 'weight', 'capacity', 'model', 'width', 'color', 'style', 'shape',
            'scent', 'count', 'items', 'size', 'name', 'team', 'lens', 'hand', 'item',
            'ink', 'of',
        ];
        usort($atoms, fn($a, $b) => strlen($b) <=> strlen($a));
    }

    $expand = function (string $tok) use ($atoms): array {
        $res = [];
        $i   = 0;
        $n   = strlen($tok);
        while ($i < $n) {
            $matched = null;
            foreach ($atoms as $a) {
                if (substr($tok, $i, strlen($a)) === $a) {
                    $matched = $a;
                    break;
                }
            }
            if ($matched === null) {
                return [$tok]; // not cleanly splittable — keep intact
            }
            $res[] = $matched;
            $i    += strlen($matched);
        }
        return $res;
    };

    // Split camelCase boundaries, then on any non-alphanumeric.
    $s     = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $s);
    $parts = preg_split('/[^a-zA-Z0-9]+/', strtolower((string) $s), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $stop = ['name' => true, 'the' => true, 'of' => true];
    $syn  = ['colour' => 'color', 'items' => 'item', 'pieces' => 'piece'];
    $out  = [];
    foreach ($parts as $p) {
        foreach ($expand($p) as $t) {
            if (isset($stop[$t])) {
                continue;
            }
            $out[$syn[$t] ?? $t] = true;
        }
    }
    ksort($out);
    return array_keys($out);
}

function themesAgree(string $a, string $b): bool
{
    return themeTokens($a) === themeTokens($b);
}

// ---------------------------------------------------------------------------
// Step 3: Reconcile each SKU across the three layers
// ---------------------------------------------------------------------------

$targets = $singleSku
    ? array_values(array_filter($listingFiles, fn($f) => basename($f, '.json') === $singleSku
        || (json_decode(file_get_contents($f), true)['sku'] ?? '') === $singleSku))
    : $listingFiles;

if ($singleSku && !$targets) {
    fwrite(STDERR, "No listing snapshot for SKU '{$singleSku}'.\n");
    exit(1);
}

$rows      = [];
$processed = 0;
$catStats  = ['ok' => 0, 'missing' => 0, 'error' => 0];

foreach ($targets as $listingFile) {
    if ($limit > 0 && $processed >= $limit) {
        break;
    }
    $processed++;

    $listing     = json_decode(file_get_contents($listingFile), true) ?? [];
    $sku         = $listing['sku'] ?? basename($listingFile, '.json');
    $summary     = reset($listing['summaries']) ?: [];
    $asin        = $summary['asin'] ?? '';
    $productType = $summary['productType'] ?? '';

    // --- Intended (Usurper) ---
    $u             = $usurper[$sku] ?? null;
    $expParentSku  = $u['parent_sku'] ?? '';
    $skuType       = $u['sku_type']   ?? ($u === null ? 'sku_not_in_usurper' : '');
    $expTheme      = $u['theme']      ?? '';

    // --- Submitted (Listing) ---
    $lstParentSku = '';
    $lstTheme     = '';
    $hasLstVar    = false;
    foreach ($listing['relationships'] ?? [] as $mk) {
        foreach ($mk['relationships'] ?? [] as $r) {
            if (($r['type'] ?? '') === 'VARIATION' && !empty($r['parentSkus'])) {
                $hasLstVar    = true;
                $lstParentSku = $r['parentSkus'][0] ?? '';
                $lstTheme     = $r['variationTheme']['theme'] ?? '';
            }
        }
    }

    // --- Realized (Catalog) ---  (from the pre-built index, both directions)
    $catParentAsin = '';
    $catTheme      = '';
    $catIsParent   = false;
    $catStatus     = 'missing';
    $catConfirms   = '';  // self | parent_record | no  — how the catalog binds this child
    if ($asin !== '') {
        if (isset($catInfo[$asin])) {
            $catStatus   = 'ok';
            $info        = $catInfo[$asin];
            $catIsParent = $info['is_parent'];
            $catTheme    = $info['theme'];
            if ($info['parent'] !== '') {
                $catParentAsin = $info['parent'];   // child's own record names the parent
                $catConfirms   = 'self';
            } elseif (isset($parentOf[$asin])) {
                $catParentAsin = $parentOf[$asin];  // a parent record lists this as a child
                $catConfirms   = 'parent_record';
            } else {
                $catConfirms = 'no';
            }
            if ($catTheme === '' && $catParentAsin !== '' && isset($catInfo[$catParentAsin])) {
                $catTheme = $catInfo[$catParentAsin]['theme'];
            }
        } elseif (file_exists($paths['catalog_errors'] . '/' . $asin . '.json')) {
            $catStatus = 'error';
        }
    }
    $catStats[$catStatus]++;
    $catParentSku = $catParentAsin !== '' ? ($asin2sku[$catParentAsin] ?? '') : '';

    // --- Presence flags ---
    $hasExp = $expParentSku !== '';
    $hasCat = $catParentAsin !== '';

    // --- Classify ---
    $cats   = [];
    $layers = [];

    // orphaned_child: intended parent, but no submitted variation.
    if ($hasExp && !$hasLstVar) {
        $cats[]   = 'orphaned_child';
        $layers[] = 'usurper≠listing';
    }

    // unexpected_child: submitted variation, but no intended parent.
    if ($hasLstVar && !$hasExp && $u !== null) {
        $cats[]   = 'unexpected_child';
        $layers[] = 'listing≠usurper';
    }

    // wrong_parent: a layer's parent disagrees with the intended parent.sku
    // (compared in SKU space; catalog parent resolved via asin2sku).
    if ($hasExp && $hasLstVar && $lstParentSku !== '' && $lstParentSku !== $expParentSku) {
        $cats[]   = 'wrong_parent';
        $layers[] = 'usurper≠listing';
    }
    if ($hasExp && $catParentSku !== '' && $catParentSku !== $expParentSku) {
        $cats[]   = 'wrong_parent';
        $layers[] = 'usurper≠catalog';
    }

    // listing_catalog_divergence: submitted vs realized disagree on parent.
    if ($hasLstVar && !$hasCat && $catStatus === 'ok') {
        $cats[]   = 'listing_catalog_divergence';
        $layers[] = 'listing≠catalog';
    } elseif ($hasLstVar && $hasCat && $catParentSku !== '' && $lstParentSku !== ''
              && $catParentSku !== $lstParentSku) {
        $cats[]   = 'listing_catalog_divergence';
        $layers[] = 'listing≠catalog';
    }

    // theme_mismatch (heuristic): intended theme vs a realized/submitted theme.
    $realizedTheme = $catTheme !== '' ? $catTheme : $lstTheme;
    if ($expTheme !== '' && $realizedTheme !== '' && !themesAgree($expTheme, $realizedTheme)) {
        $cats[]   = 'theme_mismatch';
        $layers[] = $catTheme !== '' ? 'usurper≠catalog' : 'usurper≠listing';
    }

    // parent_without_theme: a parent exists somewhere but no theme resolves.
    if (($hasExp || $hasLstVar || $hasCat)
        && $expTheme === '' && $lstTheme === '' && $catTheme === '') {
        $cats[] = 'parent_without_theme';
    }

    // dangling_parent: intended parent SKU has no Amazon listing/ASIN of its own.
    if ($hasExp && !isset($knownSkus[$expParentSku])) {
        $cats[]   = 'dangling_parent';
        $layers[] = 'usurper→(no amazon parent)';
    }

    $cats   = array_values(array_unique($cats));
    $layers = array_values(array_unique($layers));

    if (!$emitAll && !$cats) {
        continue;
    }

    // --- issues[] (Amazon's own verdict) ---
    $issues        = $listing['issues'] ?? [];
    $issueMsgs     = array_map(
        fn($i) => ($i['code'] ?? '') . ':' . ($i['message'] ?? ''),
        $issues,
    );
    $issuesSummary = implode(' | ', array_slice($issueMsgs, 0, 3));
    if (count($issueMsgs) > 3) {
        $issuesSummary .= ' (+' . (count($issueMsgs) - 3) . ' more)';
    }

    $rows[] = [
        'sku'                 => $sku,
        'asin'                => $asin,
        'product_type'        => $productType,
        'usurper_sku_type'    => $skuType,
        'expected_parent_sku' => $expParentSku,
        'listing_parent_sku'  => $lstParentSku,
        'catalog_parent_asin' => $catParentAsin,
        'catalog_parent_sku'  => $catParentSku,
        'is_catalog_parent'   => $catIsParent ? 'yes' : '',
        'catalog_confirms_child' => $catConfirms,
        'expected_theme'      => $expTheme,
        'listing_theme'       => $lstTheme,
        'catalog_theme'       => $catTheme,
        'catalog_status'      => $catStatus,
        'discrepancies'       => implode(';', $cats),
        'disagreeing_layers'  => implode(';', $layers),
        'issues_count'        => count($issues),
        'issues_summary'      => $issuesSummary,
    ];
}

// ---------------------------------------------------------------------------
// Step 4: Write CSV
// ---------------------------------------------------------------------------

echo 'Listings analyzed: ' . $processed . PHP_EOL;
echo 'Catalog data: ok=' . $catStats['ok']
    . ' missing=' . $catStats['missing']
    . ' error=' . $catStats['error'] . PHP_EOL;

if (!$rows) {
    echo PHP_EOL . 'No variation discrepancies found.' . PHP_EOL;
    if (!$emitAll) {
        echo '(Pass --all to emit a row for every SKU regardless.)' . PHP_EOL;
    }
    exit(0);
}

// Sort: most discrepancy categories first, then by SKU.
usort($rows, function ($a, $b) {
    $ca = $a['discrepancies'] === '' ? 0 : substr_count($a['discrepancies'], ';') + 1;
    $cb = $b['discrepancies'] === '' ? 0 : substr_count($b['discrepancies'], ';') + 1;
    if ($ca !== $cb) {
        return $cb <=> $ca;
    }
    return strcmp($a['sku'], $b['sku']);
});

$ts      = date('Y-m-d-H-i-s');
$outFile = $paths['output'] . '/variation_analysis_' . $ts . '.csv';
$fhOut   = fopen($outFile, 'w');
fputcsv($fhOut, array_keys($rows[0]), ',', '"', '');
foreach ($rows as $row) {
    fputcsv($fhOut, $row, ',', '"', '');
}
fclose($fhOut);

// ---------------------------------------------------------------------------
// Summary report — the bucketed analysis, computed by the tool (not by hand)
// and written to disk so it is reproducible without any external scripting.
// ---------------------------------------------------------------------------

$discRows = array_values(array_filter($rows, fn($r) => $r['discrepancies'] !== ''));
$brandOf  = fn(string $sku) => explode('-', $sku)[0];
$hasTag   = fn(array $r, string $t) => in_array($t, explode(';', $r['discrepancies']), true);

/** Count values into an assoc array, sorted desc; returns "  key  n" lines. */
$topLines = function (array $counter, int $topN = 8, string $indent = '  '): array {
    arsort($counter);
    $out = [];
    foreach (array_slice($counter, 0, $topN, true) as $k => $n) {
        $out[] = $indent . str_pad((string) $k, 40) . '  ' . $n;
    }
    return $out;
};

// Per-category SKU counts.
$byCategory = [];
foreach ($discRows as $r) {
    foreach (explode(';', $r['discrepancies']) as $c) {
        if ($c !== '') {
            $byCategory[$c] = ($byCategory[$c] ?? 0) + 1;
        }
    }
}

// listing_catalog_divergence breakdown.
$lcd = array_filter($discRows, fn($r) => $hasTag($r, 'listing_catalog_divergence'));
$lcdDiffParent    = 0;
$lcdParentUnlisted = 0;
$lcdConfirmedReal = 0;
foreach ($lcd as $r) {
    if ($r['catalog_parent_asin'] !== '') {
        $lcdDiffParent++;
    } elseif (!isset($knownSkus[$r['listing_parent_sku']])) {
        $lcdParentUnlisted++;
    } else {
        $lcdConfirmedReal++;
    }
}

// wrong_parent — disagreeing-layer breakdown.
$wpLayers = [];
foreach (array_filter($discRows, fn($r) => $hasTag($r, 'wrong_parent')) as $r) {
    foreach (explode(';', $r['disagreeing_layers']) as $l) {
        if ($l !== '') {
            $wpLayers[$l] = ($wpLayers[$l] ?? 0) + 1;
        }
    }
}

// theme_mismatch — brand + expected→realized pairs.
$tm         = array_filter($discRows, fn($r) => $hasTag($r, 'theme_mismatch'));
$tmBrands   = [];
$tmPairs    = [];
foreach ($tm as $r) {
    $tmBrands[$brandOf($r['sku'])] = ($tmBrands[$brandOf($r['sku'])] ?? 0) + 1;
    $pair = $r['expected_theme'] . '  →  ' . ($r['catalog_theme'] !== '' ? $r['catalog_theme'] : $r['listing_theme']);
    $tmPairs[$pair] = ($tmPairs[$pair] ?? 0) + 1;
}

// unexpected_child — by Usurper sku_type.
$ucTypes = [];
foreach (array_filter($discRows, fn($r) => $hasTag($r, 'unexpected_child')) as $r) {
    $t = $r['usurper_sku_type'] ?: '(blank)';
    $ucTypes[$t] = ($ucTypes[$t] ?? 0) + 1;
}

// "Usurper family never built on Amazon" backlog bucket.
$familyBacklog = count(array_filter(
    $discRows,
    fn($r) => $hasTag($r, 'orphaned_child') && $hasTag($r, 'dangling_parent'),
));

// --- Compose the report ---
$L   = [];
$L[] = 'Variation Reconciliation Summary';
$L[] = 'Account: ' . $account . '   Generated: ' . date('c');
$L[] = str_repeat('=', 60);
$L[] = 'Listings analyzed:       ' . $processed;
$L[] = 'Catalog data:            ok=' . $catStats['ok'] . ' missing=' . $catStats['missing'] . ' error=' . $catStats['error'];
$L[] = 'SKUs with discrepancies: ' . count($discRows);
$L[] = '';
$L[] = 'By category (SKUs carrying each tag):';
$L   = array_merge($L, $topLines($byCategory, 20));
$L[] = '';
$L[] = str_repeat('-', 60);
$L[] = 'TIER 1 — Actionable (likely real Amazon defects)';
$L[] = str_repeat('-', 60);
$L[] = 'listing_catalog_divergence: ' . count($lcd)
     . '  (we submitted a variation Amazon\'s catalog does not reflect)';
$L[] = '  confirmed real (neither child nor parent record binds it): ' . $lcdConfirmedReal;
$L[] = '  parent SKU not listed on Amazon:                           ' . $lcdParentUnlisted;
$L[] = '  different parent (listing vs catalog):                     ' . $lcdDiffParent;
$L[] = '';
$L[] = 'wrong_parent: ' . count(array_filter($discRows, fn($r) => $hasTag($r, 'wrong_parent')))
     . '  (variant sits under a parent other than Usurper\'s parent.sku)';
$L   = array_merge($L, $topLines($wpLayers, 8));
$L[] = '';
$L[] = 'theme_mismatch: ' . count($tm) . '  (intended theme != realized/submitted theme)';
$L[] = '  top brands:';
$L   = array_merge($L, $topLines($tmBrands, 6, '    '));
$L[] = '  top expected → realized:';
$L   = array_merge($L, $topLines($tmPairs, 8, '    '));
$L[] = '';
$L[] = str_repeat('-', 60);
$L[] = 'TIER 2 — Backlog / expected (low or no sales risk)';
$L[] = str_repeat('-', 60);
$L[] = 'Usurper family never built on Amazon (orphaned_child + dangling_parent): ' . $familyBacklog;
$L[] = 'unexpected_child by Usurper sku_type (bundles are usually expected):';
$L   = array_merge($L, $topLines($ucTypes, 6));
$L[] = '';
$L[] = 'Detail per SKU: ' . basename($outFile);
$L[] = 'Note: theme comparison is token-normalized and heuristic;';
$L[] = '      catalog_confirms_child column shows how each child is bound.';

$report = implode(PHP_EOL, $L) . PHP_EOL;

$summaryFile = $paths['output'] . '/variation_analysis_summary_' . $ts . '.txt';
file_put_contents($summaryFile, $report);

echo PHP_EOL . $report;
echo 'CSV     → ' . $outFile . PHP_EOL;
echo 'Summary → ' . $summaryFile . PHP_EOL;
