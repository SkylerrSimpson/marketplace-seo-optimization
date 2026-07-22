<?php

declare(strict_types=1);

/**
 * two_pack_title_shrink.php — when "2 Pack " + a source title would exceed
 * eBay's 80-char cap, first TRY an AI-assisted shrink that
 * preserves the core product concept (brand, item type, key distinguishing
 * feature) via natural abbreviation/editing, rather than a blind chop. Only
 * find_two_pack_candidates.php calls this — it computes new_title ONCE, when
 * building the reviewable candidates file, and create_two_pack_listings.php
 * trusts that value (see its own docblock) rather than re-deriving it, since
 * an LLM call isn't perfectly reproducible the way the price/quantity math
 * is, and because a human may have hand-edited the CSV's new_title before
 * sending it back approved.
 *
 * Never blocks a run: no API key configured, the call fails, or the model's
 * output is still somehow over the limit — all fall back to a blunt
 * substr() truncation, same as before this existed, just now flagged via
 * the returned needs_review=true so a human knows to take a look.
 */

use Anthropic\Client;

const TWO_PACK_TITLE_SHRINK_MODEL = 'claude-haiku-4-5'; // cheap/fast is plenty for compressing one short string

/**
 * Returns ['title' => string, 'issue' => ?string]. `title` always fits
 * "2 Pack " + title within 80 chars. `issue` is null when nothing's wrong,
 * or a human-readable explanation of why this one was bluntly truncated
 * (worth a review-column note, not just a bare yes/no) — the caller
 * (find_two_pack_candidates.php) appends this into its combined `issues`
 * column alongside any other checks it runs (e.g. "source title already
 * mentions a pack").
 */
function twoPackTitleWithShrink(string $sourceTitle, ?Client $anthropic, string $model = TWO_PACK_TITLE_SHRINK_MODEL): array
{
    $prefix = '2 Pack ';
    $full = $prefix . $sourceTitle;
    if (strlen($full) <= 80) {
        return ['title' => $full, 'issue' => null];
    }

    if ($anthropic !== null) {
        $maxTitleLen = 80 - strlen($prefix);
        $shortened = requestShortenedTwoPackTitle($anthropic, $sourceTitle, $maxTitleLen, $model);
        if ($shortened !== null && $shortened !== '' && strlen($prefix . $shortened) <= 80) {
            return ['title' => $prefix . $shortened, 'issue' => null];
        }
        $issue = "title exceeds 80 chars; AI shorten attempt didn't produce a usable result, truncated instead — please rewrite";
    } else {
        $issue = 'title exceeds 80 chars and was truncated (no ANTHROPIC_API_KEY configured for AI shorten) — please rewrite';
    }

    return ['title' => twoPackTitle($sourceTitle), 'issue' => $issue];
}

/**
 * One Anthropic call, one title. Returns null on any failure so the caller
 * can fall back cleanly — never throws.
 */
function requestShortenedTwoPackTitle(Client $anthropic, string $sourceTitle, int $maxTitleLen, string $model): ?string
{
    $prompt = "Shorten this eBay product title to at most {$maxTitleLen} characters. "
        . "It will be prefixed with \"2 Pack \" afterward — do not add that prefix yourself, "
        . "just shorten the title that follows it.\n\n"
        . "Preserve the core product identity: brand name (if present), what the item actually "
        . "is, and its single most distinguishing feature (size, color, material, etc.) — drop "
        . "marketing filler, redundant words, and less-important specifics first. Use natural, "
        . "common abbreviations where they genuinely help (e.g. \"Stainless Steel\" -> \"SS\", "
        . "\"with\" -> \"w/\", \"and\" -> \"&\") but don't invent unclear ones.\n\n"
        . "Output ONLY the shortened title text — no quotes, no explanation, no markdown.\n\n"
        . "Original title: {$sourceTitle}";

    try {
        $message = $anthropic->messages->create(
            model: $model,
            maxTokens: 128,
            messages: [['role' => 'user', 'content' => $prompt]],
        );
    } catch (\Throwable $e) {
        return null;
    }

    $text = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $text = $block->text;
            break;
        }
    }

    $text = trim($text, " \t\n\r\0\x0B\"'");

    return $text !== '' ? $text : null;
}

/**
 * Builds an Anthropic client from ANTHROPIC_API_KEY, or null if it's not
 * configured — the caller treats null the same as "AI shrink unavailable,
 * fall back to truncate+flag" rather than erroring, so this script keeps
 * working on a machine that's never had the key set.
 */
function two_pack_anthropic_client_or_null(): ?Client
{
    $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';

    return $apiKey !== '' ? new Client(apiKey: $apiKey) : null;
}
