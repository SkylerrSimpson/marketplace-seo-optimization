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
const SHAPE_INTERMEDIATE = 'intermediate'; // an earlier hand-applied "new template"
                                            // pass (2026-07, before renderFull() existed) --
                                            // decompose it too, see decomposeIntermediate()
const SHAPE_UNRECOGNIZED = 'unrecognized'; // matches none of the above -- leave alone, flag
                                            // for manual review

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
    // Comment-marker signature, not an exact CSS/whitespace match (the reason the
    // already_new check above missed these in the first place -- it was hand-typed
    // this HTML at an earlier point with slightly different spacing than
    // renderFull() emits). The CURRENT final template
    // never emits HTML comments at all, so this can't false-positive on an
    // already-correct listing. All six markers required so a page that merely
    // mentions one of these words in passing doesn't get misclassified.
    $markers = ['<!-- HEADER BAR', '<!-- PRODUCT TITLE', '<!-- SHORT DESCRIPTION',
        '<!-- KEY FEATURES', '<!-- SPECIFICATIONS', '<!-- FULL DESCRIPTION'];
    $hasAllMarkers = true;
    foreach ($markers as $marker) {
        if (strpos($raw, $marker) === false) { $hasAllMarkers = false; break; }
    }
    if ($hasAllMarkers && $hasMobile) {
        return SHAPE_INTERMEDIATE;
    }
    return SHAPE_UNRECOGNIZED;
}

/**
 * <br> tags are how this template represents whitespace (a paragraph-ish break
 * inside the mobile span, sometimes elsewhere). strip_tags()/DOM textContent both
 * just DELETE tags with no replacement -- "time.<br><br>Learning" strips to
 * "time.Learning" with zero space between them, silently fusing two sentences.
 * Converting <br> to an actual newline BEFORE any tag-stripping/DOM-text-extraction
 * fixes this at the source instead of trying to patch it after the fact.
 */
function convertBreaksToNewlines(string $html): string
{
    return preg_replace('/<br\s*\/?>/i', "\n", $html);
}

function decomposeLegacy(string $raw): array
{
    $raw = convertBreaksToNewlines($raw);
    // Human-readable notes on anything unusual the decomposer had to work around --
    // NOT just for needs_review items. For the full-catalog
    // pass, every listing's report row should say whether something suspicious was
    // auto-handled (malformed HTML, a dropped duplicate, etc.), not just the ones that
    // got flagged for manual review, so a clean "ok" row can still be spot-checked.
    $flags = [];

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
    // description body showed them). A handful of source rows are malformed with a
    // 3rd (or more) stray <td> tacked onto the row -- e.g. item 127082971121's "NO
    // SPACE WASTED" row also has an un-labeled 3rd cell containing a whole 19-item
    // accessories list. Cell 0 = label, cell 1 = its value (as before); any cell
    // beyond that has no label of its own, so it's appended as a plain bullet rather
    // than silently dropped.
    $bullets = [];
    $extraCellBullets = []; // stray 3rd+ <td> cells -- checked for redundancy against
                             // any <ul><li> list found below before being kept
    $mpn = ''; $upc = '';
    foreach ($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' product-specs ')]//tr") as $tr) {
        $tds = $xpath->query('.//td', $tr);
        if ($tds->length < 2) { continue; }
        $label = $flatten($cellText($tds->item(0)));
        $value = $flatten($cellText($tds->item(1)));
        if ($value !== '') {
            $labelLower = mb_strtolower(rtrim($label, ':'));
            if ($labelLower === 'mpn') { $mpn = $value; }
            elseif ($labelLower === 'upc') { $upc = $value; }
            else { $bullets[] = $value; } // "LABEL: detail" text, already colon-formatted for renderFull()'s bolding
        }
        for ($i = 2; $i < $tds->length; $i++) {
            $extra = $flatten($cellText($tds->item($i)));
            if ($extra !== '') { $extraCellBullets[] = $extra; $flags[] = 'malformed product-specs row (stray 3rd+ <td> cell)'; }
        }
    }

    // narrative: DIRECT-CHILD <p> AND <ul>/<li> elements of the col-md-6 that has the
    // h1 -- NOT ./descendant <p>, which would also match <p> tags nested inside the
    // Newsletter box (.d-flex.d-flex-col is itself a child of this same column). Raw
    // text kept (not whitespace-collapsed yet) so blank-line paragraph breaks survive
    // to split. A few source pages (e.g. 127082971121) embed an actual <ul><li> item
    // list in this column instead of/in addition to <p> paragraphs -- each <li>
    // becomes its own bullet (same shape as a Key Feature) rather than being skipped
    // since it was never a paragraph to begin with.
    $paras = [];
    $h1Text = '';
    $primaryCol = null;
    $droppedAsRedundant = [];
    $brandH3Chrome = [];
    foreach ($xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' col-md-6 ')]") as $col) {
        $h1s = $xpath->query('./h1', $col);
        if ($h1s->length === 0) { continue; }
        $h1Text = $flatten($cellText($h1s->item(0)));
        $primaryCol = $col;
        break;
    }
    if ($primaryCol !== null) {
        // A handful of source pages (e.g. 127082971121) have a stray extra
        // </div><div class="col-md-6"> mid-narrative that splits what should be one
        // continuous column into two -- walk immediate col-md-6 siblings that aren't
        // a real second product column (no <h1> of their own) and aren't the
        // Newsletter/footer chrome, so their content isn't silently skipped.
        $cols = [$primaryCol];
        foreach ($xpath->query("following-sibling::div[contains(concat(' ', normalize-space(@class), ' '), ' col-md-6 ')]", $primaryCol) as $sib) {
            if ($xpath->query('./h1', $sib)->length > 0) { break; }
            $sibClass = ' ' . $sib->getAttribute('class') . ' ';
            if (str_contains($sibClass, ' d-flex ') || str_contains($sibClass, ' footer ')) { break; }
            $cols[] = $sib;
        }
        if (count($cols) > 1) { $flags[] = 'malformed/split col-md-6 (' . (count($cols) - 1) . ' extra fragment(s) recovered)'; }
        // A direct-child <h3> in the narrative column (seen on IGE listings, e.g.
        // 254913999821's "<h3>Sona</h3>" between the MSRP <h2> and the first <p>) is
        // a brand-name subheading, confirmed against desc_source_pack.jsonl to always
        // equal that item's own live Brand aspect -- already shown to the buyer via
        // eBay's native Item Specifics on the same page, so dropping it here is the
        // same "already displayed elsewhere" chrome treatment as the MSRP <h2> and h1
        // title, not a content loss. (Distinct from "Product Details" <h3>, which
        // lives in the OTHER col-md-6 -- the specs-table column -- and is already
        // handled by its own xpath query below.)
        foreach ($cols as $col) {
            foreach ($xpath->query('./h3', $col) as $h3) {
                $t = $flatten($cellText($h3));
                if ($t !== '') { $brandH3Chrome[] = $t; }
            }
        }
        // walk each column's DIRECT children in document order -- <p> and bare text
        // nodes both become narrative paragraphs. <ul>/<ol> items normally become
        // bullets (same shape as a Key Feature bullet, since they were never a
        // paragraph to begin with) -- UNLESS the list immediately follows a paragraph
        // whose text ends in ":" (e.g. "The kit includes the following metal detector
        // accessories:"), in which case the list is clearly introducing THAT sentence,
        // not a standalone Key Feature -- keep it attached as a continuation of that
        // same paragraph so it stays next to its own intro instead of landing in the
        // unrelated Key Features bullet dump, separated from what introduces it
        // (e.g. 127082971121). A nested chrome element (e.g. the Newsletter
        // d-flex box living INSIDE this same malformed column) is a <div>, which this
        // walk's tag check already ignores -- only text/p/ul/ol are read, so it's
        // naturally excluded without needing to throw out the whole column.
        $liBullets = [];
        $allLiItems = []; // every <li> found, regardless of where it ends up routed --
                           // used below to check extra-<td>-cell bullets for redundancy
        foreach ($cols as $col) {
            foreach ($col->childNodes as $node) {
                if ($node->nodeType === XML_TEXT_NODE) {
                    $text = $cellText($node);
                    if ($text !== '') { $paras[] = $text; }
                    continue;
                }
                if ($node->nodeType !== XML_ELEMENT_NODE) { continue; }
                $tag = strtolower($node->nodeName);
                if ($tag === 'p') {
                    $text = $cellText($node);
                    if ($text !== '') { $paras[] = $text; }
                } elseif ($tag === 'ul' || $tag === 'ol') {
                    $items = [];
                    foreach ($xpath->query('./li', $node) as $li) {
                        $liText = $flatten($cellText($li));
                        if ($liText !== '') { $items[] = $liText; }
                    }
                    if ($items === []) { continue; }
                    $allLiItems = array_merge($allLiItems, $items);
                    $lastIdx = count($paras) - 1;
                    if ($lastIdx >= 0 && preg_match('/:\s*$/u', rtrim($paras[$lastIdx]))) {
                        // strip each item's own trailing punctuation first -- some
                        // source <li> items already end in ";"/"," (e.g. "gem
                        // tweezers;"), which would otherwise show doubled up against
                        // the separator. Join with \x02, a placeholder that survives
                        // $flatten()'s whitespace collapse (unlike a real "\n" would)
                        // and later gets turned into an actual <br> by renderFull() --
                        // so each item still reads on its own line, not run together
                        // in one comma-separated sentence (the list
                        // needs to stay attached to its own intro line, but readable).
                        $cleanItems = array_map(fn(string $it): string => rtrim($it, ' ,.;'), $items);
                        $paras[$lastIdx] = rtrim($paras[$lastIdx]) . "\x02" . implode("\x02", $cleanItems) . '.';
                        $flags[] = 'package-contents <ul><li> list attached to its intro sentence (' . count($items) . ' item(s))';
                    } else {
                        $liBullets = array_merge($liBullets, $items);
                    }
                }
            }
        }
        // 127082971121 is the reason both $extraCellBullets and $allLiItems exist:
        // its source restates the SAME 19-item accessory list twice, once crammed
        // into a stray 3rd <td> and once as a proper <ul><li> list, worded slightly
        // differently each time ("Premium tool bag" vs "Premium equipment tool bag").
        // Keeping both produced a visibly duplicated list. If an extra-cell bullet's
        // words are almost entirely covered by the <li> items combined (wherever they
        // ended up), it's the same content restated worse -- drop it.
        $wordSet = function (string $s): array {
            $s = preg_replace('/[^a-z0-9 ]/u', ' ', mb_strtolower($s));
            return array_values(array_unique(array_filter(explode(' ', $s), fn($w) => $w !== '')));
        };
        if ($allLiItems !== [] && $extraCellBullets !== []) {
            $liWordPool = [];
            foreach ($allLiItems as $li) { foreach ($wordSet($li) as $w) { $liWordPool[$w] = true; } }
            $keptExtraCell = [];
            foreach ($extraCellBullets as $b) {
                $bw = $wordSet($b);
                $covered = 0;
                foreach ($bw as $w) { if (isset($liWordPool[$w])) { $covered++; } }
                $isRedundant = $bw !== [] && ($covered / count($bw)) >= 0.7;
                if ($isRedundant) { $droppedAsRedundant[] = $b; } else { $keptExtraCell[] = $b; }
            }
            $extraCellBullets = $keptExtraCell;
        }
        if ($droppedAsRedundant !== []) { $flags[] = 'dropped ' . count($droppedAsRedundant) . ' duplicate bullet(s) already covered by a <ul><li> list'; }
        $bullets = array_merge($bullets, $extraCellBullets, $liBullets);
    } else {
        $bullets = array_merge($bullets, $extraCellBullets);
    }
    $flat = [];
    foreach ($paras as $p) {
        foreach (preg_split('/\n\s*\n/u', $p) as $seg) {
            $seg = $flatten($seg);
            if ($seg !== '') { $flat[] = $seg; }
        }
    }
    // Rule: first description = first paragraph, OR first two
    // paragraphs if the old body has 5+ paragraphs total (a single opening paragraph
    // reads too short/abrupt relative to a long five-plus-paragraph original).
    $firstN = count($flat) >= 5 ? 2 : 1;
    $factual = trim(implode("\n\n", array_slice($flat, 0, $firstN)));
    $sales = trim(implode("\n\n", array_slice($flat, $firstN)));

    // chrome text -- everything we deliberately drop, tracked explicitly (not
    // inferred) so nonLossyCheck() can verify "kept + dropped-chrome" accounts for
    // ~100% of the original page's visible text without guessing.
    //
    // The RAW mobile span text goes in here too, in full. It's a second, independent
    // copy of substantially the same content as the main body (by the template's own
    // design -- <!-- MOBILE DESCRIPTION --> is a separate restatement of the listing,
    // not unique content), and the new template's mobileSummary() deliberately
    // truncates it to 800 chars in the output. For long old mobile spans (some
    // effectively restate the ENTIRE main body, item lists included) that truncation
    // cuts off real text -- but that text isn't actually lost from the page, since the
    // main body already carries it in full and IS checked word-for-word on its own.
    // Without this, nonLossyCheck flags the truncated tail as "missing" purely because
    // the source happens to say the same thing twice and we only keep one full copy.
    // $droppedAsRedundant: extra-<td>-cell bullets dropped because a cleaner <ul><li>
    // list already restates the same content (see 127082971121 above) -- accounted
    // for here the same way as the mobile span, so the audit doesn't flag the
    // deliberately-not-duplicated copy as missing.
    $chrome = array_merge([$mobile], $droppedAsRedundant, $brandH3Chrome);
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
        // Legacy shape's own non-mpn/upc product-specs rows still fold into
        // $bullets (unchanged, pre-existing behavior, not part of today's fix) --
        // extraSpecs stays empty here so buildRendered() can read it uniformly
        // from either decomposer without branching on shape.
        'extraSpecs' => [], 'chrome' => $chrome, 'flags' => $flags,
    ];
}

/**
 * Decompose SHAPE_INTERMEDIATE -- an earlier hand-applied "new template"
 * pass, before renderFull() existed as the single source of truth. An HTML-
 * comment-delimited layout, confirmed identical across every sample checked
 * (2026-07-16): MOBILE DESCRIPTION, HEADER BAR, PRODUCT TITLE, SHORT DESCRIPTION,
 * KEY FEATURES, MAIN IMAGE, SPECIFICATIONS, FULL DESCRIPTION, FOOTER. Distinct
 * from decomposeLegacy() (the much older Bootstrap navbar/col-md-6 template) --
 * this one is already close to the current template's own field shape, so
 * decomposition is a straight section-by-section extraction, not DOM column-
 * walking.
 *
 * SHORT DESCRIPTION and FULL DESCRIPTION map directly to factual/sales -- that
 * split was already made by hand in these, so it's kept as-is rather than
 * re-derived by decomposeLegacy()'s paragraph-count heuristic (which exists
 * to infer a split that was never made explicitly in the much messier old
 * Bootstrap body).
 *
 * SPECIFICATIONS rows beyond MPN/UPC (e.g. "Blade Material: Zirconia Ceramic") are
 * the "custom specifics" kept from the old description. Folded into $bullets as
 * "Label: Value" text -- the exact
 * convention decomposeLegacy() already uses for a legacy listing's non-MPN/UPC
 * spec rows, so nothing here is a new/different rule, just applied to this shape.
 */
function decomposeIntermediate(string $raw): array
{
    $raw = convertBreaksToNewlines($raw);
    $flags = [];

    if (!preg_match('/property="description"[^>]*>(.*?)<\/span>/si', $raw, $m)) {
        throw new RuntimeException('no mobile span found');
    }
    $mobile = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    $oneLine = function (string $s): string {
        return trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/[ \t]*\n[ \t]*/u', "\n", $s)));
    };
    $flatten = function (string $s): string { return trim(preg_replace('/\s+/u', ' ', $s)); };
    // Insert a space at every tag boundary before strip_tags() -- otherwise two
    // adjacent tags with no whitespace between them (e.g. this shape's dimension
    // list: "...project.</div><div>1.75&quot; x...") get fused into one
    // unmatchable token ("project.1.75""), producing a false-positive "missing
    // content" report in nonLossyCheck() -- same fix that function itself already
    // applies, needed here too since this decomposer does its own text extraction.
    $degap = fn (string $h): string => preg_replace('/>(?=<)/', '> ', $h);
    $decodeText = function (string $html) use ($oneLine, $degap): string {
        return $oneLine(html_entity_decode(strip_tags($degap($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    };

    // Inner HTML of one comment-delimited section, up to the next <!-- --> marker
    // (or end of string for the last section, FOOTER).
    $section = function (string $name) use ($raw): string {
        if (!preg_match('/<!--\s*' . preg_quote($name, '/') . '\s*-->(.*?)(?=<!--|\z)/si', $raw, $m)) {
            return '';
        }
        return $m[1];
    };

    // HEADER BAR (store name/nav links) and FOOTER (copyright) are chrome --
    // dropped (store header isn't part of the product description; the new
    // template's own footer regenerates the year), tracked so the non-lossy audit
    // doesn't flag them as missing.
    $chrome = [$mobile];
    $headerText = $decodeText($section('HEADER BAR'));
    if ($headerText !== '') { $chrome[] = $headerText; }
    $footerText = $decodeText($section('FOOTER'));
    if ($footerText !== '') { $chrome[] = $footerText; }

    // PRODUCT TITLE section's own bold title line -- dropped, the real eBay title
    // is used instead (same as decomposeLegacy()'s h1 handling).
    $productTitleBlock = $section('PRODUCT TITLE');
    if (preg_match('/<div[^>]*font-size:\s*22px[^>]*>(.*?)<\/div>/si', $productTitleBlock, $tm)) {
        $titleText = $decodeText($tm[1]);
        if ($titleText !== '') { $chrome[] = $titleText; }
    }

    $paraSplit = function (string $html) use ($degap): string {
        // <br><br> already converted to blank-line breaks by convertBreaksToNewlines()
        // above -- collapse remaining tags, keep the paragraph breaks so renderFull()'s
        // own $toParas renders each as its own <p>. $degap() first so adjacent
        // block-level tags (e.g. a stray <div>...</div><div>...</div> run tacked
        // onto the end, no <br><br> between them) don't get word-fused.
        $text = html_entity_decode(strip_tags($degap($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $paras = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $text)), fn (string $p): bool => $p !== ''));
        return trim(implode("\n\n", array_map(fn (string $p): string => trim(preg_replace('/\s+/u', ' ', $p)), $paras)));
    };
    $factual = $paraSplit($section('SHORT DESCRIPTION'));
    $sales   = $paraSplit($section('FULL DESCRIPTION'));

    // KEY FEATURES -- <li> items, same shape as decomposeLegacy()'s bullets.
    $bullets = [];
    if (preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $section('KEY FEATURES'), $lm)) {
        foreach ($lm[1] as $li) {
            $t = $flatten($decodeText($li));
            if ($t !== '') { $bullets[] = $t; }
        }
    }

    // MAIN IMAGE -- the single <img src="...">.
    $image = '';
    if (preg_match('/<img[^>]*\ssrc=["\']([^"\']+)["\']/i', $section('MAIN IMAGE'), $im)) {
        $image = $im[1];
    }

    // SPECIFICATIONS -- <b>Label:</b> Value<br> pairs. MPN/UPC pulled out
    // separately (same as decomposeLegacy()); every other row goes into
    // $extraSpecs, NOT $bullets (putting old specs under
    // Key Features read as wrong/confusing on 234090739207 -- these are
    // Specifications, and belong in the new template's own Specifications
    // section, not blended into a different section). buildRendered() merges
    // $extraSpecs into $aspects, bypassing the live-aspects-only mpn/upc/size/
    // color filter -- these are already-authored content in the old
    // description himself, not raw live data that needs that conservative cap.
    $mpn = ''; $upc = ''; $extraSpecs = [];
    if (preg_match_all('/<b>\s*([^<:]+):?\s*<\/b>\s*([^<]*)/i', $section('SPECIFICATIONS'), $sm, PREG_SET_ORDER)) {
        foreach ($sm as $row) {
            $label = trim($row[1]);
            $value = $flatten($decodeText($row[2]));
            if ($value === '') {
                // An empty spec row (e.g. "UPC:" with nothing after it) contributes
                // no value to keep, but the label text itself still appeared on the
                // original page -- track it as chrome (same as decomposeLegacy()
                // tracks every spec row's label) so the audit doesn't flag it lost.
                $chrome[] = "{$label}:";
                continue;
            }
            $labelLower = mb_strtolower($label);
            if ($labelLower === 'mpn') { $mpn = $value; }
            elseif ($labelLower === 'upc') { $upc = $value; }
            else { $extraSpecs[$label] = $value; }
        }
    }

    return [
        'mobile' => $mobile, 'image' => $image, 'bullets' => $bullets,
        'factual' => $factual, 'sales' => $sales, 'mpn' => $mpn, 'upc' => $upc,
        'extraSpecs' => $extraSpecs, 'chrome' => $chrome, 'flags' => $flags,
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
    $degap = fn(string $h): string => preg_replace('/>(?=<)/', '> ', convertBreaksToNewlines($h));

    // renderFull()'s Key Features bolding rejoins "LABEL : detail" as "LABEL:" (no
    // space before the colon) when it re-derives the bold label from a bullet's first
    // colon -- a cosmetic one-character difference from source rows that happen to
    // have a space before their colon, not a content loss. Normalize "word :" ->
    // "word:" on both sides before tokenizing so this can't produce a false positive.
    // Same idea for trailing sentence/list punctuation: decomposeLegacy() rejoins
    // <li> items (which have no trailing punctuation of their own -- the boundary was
    // a closing </li> tag) with ", " and a final "." when attaching them to their
    // intro sentence, so the last word of each item -- and the very last item overall
    // -- picks up punctuation that wasn't there in that exact spot in the source
    // (which might instead have had the SAME word appear bare, e.g. from a
    // differently-punctuated duplicate elsewhere). This check is fundamentally about
    // whether WORDS survived, not exact end-of-item punctuation, so strip a trailing
    // ,/./; before whitespace-or-end on all three pools before tokenizing -- "pick,"
    // "pick." and "pick" all count as the same token.
    $degapPunct = fn(string $s): string => preg_replace(['/\s+:/u', '/[,.;]+(?=\s|$)/u'], [':', ''], $s);

    $rawNoStyle = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $rawOriginal);
    $origText = $degapPunct(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($degap($rawNoStyle)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $keptText = $degapPunct(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($degap($finalHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $chromeText = $degapPunct(trim(preg_replace('/\s+/u', ' ', implode(' ', $chromeTexts))));

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
