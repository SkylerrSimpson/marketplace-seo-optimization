<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunScriptJob;
use App\Models\ScheduledRun;
use App\Models\ScriptRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ScheduledRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_daily_schedule_with_the_right_cron(): void
    {
        $this->actingAs(User::factory()->create())->post(route('scheduled.store'), [
            'script_slug' => 'ebay.export-listings',
            'account' => 'dows',
            'frequency' => 'daily',
            'hour' => 6,
        ])->assertRedirect(route('scheduled.index'));

        $scheduled = ScheduledRun::sole();
        $this->assertSame('ebay.export-listings', $scheduled->script_slug);
        $this->assertSame('dows', $scheduled->params['account']);
        $this->assertSame('0 6 * * *', $scheduled->cron);
        $this->assertTrue($scheduled->enabled);
    }

    public function test_a_write_type_script_cannot_be_scheduled(): void
    {
        $this->actingAs(User::factory()->create())->post(route('scheduled.store'), [
            'script_slug' => 'ebay.apply-aspects', // write type
            'account' => 'dows',
            'frequency' => 'daily',
            'hour' => 6,
        ])->assertSessionHasErrors('script_slug');

        $this->assertSame(0, ScheduledRun::count());
    }

    public function test_an_invalid_account_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())->post(route('scheduled.store'), [
            'script_slug' => 'ebay.export-listings',
            'account' => 'not-a-real-account',
            'frequency' => 'hourly',
        ])->assertStatus(422);

        $this->assertSame(0, ScheduledRun::count());
    }

    public function test_dispatcher_fires_a_due_schedule_and_records_last_run(): void
    {
        Bus::fake();

        $scheduled = ScheduledRun::create([
            'script_slug' => 'ebay.export-listings',
            'params' => ['account' => 'dows'],
            'cron' => '* * * * *', // every minute -> always due
            'enabled' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->artisan('runs:dispatch-scheduled')->assertSuccessful();

        Bus::assertDispatched(RunScriptJob::class);
        $this->assertSame(1, ScriptRun::where('script_slug', 'ebay.export-listings')->count());
        $this->assertNotNull($scheduled->refresh()->last_run_at);
    }

    public function test_dispatcher_skips_a_schedule_whose_owner_is_deactivated(): void
    {
        // Deactivating a user must halt their unattended automation too — a run
        // would otherwise keep injecting a revoked user's credentials.
        Bus::fake();

        $scheduled = ScheduledRun::create([
            'script_slug' => 'ebay.export-listings',
            'params' => ['account' => 'dows'],
            'cron' => '* * * * *',
            'enabled' => true,
            'user_id' => User::factory()->deactivated()->create()->id,
        ]);

        $this->artisan('runs:dispatch-scheduled')->assertSuccessful();

        Bus::assertNotDispatched(RunScriptJob::class);
        $this->assertSame(0, ScriptRun::count());
        // Not marked as run — it's suppressed, not consumed; re-activating the
        // owner resumes it on the next due minute.
        $this->assertNull($scheduled->fresh()->last_run_at);
    }

    public function test_dispatcher_ignores_a_disabled_schedule(): void
    {
        Bus::fake();

        ScheduledRun::create([
            'script_slug' => 'ebay.export-listings',
            'params' => ['account' => 'dows'],
            'cron' => '* * * * *',
            'enabled' => false,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->artisan('runs:dispatch-scheduled')->assertSuccessful();

        Bus::assertNotDispatched(RunScriptJob::class);
        $this->assertSame(0, ScriptRun::count());
    }

    public function test_dispatcher_refuses_to_fire_a_write_script_even_if_one_is_stored(): void
    {
        // Defense in depth: even if a write-type slug reached the table (it can't
        // via the UI), the dispatcher must never turn it into a live run.
        Bus::fake();

        $scheduled = ScheduledRun::create([
            'script_slug' => 'ebay.apply-aspects',
            'params' => ['account' => 'dows', 'live' => true, 'confirm' => 'WRITE'],
            'cron' => '* * * * *',
            'enabled' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->artisan('runs:dispatch-scheduled')->assertSuccessful();

        Bus::assertNotDispatched(RunScriptJob::class);
        $this->assertSame(0, ScriptRun::count());
        // Still advances last_run_at so it doesn't get retried every minute.
        $this->assertNotNull($scheduled->refresh()->last_run_at);
    }
}
