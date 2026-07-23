<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

/**
 * The item_name instruction section of the combined modular-title prompt.
 *
 * Emits only the item_name-specific rules (token format + cap); the shared
 * product-context block and the JSON return shape are owned by
 * ModularTitleGenerator, which composes this section with the
 * title_differentiation section into one prompt / one call.
 */
final class ItemNamePromptBuilder
{
    public static function section(ProductContext $ctx): string
    {
        $max         = $ctx->itemNameMaxLen;
        $schemaGuide = $ctx->itemNameSchemaDescription !== ''
            ? "\nAmazon schema guidance: {$ctx->itemNameSchemaDescription}"
            : '';

        return <<<SECTION
=== ITEM_NAME (title, <= {$max} chars) ===
Write a concise, keyword-rich, SEO-optimized item_name — a shopper-facing title,
not a part number. Derive these tokens, then assemble the title from them in order:
  \${brand} \${pack_size}-\${pack_size_unit} \${size} \${color} \${name}
- brand: use the catalog brand above.
- pack_size + pack_size_unit: parse from the existing title (e.g. "4-pk", or a
  dimension like 13" -> pack_size "13", pack_size_unit "in"). Omit if absent.
- size, color, name: parse from the existing title (name = what the product IS).
Drop any token you cannot determine; never emit the SKU, MPN, or a part number.
Lead with the brand; condense to AT MOST {$max} characters.{$schemaGuide}
SECTION;
    }
}
