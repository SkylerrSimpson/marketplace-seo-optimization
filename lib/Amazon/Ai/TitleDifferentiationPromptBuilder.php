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
        $schemaGuide = $ctx->titleDiffSchemaDescription !== ''
            ? "\nAmazon schema guidance: {$ctx->titleDiffSchemaDescription}"
            : '';

        return <<<SECTION
=== TITLE_DIFFERENTIATION (item highlight, <= {$max} chars) ===
Write a SHORT benefit- or feature-driven phrase — NOT a full sentence, NOT the
brand, and NOT a repeat of the item_name (e.g. "Set of 6, Dishwasher Safe, 8oz").
Lead with the strongest features/benefits; keep it to AT MOST {$max} characters.{$schemaGuide}
SECTION;
    }
}
