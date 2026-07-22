<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RunScriptJob;
use App\Models\MarketplaceCredential;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use App\Scripts\CredentialEnvMapper;
use App\Scripts\ScriptRegistry;
use App\Scripts\WriteConfirmationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class RunScriptJobTest extends TestCase
{
    use RefreshDatabase;

    private ?User $actingUser = null;

    /** Memoized run owner; a run's injected credentials are read from this
     * user, so credential fixtures must be created under the same user. */
    private function actingUser(): User
    {
        return $this->actingUser ??= User::factory()->create();
    }

    private function makeRun(array $overrides = []): ScriptRun
    {
        return ScriptRun::factory()->create(array_merge([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.enrich-listings',
            'params' => ['account' => 'dows'],
        ], $overrides));
    }

    private function dispatch(ScriptRun $run): void
    {
        (new RunScriptJob($run))->handle(
            app(ScriptRegistry::class),
            app(CredentialEnvMapper::class),
            app(WriteConfirmationResolver::class),
        );
    }

    public function test_bool_param_emits_the_bare_flag_only_when_true(): void
    {
        Process::fake();

        $run = $this->makeRun(['params' => ['account' => 'dows', 'refresh' => true]]);
        $this->dispatch($run);

        Process::assertRan(fn ($process) => in_array('--refresh', $process->command, true)
            && in_array('--account=dows', $process->command, true));
    }

    public function test_bool_param_omitted_entirely_when_false(): void
    {
        Process::fake();

        $run = $this->makeRun(['params' => ['account' => 'dows', 'refresh' => false]]);
        $this->dispatch($run);

        Process::assertRan(fn ($process) => ! in_array('--refresh', $process->command, true));
    }

    public function test_string_param_omitted_from_argv_when_blank(): void
    {
        Process::fake();

        $run = $this->makeRun(['params' => ['account' => 'dows', 'ids' => '']]);
        $this->dispatch($run);

        Process::assertRan(function ($process) {
            foreach ($process->command as $arg) {
                if (is_string($arg) && str_starts_with($arg, '--ids=')) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_argv_starts_with_the_definitions_interpreter_and_cli_path(): void
    {
        Process::fake();

        $run = $this->makeRun();
        $this->dispatch($run);

        Process::assertRan(fn ($process) => $process->command[0] === 'php8.2'
            && $process->command[1] === 'marketplaces/ebay/scripts/enrich_listings.php');
    }

    public function test_credential_env_only_injected_when_a_matching_row_exists(): void
    {
        Process::fake();
        MarketplaceCredential::factory()->create([
            'user_id' => $this->actingUser()->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'fake-app-id-123'],
        ]);

        $run = $this->makeRun(['params' => ['account' => 'dows']]);
        $this->dispatch($run);

        Process::assertRan(fn ($process) => ($process->environment['EBAY_API_APP_ID'] ?? null) === 'fake-app-id-123');
    }

    public function test_a_credential_less_run_injects_blank_overrides_not_nothing(): void
    {
        // Hardening: with no stored credentials the run must NOT fall through to
        // whatever the repo .env holds — every known credential var is injected as
        // an empty string so the subprocess can't inherit the server's tokens.
        Process::fake();

        $run = $this->makeRun(['params' => ['account' => 'dows']]);
        $this->dispatch($run);

        Process::assertRan(function ($process) {
            foreach (['EBAY_API_APP_ID', 'EBAY_API_CERT_ID', 'EBAY_API_DEV_ID', 'EBAY_API_RU_NAME', 'EBAY_API_REFRESH_TOKEN'] as $var) {
                if (($process->environment[$var] ?? null) !== '') {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_partial_credentials_still_blank_the_unset_fields(): void
    {
        // A user who set only app_id must not have the rest silently completed from
        // the .env — the unset fields are blanked, not inherited.
        Process::fake();
        MarketplaceCredential::factory()->create([
            'user_id' => $this->actingUser()->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'only-app-id-set'],
        ]);

        $run = $this->makeRun(['params' => ['account' => 'dows']]);
        $this->dispatch($run);

        Process::assertRan(fn ($process) => ($process->environment['EBAY_API_APP_ID'] ?? null) === 'only-app-id-set'
            && ($process->environment['EBAY_API_CERT_ID'] ?? null) === '');
    }

    public function test_credential_env_injected_for_a_script_with_no_account_param_via_the_default_sentinel(): void
    {
        // Shopify scripts have no --account flag at all (single flat store) — the
        // env injection has to fall back to the 'default' sentinel account rather
        // than requiring a param that will never exist for this marketplace.
        Process::fake();
        MarketplaceCredential::factory()->create([
            'user_id' => $this->actingUser()->id,
            'marketplace' => 'shopify',
            'account' => 'default',
            'credentials' => ['shop_domain' => 'fake-shop.myshopify.com'],
        ]);

        $run = $this->makeRun(['script_slug' => 'shopify.audit-products', 'params' => []]);
        $this->dispatch($run);

        Process::assertRan(fn ($process) => ($process->environment['SHOP_DOMAIN'] ?? null) === 'fake-shop.myshopify.com');
    }

    public function test_a_credential_less_no_account_script_also_gets_blank_overrides(): void
    {
        // Same hardening for the Shopify (no --account) path via the 'default'
        // sentinel — blanks, not an inherited SHOP_DOMAIN from the .env.
        Process::fake();

        $run = $this->makeRun(['script_slug' => 'shopify.audit-products', 'params' => []]);
        $this->dispatch($run);

        Process::assertRan(fn ($process) => ($process->environment['SHOP_DOMAIN'] ?? null) === ''
            && ($process->environment['ADMIN_API_TOKEN'] ?? null) === '');
    }

    public function test_successful_result_marks_the_run_succeeded_and_captures_output(): void
    {
        Process::fake([
            '*' => Process::result(output: 'all good', errorOutput: '', exitCode: 0),
        ]);

        $run = $this->makeRun();
        $this->dispatch($run);
        $run->refresh();

        $this->assertSame(ScriptRunStatus::Succeeded, $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertSame("all good\n", $run->stdout);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }

    public function test_nonzero_exit_marks_the_run_failed_with_the_real_exit_code(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
        ]);

        $run = $this->makeRun();
        $this->dispatch($run);
        $run->refresh();

        $this->assertSame(ScriptRunStatus::Failed, $run->status);
        $this->assertSame(1, $run->exit_code);
        $this->assertSame("boom\n", $run->stderr);
    }

    public function test_single_item_live_run_pipes_the_item_id_as_stdin(): void
    {
        Process::fake();

        $run = $this->makeRun([
            'script_slug' => 'ebay.apply-aspects',
            'params' => ['account' => 'dows', 'item' => '123456789012', 'live' => true],
        ]);
        $this->dispatch($run);

        Process::assertRan(fn ($process) => $process->input === '123456789012');
    }

    public function test_bulk_live_run_gets_no_stdin(): void
    {
        Process::fake();

        $run = $this->makeRun([
            'script_slug' => 'ebay.apply-aspects',
            'params' => ['account' => 'dows', 'items' => '111,222', 'live' => true, 'confirm' => 'WRITE'],
        ]);
        $this->dispatch($run);

        Process::assertRan(fn ($process) => empty($process->input));
    }

    public function test_non_live_write_run_gets_no_stdin(): void
    {
        Process::fake();

        $run = $this->makeRun([
            'script_slug' => 'ebay.apply-aspects',
            'params' => ['account' => 'dows', 'item' => '123456789012'],
        ]);
        $this->dispatch($run);

        Process::assertRan(fn ($process) => empty($process->input));
    }

    /**
     * appendOutput() is what makes a run's stdout/stderr visible WHILE it's
     * still running (the whole point of streaming — see RunScriptJob's own
     * docblock on the method) rather than only once at the end. It's
     * private because it's an implementation detail of the real-time
     * Process callback, not a public API — reflection is the correct tool
     * to test it in isolation, without depending on timing of a real or
     * faked subprocess's chunk boundaries.
     */
    private function callAppendOutput(RunScriptJob $job, string $type, string $output): void
    {
        $method = new \ReflectionMethod($job, 'appendOutput');
        $method->invoke($job, $type, $output);
    }

    public function test_append_output_accumulates_stdout_across_multiple_chunks(): void
    {
        $run = $this->makeRun();
        $job = new RunScriptJob($run);

        $this->callAppendOutput($job, 'out', "first chunk\n");
        $this->callAppendOutput($job, 'out', "second chunk\n");

        $this->assertSame("first chunk\nsecond chunk\n", $run->fresh()->stdout);
    }

    public function test_append_output_accumulates_stderr_separately_from_stdout(): void
    {
        $run = $this->makeRun();
        $job = new RunScriptJob($run);

        $this->callAppendOutput($job, 'out', 'out chunk');
        $this->callAppendOutput($job, 'err', 'err chunk');

        $run->refresh();
        $this->assertSame('out chunk', $run->stdout);
        $this->assertSame('err chunk', $run->stderr);
    }

    public function test_append_output_ignores_empty_chunks(): void
    {
        $run = $this->makeRun();
        $job = new RunScriptJob($run);

        $this->callAppendOutput($job, 'out', 'real content');
        $this->callAppendOutput($job, 'out', '');

        $this->assertSame('real content', $run->fresh()->stdout);
    }

    public function test_pid_is_captured_from_the_started_process(): void
    {
        Process::fake();

        $run = $this->makeRun();
        $this->dispatch($run);

        // FakeProcessDescription's default processId — see
        // vendor/laravel/framework/.../Process/FakeProcessDescription.php.
        $this->assertSame(1000, $run->fresh()->pid);
    }

    /**
     * Cancelled while still Pending in the queue — a real race, not
     * hypothetical, since a job can sit queued for a moment before it's
     * picked up. This must be checked BEFORE the child process is ever
     * spawned, so a write-type run can never slip a live call through a
     * cancel-before-start race.
     */
    public function test_cancel_requested_before_the_job_starts_never_spawns_a_process(): void
    {
        Process::fake();

        $run = $this->makeRun(['cancel_requested_at' => now()]);
        $this->dispatch($run);

        Process::assertNothingRan();
        $this->assertSame(ScriptRunStatus::Cancelled, $run->fresh()->status);
        $this->assertNull($run->fresh()->exit_code);
    }

    /**
     * Cancel arrives while the process is genuinely running (after start(),
     * during wait()) — the process DOES get spawned (so real work may have
     * already happened, e.g. a live write script), but the final status must
     * still resolve to Cancelled, not Succeeded/Failed, regardless of the
     * process's own exit code. The fake handler runs at the point Laravel's
     * Process::fake() actually "starts" the process — simulating the
     * cancel request landing in the DB from a separate HTTP request at
     * exactly that moment.
     */
    public function test_cancel_requested_after_start_resolves_to_cancelled_not_succeeded(): void
    {
        $run = $this->makeRun();

        Process::fake(function () use ($run) {
            $run->update(['cancel_requested_at' => now()]);

            return Process::result(output: 'partial work done', exitCode: 0);
        });

        $this->dispatch($run);

        $run->refresh();
        $this->assertSame(ScriptRunStatus::Cancelled, $run->status);
        $this->assertSame("partial work done\n", $run->stdout);
    }

    public function test_cancel_requested_after_start_resolves_to_cancelled_even_on_nonzero_exit(): void
    {
        $run = $this->makeRun();

        Process::fake(function () use ($run) {
            $run->update(['cancel_requested_at' => now()]);

            return Process::result(output: '', errorOutput: 'terminated', exitCode: 143);
        });

        $this->dispatch($run);

        // A killed process's exit code (often 143 for SIGTERM) is NOT what
        // this trusts — cancel_requested_at is. Confirms this doesn't
        // accidentally read as a generic Failed run.
        $this->assertSame(ScriptRunStatus::Cancelled, $run->fresh()->status);
    }
}
