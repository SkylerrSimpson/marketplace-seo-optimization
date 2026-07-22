<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunScriptJob;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use App\Scripts\ScriptRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RunCancelResumeTest extends TestCase
{
    use RefreshDatabase;

    private ?User $actingUser = null;

    /** Memoized signed-in user; runs default to being owned by them so the
     * per-user ownership guard passes. */
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

    // --- cancel --------------------------------------------------------------

    public function test_cancel_guest_redirected_to_login(): void
    {
        $run = $this->makeRun(['status' => ScriptRunStatus::Running]);

        $this->post(route('runs.cancel', $run))->assertRedirect('/login');
    }

    #[DataProvider('terminalStatuses')]
    public function test_cancel_404s_on_an_already_terminal_run(ScriptRunStatus $status): void
    {
        $run = $this->makeRun(['status' => $status]);

        $this->actingAs($this->actingUser())
            ->postJson(route('runs.cancel', $run))
            ->assertNotFound();

        $this->assertNull($run->fresh()->cancel_requested_at);
    }

    public static function terminalStatuses(): array
    {
        return [
            'succeeded' => [ScriptRunStatus::Succeeded],
            'failed' => [ScriptRunStatus::Failed],
            'cancelled' => [ScriptRunStatus::Cancelled],
        ];
    }

    public function test_cancel_on_a_pending_run_sets_cancel_requested_at_and_returns_json(): void
    {
        $run = $this->makeRun(['status' => ScriptRunStatus::Pending]);

        $response = $this->actingAs($this->actingUser())
            ->postJson(route('runs.cancel', $run));

        $response->assertOk();
        $response->assertExactJson(['cancelled' => true]);
        $this->assertNotNull($run->fresh()->cancel_requested_at);
    }

    public function test_cancel_on_a_running_run_sets_cancel_requested_at(): void
    {
        // No real pid — signalProcess() is best-effort and must not blow up
        // when there's nothing real to signal (the process may have already
        // exited naturally, or (as here) never had one in this fixture).
        $run = $this->makeRun(['status' => ScriptRunStatus::Running, 'pid' => null]);

        $response = $this->actingAs($this->actingUser())
            ->postJson(route('runs.cancel', $run));

        $response->assertOk();
        $this->assertNotNull($run->fresh()->cancel_requested_at);
    }

    // --- resume ----------------------------------------------------------------

    public function test_resume_guest_redirected_to_login(): void
    {
        $run = $this->makeRun(['status' => ScriptRunStatus::Failed]);

        $this->post(route('runs.resume', $run))->assertRedirect('/login');
    }

    #[DataProvider('nonResumableStatuses')]
    public function test_resume_404s_on_a_run_that_is_not_failed_or_cancelled(ScriptRunStatus $status): void
    {
        Bus::fake();
        $run = $this->makeRun(['status' => $status]);

        $this->actingAs($this->actingUser())
            ->postJson(route('runs.resume', $run))
            ->assertNotFound();

        Bus::assertNotDispatched(RunScriptJob::class);
    }

    public static function nonResumableStatuses(): array
    {
        return [
            'succeeded' => [ScriptRunStatus::Succeeded],
            'pending' => [ScriptRunStatus::Pending],
            'running' => [ScriptRunStatus::Running],
        ];
    }

    public function test_resume_on_a_failed_run_creates_a_new_run_with_the_same_params(): void
    {
        Bus::fake();
        $run = $this->makeRun(['status' => ScriptRunStatus::Failed, 'params' => ['account' => 'dows', 'limit' => '5']]);
        $user = $this->actingUser();

        $response = $this->actingAs($user)->postJson(route('runs.resume', $run));

        $response->assertCreated();
        $newRunId = $response->json('run_id');
        $this->assertNotSame($run->id, $newRunId);

        $newRun = ScriptRun::find($newRunId);
        $this->assertSame('ebay.enrich-listings', $newRun->script_slug);
        $this->assertSame(['account' => 'dows', 'limit' => '5'], $newRun->params);
        $this->assertSame(ScriptRunStatus::Pending, $newRun->status);
        $this->assertSame($user->id, $newRun->user_id);
        Bus::assertDispatched(RunScriptJob::class, fn ($job) => $job->scriptRun->is($newRun));
    }

    public function test_resume_on_a_cancelled_run_creates_a_new_run(): void
    {
        Bus::fake();
        $run = $this->makeRun(['status' => ScriptRunStatus::Cancelled]);

        $this->actingAs($this->actingUser())
            ->postJson(route('runs.resume', $run))
            ->assertCreated();

        $this->assertSame(2, ScriptRun::count());
    }

    /**
     * Only a script with a registered `resume` bool param gets it forced to
     * true — everything else just gets an identical re-run. Uses a fixture
     * registry (same pattern as ScriptRegistryTest) since no real registered
     * script has a `resume` param yet (only the standalone
     * audit_shipping_policy.php, not registered in DOWScripts today).
     */
    public function test_resume_forces_the_resume_flag_only_when_the_script_has_that_param(): void
    {
        Bus::fake();

        $configDir = sys_get_temp_dir().'/dowscripts_resume_test_'.uniqid();
        mkdir($configDir);
        $entries = [
            [
                'slug' => 'fixture.with-resume',
                'category' => 'testing',
                'title' => 'With Resume',
                'description' => 'Has a resume param.',
                'type' => 'read',
                'cli_path' => 'fixture/with_resume.php',
                'interpreter' => 'php8.2',
                'params' => [
                    ['name' => 'account', 'flag' => '--account', 'type' => 'enum', 'required' => true, 'options' => ['dows', 'ige']],
                    ['name' => 'resume', 'flag' => '--resume', 'type' => 'bool', 'required' => false],
                ],
            ],
            [
                'slug' => 'fixture.without-resume',
                'category' => 'testing',
                'title' => 'Without Resume',
                'description' => 'No resume param.',
                'type' => 'read',
                'cli_path' => 'fixture/without_resume.php',
                'interpreter' => 'php8.2',
                'params' => [
                    ['name' => 'account', 'flag' => '--account', 'type' => 'enum', 'required' => true, 'options' => ['dows', 'ige']],
                ],
            ],
        ];
        file_put_contents("{$configDir}/fixture.php", '<?php return '.var_export($entries, true).';');

        $this->app->instance(ScriptRegistry::class, new ScriptRegistry($configDir));

        $withResume = $this->makeRun(['script_slug' => 'fixture.with-resume', 'status' => ScriptRunStatus::Failed, 'params' => ['account' => 'dows']]);
        $withoutResume = $this->makeRun(['script_slug' => 'fixture.without-resume', 'status' => ScriptRunStatus::Failed, 'params' => ['account' => 'dows']]);

        $user = $this->actingUser();

        $r1 = $this->actingAs($user)->postJson(route('runs.resume', $withResume));
        $r2 = $this->actingAs($user)->postJson(route('runs.resume', $withoutResume));

        $this->assertTrue(ScriptRun::find($r1->json('run_id'))->params['resume']);
        $this->assertArrayNotHasKey('resume', ScriptRun::find($r2->json('run_id'))->params);

        unlink("{$configDir}/fixture.php");
        rmdir($configDir);
    }
}
