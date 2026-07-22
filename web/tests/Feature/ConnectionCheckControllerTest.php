<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketplaceCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class ConnectionCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('connection-check.show', ['marketplace' => 'ebay']))->assertRedirect('/login');
    }

    public function test_returns_404_for_a_marketplace_with_no_connection_check_script(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('connection-check.show', ['marketplace' => 'shopify']))
            ->assertNotFound();
    }

    public function test_returns_accounts_wrapped_from_the_scripts_own_output(): void
    {
        Process::fake([
            '*' => Process::result(output: '{"dows":{"ok":true,"detail":"connected as X"},"ige":{"ok":false,"detail":"boom"}}'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('connection-check.show', ['marketplace' => 'ebay']));

        $response->assertOk();
        $response->assertExactJson([
            'accounts' => [
                'dows' => ['ok' => true, 'detail' => 'connected as X'],
                'ige' => ['ok' => false, 'detail' => 'boom'],
            ],
        ]);
    }

    public function test_an_infra_failure_returns_error_and_is_not_cached(): void
    {
        // Process errors / unparseable output -> "unreachable", and crucially the
        // next request re-runs the check rather than serving a cached failure.
        Process::fake([
            '*' => Process::result(output: 'Fatal error: could not connect', exitCode: 1),
        ]);

        $user = User::factory()->create();

        $first = $this->actingAs($user)->get(route('connection-check.show', ['marketplace' => 'ebay']));
        $first->assertOk();
        $first->assertExactJson(['error' => 'unreachable']);

        $this->actingAs($user)->get(route('connection-check.show', ['marketplace' => 'ebay']));

        // Not cached: both requests actually ran the process.
        Process::assertRanTimes(fn () => true, 2);
    }

    public function test_a_per_account_auth_failure_is_a_real_result_and_is_cached(): void
    {
        // ok:false is the check succeeding and reporting bad creds — cacheable.
        Process::fake([
            '*' => Process::result(output: '{"dows":{"ok":false,"detail":"bad token"}}'),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('connection-check.show', ['marketplace' => 'ebay']))
            ->assertExactJson(['accounts' => ['dows' => ['ok' => false, 'detail' => 'bad token']]]);
        $this->actingAs($user)->get(route('connection-check.show', ['marketplace' => 'ebay']));

        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_invokes_the_registered_connection_check_script_with_all_and_json(): void
    {
        Process::fake();

        $this->actingAs(User::factory()->create())
            ->get(route('connection-check.show', ['marketplace' => 'ebay']));

        Process::assertRan(fn ($process) => in_array('marketplaces/ebay/scripts/check_connection.php', $process->command, true)
            && in_array('--all', $process->command, true)
            && in_array('--json', $process->command, true));
    }

    public function test_the_signed_in_users_own_credentials_are_injected_into_the_check(): void
    {
        // The check must test the user's OWN stored tokens (same as a real run),
        // not whatever the repo .env holds.
        Process::fake();
        $user = User::factory()->create();
        MarketplaceCredential::factory()->create([
            'user_id' => $user->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'this-users-app-id'],
        ]);

        $this->actingAs($user)->get(route('connection-check.show', ['marketplace' => 'ebay']));

        Process::assertRan(fn ($process) => ($process->environment['EBAY_API_APP_ID'] ?? null) === 'this-users-app-id');
    }

    public function test_one_users_cached_result_is_never_served_to_another_user(): void
    {
        Process::fake(['*' => Process::result(output: '{"dows":{"ok":true,"detail":"x"}}')]);
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->get(route('connection-check.show', ['marketplace' => 'ebay']));
        $this->actingAs($bob)->get(route('connection-check.show', ['marketplace' => 'ebay']));

        // Bob can't be served from Alice's cache — the cache is per-user, so the
        // process runs a second time for him.
        Process::assertRanTimes(fn () => true, 2);
    }

    public function test_result_is_cached_so_a_second_request_does_not_rerun_the_process(): void
    {
        Process::fake([
            '*' => Process::result(output: '{"dows":{"ok":true,"detail":"x"}}'),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('connection-check.show', ['marketplace' => 'ebay']));
        $this->actingAs($user)->get(route('connection-check.show', ['marketplace' => 'ebay']));

        Process::assertRanTimes(fn () => true, 1);
    }
}
