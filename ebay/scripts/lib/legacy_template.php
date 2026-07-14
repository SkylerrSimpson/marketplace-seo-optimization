<?php

declare(strict_types=1);

/**
 * legacy_template.php — non-lossy decomposition of the old DOWS/IGE Bootstrap-
 * template description HTML into the fields the new template (build_description_
 * review.php's renderFull()) expects: factual intro, sales paragraph, Key Features
 * bullets, main image, MPN/UPC.
 *
 * Built + proven 2026-07-13 against real live DOWS listings after three separate
 * content-loss incidents from cruder approaches in the same session:
 *   1. Using desc_source_pack.jsonl's old_first_description/old_sales_description/
 *      old_key_features columns — those are a LOSSY extraction (boilerplate-
 *      stripped, word-count-filtered, deduped) built for LLM-authoring grounding,
 *      never meant to be a faithful copy. Dropped feature bullets and whole sales
 *      paragraphs silently.
 *   2. Verbatim-DOM-preservation (strip only known chrome, keep the rest as one
 *      blob) — non-lossy on content, but kept the OLD two-column DOM order (image+
 *      specs table, THEN title+narrative), so on eBay's page (no Bootstrap CSS to
 *      make it two columns) it rendered stacked in the wrong order: product details
 *      above the title/description instead of the new template's title-first order.
 *   3. First cut of THIS decompose approach: collapsed whitespace before trying to
 *      split paragraphs (so the split never fired), and queried ALL descendant <p>
 *      tags instead of direct children (which also pulled in the Newsletter box's
 *      <p> tags, since that box is nested inside the same column as the narrative).
 *   4. Second cut: unconditionally dropped the original page's own MPN/UPC into the
 *      new template's Product Specifications-from-aspects section — but a listing's
 *      eBay item specifics don't always actually include mpn/upc, so those listings
 *      lost their MPN/UPC entirely. Fixed by extracting mpn/upc here and merging
 *      them into $aspects as a fallback (only if not already present).
 *
 * Every one of those was caught by the SAME check: a word-level diff between the
 * original page's visible text and the final rendered output, verifying every
 * unaccounted-for word is either known intentionally-dropped chrome or an
 * intentional design choice (MSRP not shown, internal <h1> replaced by the real
 * eBay title, copyright year regenerated) -- see nonLossyCheck() below. ALWAYS run
 * that check before trusting this on a new listing/shape.
 *
 * Known template structure this decomposer targets (confirmed across every DOWS
 * legacy listing checked so far):
 *   <!-- MOBILE DESCRIPTION --> hidden div > span[property=description]
 *   <!-- MAIN DESCRIPTION -->
 *   <style>...</style>  (Bootstrap-ish grid/nav/newsletter CSS -- discarded)
 *   <div class="navbar navbar-default">...Deals Only / Our Store / About Us /
 *     Contact Us...</div>                                    -- discarded (chrome)
 *   <div class="container">
 *     <div class="col-md-6">                                 -- LEFT column
 *       <img class="img-responsive" src="...">                 -> image
 *       <h3>Product Details</h3>
 *       <div class="product-specs"><table>...MPN/UPC/Features rows...</table></div>
 *                                                                -> mpn, upc, bullets
 *     </div>
 *     <div class="col-md-6">                                 -- RIGHT column
 *       <h1>...</h1>                                            -- discarded (the
 *                                                                   real eBay title
 *                                                                   is used instead)
 *       <h2>MSRP ...</h2>                                       -- discarded (price
 *                                                                   already shown
 *                                                                   natively on the
 *                                                                   eBay page)
 *       <p>paragraph 1\n\nparagraph 2\n\n...</p>                -> factual (para 1),
 *                                                                   sales (rest)
 *       <p><strong>optional 2nd paragraph e.g. battery warning</strong></p>
 *                                                                -> appended to sales
 *       <div class="d-flex d-flex-col ...">Newsletter box</div> -- discarded (chrome)
 *     </div>
 *     <div class="col-md-12 footer"><p>&copy; YYYY</p></div>  -- discarded (chrome;
 *                                                                 new footer
 *                                                                 regenerates the
 *                                                                 year itself)
 */

/** Shape a listing's raw description HTML falls into, so the caller never guesses. */
const SHAPE_LEGACY       = 'legacy';       // the Bootstrap template above -- decompose it
const SHAPE_ALREADY_NEW  = 'already_new';  // already has the new template's own header -- leave alone
const SHAPE_UNRECOGNIZED = 'unrecognized'; // matches neither -- leave alone, flag for manual review

function classifyDescriptionShape(string $raw): string
{
    if (strpos($raw, 'background:#f5f5f5;padding:12px 15px;border:1px solid #e5e5e5') !== false) {
        return SHAPE_ALREADY_NEW;
    }
    $hasNavbar = strpos($raw, 'navbar navbar-default') !== false;
    $hasCols   = strpos($raw, 'col-md-6') !== false;
    $hasMobile = preg_match('/property="description"[^>]*>(.*?)<\/span>/si', $raw) === 1;
    $hasSpecsTable = strpos($raw, 'product-specs') !== false;
    if ($hasNavbar && $hasCols && $hasMobile && $hasSpecsTable) {
        return SHAPE_LEGACY;
    }
    return SHAPE_UNRECOGNIZED;
}

function decomposeLegacy(string $raw): array
{
    if (!preg_match('/property="description"[^>]*>(.*?)<\/span>/si', $raw, $m)) {
        throw new RuntimeException('no mobile span found');
    }
    $mobile = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="__root__">' . $raw . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // collapse-internal-whitespace-but-preserve-blank-line-paragraph-breaks helper
    $oneLine = function (string $s): string {
        return trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/[ \t]*\n[ \t]*/u', "\n", $s)));
    };
    $cellText = function (DOMNode $n) use ($oneLine): string {
        return $oneLine(html_entity_decode($n->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    };
    // fully single-line (table cells / bullets never have meaningful blank-line breaks)
    $flatten = function (string $s): string { return trim(preg_replace('/\s+/u', ' ', $s)); };

    // image: the product photo (class="img-responsive"), not the Prop65/logo/etc.
    $image = '';
    foreach ($xpath->query("//img[contains(concat(' ', normalize-space(@class), ' '), ' img-responsive ')]") as $img) {
        $image = $img->getAttribute('src');
        break;
    }
    if ($image === '') {
        foreach ($dom->getElementsByTagName('img') as $img) { $image = $img->getAttribute('src'); break; }
    }

    // bullets + mpn/upc: every row in .product-specs -- MPN/UPC pulled out separately
    // (merged into $aspects as a fallback by the caller, NOT dropped -- a listing's
    // eBay item specifics don't always actually include mpn/upc even though the old
    // description body showed them).
    $bullets = [];
    $mpn = ''; $upc = '';
    foreach ($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' product-specs ')]//tr") as $tr) {
        $tds = $xpath->query('.//td', $tr);
        if ($tds->length < 2) { continue; }
        $label = $flatten($cellText($tds->item(0)));
        $value = $flatten($cellText($tds->item(1)));
        if ($value === '') { continue; }
        $labelLower = mb_strtolower(rtrim($label, ':'));
        if ($labelLower === 'mpn') { $mpn = $value; continue; }
        if ($labelLower === 'upc') { $upc = $value; continue; }
        $bullets[] = $value; // "LABEL: detail" text, already colon-formatted for renderFull()'s bolding
    }

    // narrative: DIRECT-CHILD <p> elements of the col-md-6 that has the h1 -- NOT
    // ./descendant <p>, which would also match <p> tags nested inside the Newsletter
    // box (.d-flex.d-flex-col is itself a child of this same column). Raw text kept
    // (not whitespace-collapsed yet) so blank-line paragraph breaks survive to split.
    $paras = [];
    $h1Text = '';
    foreach ($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' col-md-6 ')]") as $col) {
        $h1s = $xpath->query('./h1', $col);
        if ($h1s->length === 0) { continue; }
        $h1Text = $flatten($cellText($h1s->item(0)));
        foreach ($xpath->query('./p', $col) as $p) {
            $text = $cellText($p);
            if ($text !== '') { $paras[] = $text; }
        }
        break;
    }
    $flat = [];
    foreach ($paras as $p) {
        foreach (preg_split('/\n\s*\n/u', $p) as $seg) {
            $seg = $flatten($seg);
            if ($seg !== '') { $flat[] = $seg; }
        }
    }
    $factual = $flat[0] ?? '';
    $sales = trim(implode("\n\n", array_slice($flat, 1)));

    // chrome text -- everything we deliberately drop, tracked explicitly (not
    // inferred) so nonLossyCheck() can verify "kept + dropped-chrome" accounts for
    // ~100% of the original page's visible text without guessing.
    $chrome = [];
    foreach ($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' navbar ')]") as $n) { $chrome[] = $flatten($cellText($n)); }
    foreach ($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' d-flex ')]") as $n) { $chrome[] = $flatten($cellText($n)); }
    foreach ($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' footer ')]") as $n) { $chrome[] = $flatten($cellText($n)); }
    foreach ($xpath->query('//h2[contains(., "MSRP")]') as $n) { $chrome[] = $flatten($cellText($n)); }
    foreach ($xpath->query('//h3[contains(., "Product Details")]') as $n) { $chrome[] = $flatten($cellText($n)); }
    if ($h1Text !== '') { $chrome[] = $h1Text; }
    // the "MPN:" / "UPC:" / "Features:" row labels themselves (not their values)
    foreach ($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' product-specs ')]//td[contains(concat(' ', normalize-space(@class), ' '), ' attr-left ')]") as $n) {
        $t = $flatten($cellText($n));
        if ($t !== '') { $chrome[] = $t; }
    }

    return [
        'mobile' => $mobile, 'image' => $image, 'bullets' => $bullets,
        'factual' => $factual, 'sales' => $sales, 'mpn' => $mpn, 'upc' => $upc,
        'chrome' => $chrome,
    ];
}

/**
 * Word-level non-lossy check: every word in the original page's visible text
 * (style/script/comment stripped) must appear either in the final rendered HTML's
 * visible text, or in the explicitly-tracked $chrome text (decomposeLegacy()'s
 * 'chrome' key), or a small fixed allowlist for known-and-approved design choices
 * (MSRP price number is dropped, copyright year is regenerated to current year).
 * Returns the list of truly-unaccounted words -- empty means clean.
 */
function nonLossyCheck(string $rawOriginal, string $finalHtml, array $chromeTexts): array
{
    // insert a space at every tag boundary before strip_tags() -- otherwise two
    // adjacent tags with no whitespace between them (e.g. "...fanatics!</p><p><strong>
    // WARNING:...") get fused into one unmatchable token ("fanatics!WARNING:"),
    // producing a false-positive "missing content" report.
    $degap = fn(string $h): string => preg_replace('/>(?=<)/', '> ', $h);

    $rawNoStyle = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $rawOriginal);
    $origText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($degap($rawNoStyle)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $keptText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($degap($finalHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $chromeText = trim(preg_replace('/\s+/u', ' ', implode(' ', $chromeTexts)));

    $counts = array_count_values(preg_split('/\s+/', $keptText . ' ' . $chromeText));
    // small fixed allowlist: MSRP price number (any digits.digits after "MSRP"),
    // "MSRP" itself, copyright year digits -- these are intentional, catalog-wide
    // design choices, not per-listing content.
    $allowlist = ['MSRP'];

    $missing = [];
    foreach (preg_split('/\s+/', $origText) as $w) {
        if (($counts[$w] ?? 0) > 0) { $counts[$w]--; continue; }
        if (in_array($w, $allowlist, true)) { continue; }
        if (preg_match('/^\d{1,3}(\.\d+)?$/', $w)) { continue; } // MSRP price / stray year digits
        if (preg_match('/^(19|20)\d{2}$/', $w)) { continue; } // copyright year
        $missing[] = $w;
    }
    return $missing;
}
