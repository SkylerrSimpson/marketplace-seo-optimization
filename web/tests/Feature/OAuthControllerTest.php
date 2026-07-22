<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketplaceCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class OAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_oauth_routes(): void
    {
        $this->get(route('oauth.authorize', ['marketplace' => 'shopify']))->assertRedirect('/login');
        $this->get(route('oauth.callback', ['marketplace' => 'shopify']))->assertRedirect('/login');
    }

    public function test_authorize_404s_for_a_non_shopify_marketplace(): void
    {
        Process::fake();

        $this->actingAs(User::factory()->create())
            ->get(route('oauth.authorize', ['marketplace' => 'ebay']))
            ->assertNotFound();
    }

    public function test_callback_404s_for_a_non_shopify_marketplace(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('oauth.callback', ['marketplace' => 'ebay']))
            ->assertNotFound();
    }

    public function test_authorize_stores_state_in_session_and_redirects_to_the_consent_url(): void
    {
        Process::fake(['*' => Process::result(output: 'https://shop.example/admin/oauth/authorize?state=x')]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('oauth.authorize', ['marketplace' => 'shopify']));

        $response->assertRedirect('https://shop.example/admin/oauth/authorize?state=x');
        $response->assertSessionHas('oauth.shopify.state');

        // The helper was asked for an authorize URL, handed our own callback route
        // as the redirect target and the freshly-minted state.
        Process::assertRan(fn ($process) => in_array('authorize-url', $process->command, true)
            && collect($process->command)->contains(fn ($a) => str_starts_with((string) $a, '--redirect-uri='))
            && collect($process->command)->contains(fn ($a) => str_starts_with((string) $a, '--state=')));
    }

    public function test_authorize_redirects_to_credentials_with_failure_when_the_helper_errors(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);

        $this->actingAs(User::factory()->create())
            ->get(route('oauth.authorize', ['marketplace' => 'shopify']))
            ->assertRedirect(route('credentials.index'))
            ->assertSessionHas('status', 'oauth-failed');
    }

    public function test_callback_with_mismatched_state_403s_and_writes_nothing(): void
    {
        Process::fake();

        $this->actingAs(User::factory()->create())
            ->withSession(['oauth.shopify.state' => 'the-real-state'])
            ->get(route('oauth.callback', ['marketplace' => 'shopify', 'state' => 'a-forged-state', 'code' => 'c']))
            ->assertForbidden();

        $this->assertDatabaseCount('marketplace_credentials', 0);
        Process::assertNothingRan();
    }

    public function test_callback_with_no_state_in_session_403s(): void
    {
        Process::fake();

        $this->actingAs(User::factory()->create())
            ->get(route('oauth.callback', ['marketplace' => 'shopify', 'state' => 'anything', 'code' => 'c']))
            ->assertForbidden();

        $this->assertDatabaseCount('marketplace_credentials', 0);
    }

    public function test_callback_with_a_declined_error_redirects_without_writing(): void
    {
        Process::fake();

        $this->actingAs(User::factory()->create())
            ->withSession(['oauth.shopify.state' => 's'])
            ->get(route('oauth.callback', ['marketplace' => 'shopify', 'error' => 'access_denied']))
            ->assertRedirect(route('credentials.index'))
            ->assertSessionHas('status', 'oauth-declined');

        $this->assertDatabaseCount('marketplace_credentials', 0);
        Process::assertNothingRan();
    }

    public function test_callback_happy_path_stores_the_token_and_clears_the_state(): void
    {
        Process::fake(['*' => Process::result(output: json_encode([
            'access_token' => 'shpat_freshtoken',
            'scope' => 'read_products,write_products',
            'shop' => 'e2ab17.myshopify.com',
            'api_version' => '2026-04',
        ]))]);

        $response = $this->actingAs(User::factory()->create())
            ->withSession(['oauth.shopify.state' => 'match'])
            ->get(route('oauth.callback', ['marketplace' => 'shopify', 'state' => 'match', 'code' => 'the-code']));

        $response->assertRedirect(route('credentials.index'));
        $response->assertSessionHas('status', 'oauth-connected');
        // Nonce consumed — can't be replayed.
        $response->assertSessionMissing('oauth.shopify.state');

        $credential = MarketplaceCredential::forAccount('shopify', 'default')->first();
        $this->assertNotNull($credential);
        $this->assertSame('shpat_freshtoken', $credential->credentials['admin_api_token']);
        $this->assertSame('e2ab17.myshopify.com', $credential->credentials['shop_domain']);
        $this->assertSame('2026-04', $credential->credentials['api_version']);

        // The helper was asked to exchange the code, never to touch .env.
        Process::assertRan(fn ($process) => in_array('web-exchange', $process->command, true)
            && collect($process->command)->contains('--code=the-code'));
    }

    public function test_callback_merges_and_never_clobbers_a_different_marketplace_row(): void
    {
        MarketplaceCredential::factory()->create([
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'ebay-app-id-untouched'],
        ]);

        Process::fake(['*' => Process::result(output: json_encode([
            'access_token' => 'shpat_x', 'scope' => '', 'shop' => 's.myshopify.com', 'api_version' => '2026-04',
        ]))]);

        $this->actingAs(User::factory()->create())
            ->withSession(['oauth.shopify.state' => 'm'])
            ->get(route('oauth.callback', ['marketplace' => 'shopify', 'state' => 'm', 'code' => 'c']));

        // eBay row is exactly as it was.
        $ebay = MarketplaceCredential::forAccount('ebay', 'dows')->first();
        $this->assertSame('ebay-app-id-untouched', $ebay->credentials['app_id']);
    }

    public function test_callback_with_a_failed_exchange_redirects_with_failure_and_writes_nothing(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'Exchange failed (HTTP 400)', exitCode: 1)]);

        $this->actingAs(User::factory()->create())
            ->withSession(['oauth.shopify.state' => 'm'])
            ->get(route('oauth.callback', ['marketplace' => 'shopify', 'state' => 'm', 'code' => 'c']))
            ->assertRedirect(route('credentials.index'))
            ->assertSessionHas('status', 'oauth-failed');

        $this->assertDatabaseCount('marketplace_credentials', 0);
    }
}
