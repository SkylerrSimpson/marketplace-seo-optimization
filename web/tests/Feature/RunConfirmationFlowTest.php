<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunScriptJob;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use App\Scripts\BackupChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class RunConfirmationFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $backupRoot;

    private ?User $actingUser = null;

    /** Memoized signed-in user; preview runs default to being owned by them so
     * the per-user ownership guard passes. */
    private function actingUser(): User
    {
        return $this->actingUser ??= User::factory()->create();
    }

    /**
     * A fake BackupChecker pointed at a controlled temp dir is bound for
     * every test in this class, with a 'dows' backup already present — so
     * these tests are hermetic (never depend on whatever real backups
     * happen to exist on disk) and the pre-existing eligibility tests below
     * don't need to know the new gate exists at all. The two tests that
     * actually exercise the gate rebind a BackupChecker pointed at an empty
     * dir instead.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->backupRoot = sys_get_temp_dir().'/run_confirmation_test_'.uniqid();
        mkdir("{$this->backupRoot}/marketplaces/ebay/data/dows/backups/existing_backup", 0775, true);
        file_put_contents("{$this->backupRoot}/marketplaces/ebay/data/dows/backups/existing_backup/f.txt", 'x');

        $this->app->instance(BackupChecker::class, new BackupChecker($this->backupRoot));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->backupRoot);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function verifiedWritePreview(array $overrides = []): ScriptRun
    {
        return ScriptRun::factory()->create(array_merge([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.apply-aspects',
            'status' => ScriptRunStatus::Succeeded,
            'params' => ['account' => 'dows', 'item' => '123456789012', 'verify' => true],
        ], $overrides));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $run = $this->verifiedWritePreview();

        $this->get(route('runs.confirm.create', $run))->assertRedirect('/login');
        $this->post(route('runs.confirm.store', $run))->assertRedirect('/login');
    }

    public function test_create_403s_for_a_read_type_script(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.enrich-listings',
            'status' => ScriptRunStatus::Succeeded,
            'params' => ['account' => 'dows', 'verify' => true],
        ]);

        $this->actingAs($this->actingUser())
            ->get(route('runs.confirm.create', $run))
            ->assertForbidden();
    }

    public function test_create_403s_for_a_run_that_has_not_finished(): void
    {
        $run = $this->verifiedWritePreview(['status' => ScriptRunStatus::Running]);

        $this->actingAs($this->actingUser())
            ->get(route('runs.confirm.create', $run))
            ->assertForbidden();
    }

    public function test_create_403s_when_the_preview_was_not_run_with_verify(): void
    {
        $run = $this->verifiedWritePreview([
            'params' => ['account' => 'dows', 'item' => '123456789012'],
        ]);

        $this->actingAs($this->actingUser())
            ->get(route('runs.confirm.create', $run))
            ->assertForbidden();
    }

    public function test_create_shows_the_retype_prompt_for_single_mode(): void
    {
        $run = $this->verifiedWritePreview();

        $response = $this->actingAs($this->actingUser())->get(route('runs.confirm.create', $run));

        $response->assertOk();
        $response->assertSee('123456789012');
    }

    public function test_create_shows_the_write_prompt_for_bulk_mode(): void
    {
        $run = $this->verifiedWritePreview([
            'params' => ['account' => 'dows', 'items' => '111,222', 'verify' => true],
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.confirm.create', $run));

        $response->assertOk();
        $response->assertSee('Type WRITE', false);
    }

    public function test_store_rejects_a_wrong_retyped_item_id(): void
    {
        Bus::fake();
        $run = $this->verifiedWritePreview();

        $this->actingAs($this->actingUser())
            ->post(route('runs.confirm.store', $run), ['confirmation' => '999999999999'])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(1, ScriptRun::count());
        Bus::assertNotDispatched(RunScriptJob::class);
    }

    public function test_store_with_correct_retyped_id_creates_the_live_run(): void
    {
        Bus::fake();
        $run = $this->verifiedWritePreview();
        $user = $this->actingUser();

        $response = $this->actingAs($user)
            ->post(route('runs.confirm.store', $run), ['confirmation' => '123456789012']);

        $liveRun = ScriptRun::where('id', '!=', $run->id)->first();

        $this->assertNotNull($liveRun);
        $this->assertSame($run->id, $liveRun->preview_run_id);
        $this->assertTrue($liveRun->params['live']);
        $this->assertArrayNotHasKey('confirm', $liveRun->params);
        $this->assertSame('123456789012', $liveRun->confirmation_text);
        $this->assertNotNull($liveRun->confirmed_at);
        $this->assertSame($user->id, $liveRun->user_id);
        $response->assertRedirect(route('runs.show', $liveRun));

        Bus::assertDispatched(RunScriptJob::class, fn ($job) => $job->scriptRun->is($liveRun));
    }

    public function test_store_rejects_lowercase_write_for_bulk(): void
    {
        Bus::fake();
        $run = $this->verifiedWritePreview([
            'params' => ['account' => 'dows', 'items' => '111,222', 'verify' => true],
        ]);

        $this->actingAs($this->actingUser())
            ->post(route('runs.confirm.store', $run), ['confirmation' => 'write'])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(1, ScriptRun::count());
    }

    public function test_store_with_exact_write_creates_the_bulk_live_run(): void
    {
        Bus::fake();
        $run = $this->verifiedWritePreview([
            'params' => ['account' => 'dows', 'items' => '111,222', 'verify' => true],
        ]);

        $this->actingAs($this->actingUser())
            ->post(route('runs.confirm.store', $run), ['confirmation' => 'WRITE']);

        $liveRun = ScriptRun::where('id', '!=', $run->id)->first();

        $this->assertNotNull($liveRun);
        $this->assertTrue($liveRun->params['live']);
        $this->assertSame('WRITE', $liveRun->params['confirm']);
        $this->assertSame('WRITE', $liveRun->confirmation_text);
    }

    public function test_create_redirects_to_backups_when_no_backup_exists_for_the_account(): void
    {
        $emptyRoot = sys_get_temp_dir().'/run_confirmation_test_empty_'.uniqid();
        mkdir($emptyRoot, 0775, true);
        $this->app->instance(BackupChecker::class, new BackupChecker($emptyRoot));

        $run = $this->verifiedWritePreview();

        // Not a dead-end 403 anymore — a real user without a backup is sent to
        // /backups with an actionable message, the gate still blocking the write.
        $this->actingAs($this->actingUser())
            ->get(route('runs.confirm.create', $run))
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('backupGate');

        $this->removeDir($emptyRoot);
    }

    public function test_store_redirects_to_backups_and_writes_nothing_when_no_backup_exists(): void
    {
        Bus::fake();
        $emptyRoot = sys_get_temp_dir().'/run_confirmation_test_empty_'.uniqid();
        mkdir($emptyRoot, 0775, true);
        $this->app->instance(BackupChecker::class, new BackupChecker($emptyRoot));

        $run = $this->verifiedWritePreview();

        $this->actingAs($this->actingUser())
            ->post(route('runs.confirm.store', $run), ['confirmation' => '123456789012'])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('backupGate');

        // The gate is preserved: no live run row created, no job dispatched.
        $this->assertSame(1, ScriptRun::count());
        Bus::assertNotDispatched(RunScriptJob::class);

        $this->removeDir($emptyRoot);
    }

    public function test_ineligible_run_still_hard_403s(): void
    {
        // The eligibility guard (not a normal user state) stays a hard 403.
        $run = ScriptRun::create([
            'script_slug' => 'ebay.apply-aspects',
            'user_id' => $this->actingUser()->id,
            'params' => ['account' => 'dows', 'item' => '123456789012', 'verify' => true],
            'status' => ScriptRunStatus::Pending, // not a succeeded verify preview
        ]);

        $this->actingAs($this->actingUser())
            ->get(route('runs.confirm.create', $run))
            ->assertForbidden();
    }
}
