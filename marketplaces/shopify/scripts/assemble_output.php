<?php

declare(strict_types=1);

/**
 * Assemble the reviewable Phase 2 output from the in-session drafts.
 *
 * Merges drafts_manual.json ({numeric_id: meta}) with phase2_input.json, fills
 * product_type for the 23 blanks (4 Fishing IDs, rest Survival/Camping), computes
 * char counts + status, and writes phase2_output.{json,csv}. Resumable: re-run any
 * time as more drafts are added. Reports how many of the 199 are still undrafted.
 *
 * Usage: php assemble_output.php
 */

require __DIR__ . '/../../lib/bootstrap.php';

const FISHING_IDS = ['9512980218156', '9516236144940', '9516244304172', '9516251480364'];
const SEO_MIN = 140, SEO_MAX = 160, SEO_HARD_MIN = 70;

/**
 * Guarantee ASCII-only output. Transliterates common typographic non-ASCII
 * (en/em dash, curly quotes, nbsp, x-sign) and strips anything else remaining.
 * Applied to every output field — titles from Shopify carry en-dashes.
 */
function asciiFold(string $s): string
{
    $map = [
        "\xE2\x80\x93" => '-',  "\xE2\x80\x94" => '-',   // – —
        "\xE2\x80\x99" => "'",  "\xE2\x80\x98" => "'",   // ’ ‘
        "\xE2\x80\x9C" => '"',  "\xE2\x80\x9D" => '"',   // “ ”
        "\xC2\xA0"     => ' ',                            // nbsp
        "\xE2\x80\xA6" => '...',                          // …
        "\xC3\x97"     => 'x',                            // ×
    ];
    $s = strtr($s, $map);
    return preg_replace('/[^\x00-\x7F]/', '', $s); // hard guarantee
}

$input    = json_decode((string) file_get_contents(SHOPIFY_INPUT . '/phase2_input.json'), true);
$drafts   = json_decode((string) file_get_contents(SHOPIFY_DRAFTS . '/drafts_manual.json'), true) ?: [];
$imgAlts  = is_file(SHOPIFY_INPUT . '/image_alts.json')
    ? json_decode((string) file_get_contents(SHOPIFY_INPUT . '/image_alts.json'), true) : [];
$altDraft = is_file(SHOPIFY_DRAFTS . '/drafts_alt.json')
    ? json_decode((string) file_get_contents(SHOPIFY_DRAFTS . '/drafts_alt.json'), true) : [];

$out = [];
$undrafted = [];
foreach ($input as $r) {
    $id = (string) $r['numeric_id'];

    $current = trim((string) ($r['product_type'] ?? ''));
    if ($current !== '') {
        $ptype = $current; $changed = 0;
    } else {
        $ptype = in_array($id, FISHING_IDS, true) ? 'Fishing' : 'Survival/Camping';
        $changed = 1;
    }

    $meta = $drafts[$id] ?? '';
    $len  = $meta === '' ? 0 : mb_strlen($meta);
    if ($meta === '') {
        $status = 'TODO';
        $undrafted[] = $id;
    } elseif ($len > SEO_MAX || $len < SEO_HARD_MIN) {
        $status = 'out_of_band';
    } elseif ($len < SEO_MIN) {
        $status = 'short_but_acceptable';
    } else {
        $status = 'ok';
    }

    $oldAlt   = trim((string) ($imgAlts[$id]['old_alt'] ?? ''));
    $newAlt   = (string) ($altDraft[$id] ?? '');
    $altMedia = (string) ($imgAlts[$id]['media_id'] ?? '');

    $out[] = [
        'numeric_id'           => $id,
        'gid'                  => $r['gid'],
        'title'                => $r['title'],
        'old_product_type'     => $current !== '' ? $current : '(blank)',
        'product_type_final'   => $ptype,
        'product_type_changed' => $changed,
        'old_seo_desc'         => $r['current_seo_desc'] ?? '',
        'new_meta_description' => $meta,
        'char_count'           => $len,
        'old_image_alt'        => $oldAlt,
        'new_image_alt'        => $newAlt,
        'image_alt_changed'    => ($newAlt !== '' && $newAlt !== $oldAlt) ? 1 : 0,
        'featured_media_id'    => $altMedia,
        'weak_source'          => !empty($r['weak_source']) ? 1 : 0,
        'status'               => $status,
    ];
}

// ASCII-fold every string field in every row (hard ASCII guarantee for the output).
foreach ($out as &$row) {
    foreach ($row as $k => $val) {
        if (is_string($val)) { $row[$k] = asciiFold($val); }
    }
}
unset($row);

file_put_contents(SHOPIFY_OUTPUT . '/phase2_output.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// Boss-facing CSV: friendly header label => internal key. before/after adjacent.
// (gid stays in the JSON, not the review CSV.)
$csvMap = [
    'numeric_id'         => 'numeric_id',
    'title'              => 'title',
    'old_product_type'   => 'old_product_type',
    'new_product_type'   => 'product_type_final',
    'old_seo_description'=> 'old_seo_desc',
    'new_seo_description'=> 'new_meta_description',
    'new_desc_chars'     => 'char_count',
    'old_image_alt'      => 'old_image_alt',
    'new_image_alt'      => 'new_image_alt',
    'image_alt_changed'  => 'image_alt_changed',
    'weak_source'        => 'weak_source',
    'status'             => 'status',
];
$fh = fopen(SHOPIFY_OUTPUT . '/phase2_output.csv', 'w');
fputcsv($fh, array_keys($csvMap));
foreach ($out as $row) {
    fputcsv($fh, array_map(static fn($k) => $row[$k], array_values($csvMap)));
}
fclose($fh);

$done = count($out) - count($undrafted);
echo "Assembled phase2_output.{json,csv}\n";
echo "Drafted: {$done}/" . count($out) . "   |   Remaining (TODO): " . count($undrafted) . "\n";
$oob = count(array_filter($out, fn($r) => in_array($r['status'], ['out_of_band'], true)));
if ($oob) { echo "⚠ {$oob} drafted rows out of length band — fix before approval.\n"; }
