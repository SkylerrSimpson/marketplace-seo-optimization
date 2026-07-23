<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai;

use Ige\Amazon\Ai\Provider\ProviderInterface;

/**
 * Generate an Amazon modular title (item_name + title_differentiation) for one
 * product with one provider, in a SINGLE request.
 *
 * "Modular title" is Amazon's name for the item_name / title_differentiation
 * pair. Both share the same product context, so one prompt returns both — one
 * call (and cost) per provider per SKU. The model is always required to return
 * both attributes, each within its own character limit.
 *
 * Composes the shared context block with the two attribute sections
 * (ItemNamePromptBuilder, TitleDifferentiationPromptBuilder), calls the provider
 * once, then hands the shared response to ItemNameGenerator and
 * TitleDifferentiationGenerator for per-attribute parsing/validation.
 *
 * May throw if the provider call fails; callers catch per SKU.
 */
final class ModularTitleGenerator
{
    private const MAX_TOKENS = 320;

    public function __construct(
        private ProviderInterface $provider,
    ) {
    }

    /**
     * @return array{provider:string,model:string,item_name:array<string,mixed>,title_differentiation:array<string,mixed>,raw:string,usage:array<string,mixed>}
     */
    public function generate(ProductContext $ctx): array
    {
        $result = $this->provider->complete(self::buildPrompt($ctx), ['maxTokens' => self::MAX_TOKENS]);

        return [
            'provider'              => $result->provider,
            'model'                 => $result->model,
            'item_name'             => ItemNameGenerator::generate($result, $ctx),
            'title_differentiation' => TitleDifferentiationGenerator::generate($result, $ctx),
            'raw'                   => $result->rawText,
            'usage'                 => $result->usage,
        ];
    }

    /**
     * Prompt and output-token cap this generator would send for $ctx, without
     * touching the provider — lets a dry run project token cost with no API key.
     * The single call covers both item_name and title_differentiation.
     *
     * @return array{prompt:string,maxOutputTokens:int}
     */
    public static function previewRequest(ProductContext $ctx): array
    {
        return ['prompt' => self::buildPrompt($ctx), 'maxOutputTokens' => self::MAX_TOKENS];
    }

    private static function buildPrompt(ProductContext $ctx): string
    {
        $inMax          = $ctx->itemNameMaxLen;
        $tdMax          = $ctx->titleDiffMaxLen;
        $brand          = $ctx->brand !== '' ? $ctx->brand : '(unknown)';
        $existing       = $ctx->existingTitle !== '' ? $ctx->existingTitle : '(none)';
        $description    = $ctx->description !== '' ? $ctx->description : '(none)';
        $searchTerms    = $ctx->searchTerms !== '' ? $ctx->searchTerms : '(none)';
        $featuresText   = self::bulletList($ctx->features, 5);
        $itemNameRules  = ItemNamePromptBuilder::section($ctx);
        $titleDiffRules = TitleDifferentiationPromptBuilder::section($ctx);

        return <<<PROMPT
You are an Amazon SP-API listing specialist and an expert at writing
search-engine-optimized (SEO) Amazon listings. For the product below, write an
item_name of AT MOST {$inMax} characters and a title_differentiation of AT MOST
{$tdMax} characters. Always return both, each within its own limit.

=== PRODUCT CONTEXT ===
SKU: {$ctx->sku}
Product Type: {$ctx->productType}
Brand (from catalog): {$brand}
Existing Title: {$existing}
Description: {$description}
Search Terms: {$searchTerms}
Features:
{$featuresText}
{$itemNameRules}

{$titleDiffRules}

=== RETURN ===
Return ONLY a valid JSON object, no markdown fences, in this exact shape:
{"item_name": "<= {$inMax} char title", "tokens": {"brand": "", "pack_size": "", "pack_size_unit": "", "size": "", "color": "", "name": ""}, "title_differentiation": "<= {$tdMax} char benefit phrase"}
PROMPT;
    }

    /** @param list<string> $features */
    private static function bulletList(array $features, int $limit): string
    {
        $text = '';
        foreach (array_slice(array_filter($features), 0, $limit) as $f) {
            $text .= "- {$f}\n";
        }
        return $text !== '' ? $text : "(none)\n";
    }
}
