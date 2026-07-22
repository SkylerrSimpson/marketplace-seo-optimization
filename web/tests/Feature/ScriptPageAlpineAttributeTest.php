<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards against the recurring "leaked x-data" bug class: a literal double-quote
 * inside the double-quoted x-data="{ ... }" attribute closes it early, so the
 * rest of the Alpine component renders as visible page text. Parses the DOM and
 * asserts the component's methods are actually inside an x-data attribute — if the
 * attribute broke, they'd have leaked into the body and this fails.
 */
final class ScriptPageAlpineAttributeTest extends TestCase
{
    use RefreshDatabase;

    public function test_script_page_x_data_attribute_is_not_broken(): void
    {
        // A latest run whose stdout contains quotes exercises the @js escaping path
        // alongside the static x-data body.
        ScriptRun::factory()->create([
            'user_id' => User::factory(),
            'script_slug' => 'ebay.apply-aspects',
            'status' => ScriptRunStatus::Succeeded,
            'stdout' => 'said "hello" and it\'s fine — —',
        ]);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('scripts.show', 'ebay.apply-aspects'))
            ->getContent();

        // Parse the main component's x-data value exactly like a browser does: an
        // HTML attribute value ends at the very first '"'. Because @js/@blade escape
        // every real quote inside the value, that first '"' should be the true
        // closer '}"', so the trimmed value ends with '}'. A stray literal '"'
        // anywhere inside truncates the value early — it then ends mid-expression
        // (not on '}') and everything past it leaks into the page body.
        $anchor = strpos($html, 'grid grid-cols-1 md:grid-cols-2');
        $this->assertNotFalse($anchor, 'Could not locate the main script component.');

        $valueStart = strpos($html, 'x-data="', $anchor) + strlen('x-data="');
        $valueEnd = strpos($html, '"', $valueStart);
        $attributeValue = rtrim(substr($html, $valueStart, $valueEnd - $valueStart));

        $this->assertStringEndsWith(
            '}',
            $attributeValue,
            'The main x-data attribute broke early — a literal " inside it truncated the value '
            .'before its closing "}", leaking the rest of the Alpine component into the page body.'
        );
    }
}
