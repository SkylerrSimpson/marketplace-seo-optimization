<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * The title_differentiation instruction section of the combined modular-title
 * prompt (item highlight). Emits only its own rules; the shared product-context
 * block and JSON return shape are owned by ModularTitleGenerator.
 */
final class TitleDifferentiationPromptBuilder
{
    public static function section(ProductContext $ctx): string
    {
        $max         = $ctx->titleDiffMaxLen;
        $targetMin   = (int) round($max * 0.8);
        $schemaGuide = $ctx->titleDiffSchemaDescription !== ''
            ? "\nAmazon schema guidance: {$ctx->titleDiffSchemaDescription}"
            : '';

        return <<<SECTION
=== TITLE_DIFFERENTIATION (item highlight, {$targetMin}–{$max} chars) ===
Write a compact, comma-separated list of the product's strongest, most
search-relevant features/benefits — NOT a full sentence, NOT the brand, and NOT a
repeat of the item_name (e.g. "Set of 6, Dishwasher Safe, 8oz, BPA-Free, Stackable").
Use the available space: aim for {$targetMin}–{$max} characters, leading with the
highest-value keywords. Never pad with filler or repeat a point.{$schemaGuide}
SECTION;
    }
}
