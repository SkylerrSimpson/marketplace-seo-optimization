<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketplaceCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CredentialTest extends TestCase
{
    use RefreshDatabase;

    private ?User $actingUser = null;

    /** Memoized signed-in user; credential fixtures default to being owned by
     * them so the per-user scoping resolves their own rows. */
    private function actingUser(): User
    {
        return $this->actingUser ??= User::factory()->create();
    }

    public function test_guest_is_redirected_to_login_for_all_credential_routes(): void
    {
        $this->get(route('credentials.index'))->assertRedirect('/login');
        $this->get(route('credentials.edit', ['marketplace' => 'ebay', 'account' => 'dows']))->assertRedirect('/login');
        $this->put(route('credentials.update', ['marketplace' => 'ebay', 'account' => 'dows']))->assertRedirect('/login');
        $this->delete(route('credentials.destroy', ['marketplace' => 'ebay', 'account' => 'dows']))->assertRedirect('/login');
    }

    public function test_index_add_account_form_only_asks_for_an_account_name_when_there_is_a_real_choice(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('credentials.index'));

        $response->assertOk();
        // Shopify (accounts => ['default']) skips the account question entirely
        // via isSingleAccount() — a marketplace with only one possible answer
        // shouldn't make someone type or pick it.
        $response->assertSee('isSingleAccount(mp)', false);
        $response->assertSee('input type="hidden" name="account" value="default"', false);
    }

    public function test_index_never_leaks_a_decrypted_secret_into_the_response(): void
    {
        MarketplaceCredential::factory()->create(['user_id' => $this->actingUser()->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'super-secret-value-should-not-leak'],
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('credentials.index'));

        $response->assertOk();
        $response->assertDontSee('super-secret-value-should-not-leak');
    }

    public function test_edit_page_never_leaks_a_decrypted_secret_into_the_response(): void
    {
        MarketplaceCredential::factory()->create(['user_id' => $this->actingUser()->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'super-secret-value-should-not-leak'],
        ]);

        $response = $this->actingAs($this->actingUser())
            ->get(route('credentials.edit', ['marketplace' => 'ebay', 'account' => 'dows']));

        $response->assertOk();
        $response->assertDontSee('super-secret-value-should-not-leak');
        // The masked placeholder is the one thing that SHOULD be there.
        $response->assertSee('leave blank to keep', false);
    }

    public function test_blank_field_on_update_leaves_the_existing_value_unchanged(): void
    {
        MarketplaceCredential::factory()->create(['user_id' => $this->actingUser()->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'original-app-id'],
        ]);

        $this->actingAs($this->actingUser())->put(
            route('credentials.update', ['marketplace' => 'ebay', 'account' => 'dows']),
            [
                'app_id' => '',              // blank — must NOT clear the existing value
                'cert_id' => 'new-cert-id',   // non-blank — must be set
            ],
        )->assertRedirect(route('credentials.index'));

        $credential = MarketplaceCredential::forAccount('ebay', 'dows')->first();

        $this->assertSame('original-app-id', $credential->credentials['app_id']);
        $this->assertSame('new-cert-id', $credential->credentials['cert_id']);
    }

    public function test_update_creates_a_row_when_none_exists_yet(): void
    {
        $this->assertNull(MarketplaceCredential::forAccount('ebay', 'ige')->first());

        $this->actingAs($this->actingUser())->put(
            route('credentials.update', ['marketplace' => 'ebay', 'account' => 'ige']),
            ['app_id' => 'fresh-app-id'],
        )->assertRedirect(route('credentials.index'));

        $credential = MarketplaceCredential::forAccount('ebay', 'ige')->first();

        $this->assertNotNull($credential);
        $this->assertSame('fresh-app-id', $credential->credentials['app_id']);
    }

    public function test_a_field_not_known_for_the_marketplace_is_never_stored(): void
    {
        $this->actingAs($this->actingUser())->put(
            route('credentials.update', ['marketplace' => 'ebay', 'account' => 'dows']),
            ['app_id' => 'real-field', 'not_a_real_field' => 'should-be-dropped'],
        )->assertRedirect(route('credentials.index'));

        $credential = MarketplaceCredential::forAccount('ebay', 'dows')->first();

        $this->assertArrayNotHasKey('not_a_real_field', $credential->credentials);
        $this->assertSame('real-field', $credential->credentials['app_id']);
    }

    public function test_update_is_idempotent_on_the_same_marketplace_and_account(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->put(
            route('credentials.update', ['marketplace' => 'ebay', 'account' => 'dows']),
            ['app_id' => 'first-call'],
        );
        $this->actingAs($user)->put(
            route('credentials.update', ['marketplace' => 'ebay', 'account' => 'dows']),
            ['cert_id' => 'second-call'],
        );

        $this->assertSame(1, MarketplaceCredential::forAccount('ebay', 'dows')->count());
    }

    public function test_destroy_deletes_the_credential_and_redirects(): void
    {
        MarketplaceCredential::factory()->create(['user_id' => $this->actingUser()->id, 'marketplace' => 'ebay', 'account' => 'dows']);

        $this->actingAs($this->actingUser())
            ->delete(route('credentials.destroy', ['marketplace' => 'ebay', 'account' => 'dows']))
            ->assertRedirect(route('credentials.index'));

        $this->assertNull(MarketplaceCredential::forAccount('ebay', 'dows')->first());
    }

    public function test_destroy_404s_when_no_credential_exists_for_the_account(): void
    {
        $this->actingAs($this->actingUser())
            ->delete(route('credentials.destroy', ['marketplace' => 'ebay', 'account' => 'ige']))
            ->assertNotFound();
    }

    public function test_destroy_does_not_touch_a_different_accounts_credentials(): void
    {
        MarketplaceCredential::factory()->create(['user_id' => $this->actingUser()->id, 'marketplace' => 'ebay', 'account' => 'dows']);
        MarketplaceCredential::factory()->create(['user_id' => $this->actingUser()->id, 'marketplace' => 'ebay', 'account' => 'ige']);

        $this->actingAs($this->actingUser())
            ->delete(route('credentials.destroy', ['marketplace' => 'ebay', 'account' => 'dows']));

        $this->assertNotNull(MarketplaceCredential::forAccount('ebay', 'ige')->first());
    }

    public function test_new_form_rejects_an_account_not_in_the_known_roster_for_ebay(): void
    {
        $this->actingAs($this->actingUser())
            ->get(route('credentials.new', ['marketplace' => 'ebay', 'account' => 'dows2']))
            ->assertSessionHasErrors('account');
    }

    public function test_index_never_reflects_old_marketplace_input_as_a_raw_quote_break_out_of_its_js_attribute(): void
    {
        // A validation failure flashes the submitted (invalid) marketplace back
        // via old() — index.blade.php embeds it in x-data="{ marketplace: ... }".
        // {{ old(...) }} wrapped in a hand-rolled single-quoted JS string only
        // HTML-escapes ' to &#039;, which the browser decodes back to a literal
        // ' before Alpine evaluates the attribute — breaking out of the string
        // and letting arbitrary JS run. Must go through @js() instead, which is
        // safe in both the JS and HTML-attribute context at once.
        $user = $this->actingUser();
        $payload = "x'+(window.pwned=1)+'";

        $this->actingAs($user)
            ->from(route('credentials.index'))
            ->get(route('credentials.new', ['marketplace' => $payload, 'account' => 'y']))
            ->assertRedirect(route('credentials.index'));

        $response = $this->actingAs($user)->get(route('credentials.index'));

        $response->assertOk();
        $response->assertDontSee("marketplace: 'x'+(window.pwned=1)+''", false);
    }

    public function test_new_form_accepts_a_known_account_for_ebay(): void
    {
        $this->actingAs($this->actingUser())
            ->get(route('credentials.new', ['marketplace' => 'ebay', 'account' => 'ige']))
            ->assertRedirect(route('credentials.edit', ['marketplace' => 'ebay', 'account' => 'ige']));
    }

    public function test_index_lists_shopify_as_a_marketplace_option(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('credentials.index'));

        $response->assertOk();
        $response->assertSee('shopify');
    }

    public function test_new_form_accepts_the_default_sentinel_account_for_shopify(): void
    {
        $this->actingAs($this->actingUser())
            ->get(route('credentials.new', ['marketplace' => 'shopify', 'account' => 'default']))
            ->assertRedirect(route('credentials.edit', ['marketplace' => 'shopify', 'account' => 'default']));
    }

    public function test_new_form_rejects_any_account_other_than_default_for_shopify(): void
    {
        // Shopify is a single flat store — 'default' is the only valid account,
        // same reasoning as eBay's roster check, just a roster of one.
        $this->actingAs($this->actingUser())
            ->get(route('credentials.new', ['marketplace' => 'shopify', 'account' => 'my-store']))
            ->assertSessionHasErrors('account');
    }

    public function test_edit_page_shows_instructions_for_ebay(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('credentials.edit', ['marketplace' => 'ebay', 'account' => 'dows']));

        $response->assertOk();
        $response->assertSee('mint_refresh_token.php', false);
    }

    public function test_edit_page_shows_instructions_for_shopify(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('credentials.edit', ['marketplace' => 'shopify', 'account' => 'default']));

        $response->assertOk();
        $response->assertSee('oauth_mint.php', false);
    }

    public function test_edit_page_shows_a_set_badge_for_a_configured_field_and_not_set_for_the_rest(): void
    {
        MarketplaceCredential::factory()->create(['user_id' => $this->actingUser()->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'some-value'],
        ]);

        $response = $this->actingAs($this->actingUser())
            ->get(route('credentials.edit', ['marketplace' => 'ebay', 'account' => 'dows']));

        $response->assertOk();
        $response->assertSee('Set', false);
        $response->assertSee('Not set', false);
        // The field that IS set carries the hook the overwrite-confirm script keys off of.
        $response->assertSee('data-was-set="1"', false);
    }
}
