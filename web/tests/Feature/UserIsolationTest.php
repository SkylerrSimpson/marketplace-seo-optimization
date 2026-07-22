<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketplaceCredential;
use App\Models\ScheduledRun;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The deny-path guarantees for per-user isolation: a second signed-in user
 * ("intruder") is walled off from everything the owner created — runs,
 * credentials, and schedules. Every owner-aligned happy path is already
 * covered by the per-feature tests; this file exists to prove the OTHER user
 * can't reach in. A 404 (never 403) is asserted throughout so another user's
 * row id isn't even confirmable.
 */
final class UserIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
    }

    private function ownerRun(array $overrides = []): ScriptRun
    {
        return ScriptRun::factory()->create(array_merge([
            'user_id' => $this->owner->id,
            'script_slug' => 'ebay.enrich-listings',
            'params' => ['account' => 'dows'],
            'status' => ScriptRunStatus::Running,
        ], $overrides));
    }

    // --- runs ----------------------------------------------------------------

    public function test_intruder_cannot_view_anothers_run(): void
    {
        $run = $this->ownerRun();

        $this->actingAs($this->intruder)->get(route('runs.show', $run))->assertNotFound();
        $this->actingAs($this->intruder)->get(route('runs.output', $run))->assertNotFound();
    }

    public function test_intruder_cannot_cancel_anothers_run(): void
    {
        $run = $this->ownerRun();

        $this->actingAs($this->intruder)->postJson(route('runs.cancel', $run))->assertNotFound();
        $this->assertNull($run->fresh()->cancel_requested_at);
    }

    public function test_intruder_cannot_resume_anothers_run(): void
    {
        $run = $this->ownerRun(['status' => ScriptRunStatus::Failed]);

        $this->actingAs($this->intruder)->postJson(route('runs.resume', $run))->assertNotFound();
        $this->assertSame(1, ScriptRun::count());
    }

    public function test_intruder_cannot_download_anothers_run_output(): void
    {
        Storage::fake('local');
        $run = $this->ownerRun(['status' => ScriptRunStatus::Succeeded]);
        Storage::disk('local')->put($run->storageDirectory().'/out.csv', "a,b\n1,2\n");

        $this->actingAs($this->intruder)
            ->get(route('runs.download', ['run' => $run, 'filename' => 'out.csv']))
            ->assertNotFound();
    }

    public function test_runs_index_shows_only_the_signed_in_users_runs(): void
    {
        $run = $this->ownerRun(['script_slug' => 'ebay.export-listings', 'status' => ScriptRunStatus::Succeeded]);

        $response = $this->actingAs($this->intruder)->get(route('runs.index'));

        $response->assertOk();
        // The owner's run row links to its own detail page — that link must not
        // appear for the intruder (the script title alone is too broad: it also
        // shows up in filter controls).
        $response->assertDontSee(route('runs.show', $run), false);
    }

    public function test_intruder_cannot_promote_anothers_verified_preview_to_live(): void
    {
        // Ownership is checked before eligibility/backup gates, so this is a flat
        // 404 regardless of how promotable the run itself is.
        $preview = $this->ownerRun([
            'script_slug' => 'ebay.apply-aspects',
            'status' => ScriptRunStatus::Succeeded,
            'params' => ['account' => 'dows', 'item' => '123456789012', 'verify' => true],
        ]);

        $this->actingAs($this->intruder)->get(route('runs.confirm.create', $preview))->assertNotFound();
        $this->actingAs($this->intruder)
            ->post(route('runs.confirm.store', $preview), ['confirmation' => '123456789012'])
            ->assertNotFound();
    }

    // --- credentials ---------------------------------------------------------

    public function test_intruder_never_sees_anothers_stored_credential(): void
    {
        MarketplaceCredential::factory()->create([
            'user_id' => $this->owner->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'owner-secret-app-id'],
        ]);

        // The intruder's own edit page for the same account shows it as unset —
        // never the owner's value, nor even the "Set" badge that would leak that
        // someone has configured it.
        $response = $this->actingAs($this->intruder)
            ->get(route('credentials.edit', ['marketplace' => 'ebay', 'account' => 'dows']));

        $response->assertOk();
        $response->assertDontSee('owner-secret-app-id');
        // The masked "leave blank to keep" placeholder only renders for a field
        // this user has set — the intruder must see none of it (every field reads
        // "(not set)" for them), so the owner having configured it doesn't leak.
        $response->assertDontSee('leave blank to keep', false);
        $response->assertSee('(not set)', false);
    }

    public function test_intruder_update_creates_its_own_row_and_leaves_the_owners_untouched(): void
    {
        MarketplaceCredential::factory()->create([
            'user_id' => $this->owner->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'owner-app-id'],
        ]);

        $this->actingAs($this->intruder)->put(
            route('credentials.update', ['marketplace' => 'ebay', 'account' => 'dows']),
            ['app_id' => 'intruder-app-id'],
        )->assertRedirect(route('credentials.index'));

        $this->assertSame(2, MarketplaceCredential::forAccount('ebay', 'dows')->count());
        $this->assertSame('owner-app-id', MarketplaceCredential::forUser($this->owner->id)
            ->forAccount('ebay', 'dows')->first()->credentials['app_id']);
        $this->assertSame('intruder-app-id', MarketplaceCredential::forUser($this->intruder->id)
            ->forAccount('ebay', 'dows')->first()->credentials['app_id']);
    }

    public function test_intruder_cannot_delete_anothers_credential(): void
    {
        MarketplaceCredential::factory()->create([
            'user_id' => $this->owner->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
        ]);

        // The intruder has no such row of their own, so destroy 404s and the
        // owner's row is left entirely alone.
        $this->actingAs($this->intruder)
            ->delete(route('credentials.destroy', ['marketplace' => 'ebay', 'account' => 'dows']))
            ->assertNotFound();

        $this->assertSame(1, MarketplaceCredential::forUser($this->owner->id)->forAccount('ebay', 'dows')->count());
    }

    // --- scheduled runs ------------------------------------------------------

    private function ownerSchedule(): ScheduledRun
    {
        return ScheduledRun::create([
            'script_slug' => 'ebay.export-listings',
            'params' => ['account' => 'dows'],
            'cron' => '0 6 * * *',
            'enabled' => true,
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_scheduled_index_shows_only_the_signed_in_users_schedules(): void
    {
        $schedule = $this->ownerSchedule();

        $response = $this->actingAs($this->intruder)->get(route('scheduled.index'));

        $response->assertOk();
        // The script picker lists every schedulable script's title, so match on
        // the owner row's own delete action instead — unique to that schedule.
        $response->assertDontSee(route('scheduled.destroy', $schedule), false);
    }

    public function test_intruder_cannot_toggle_anothers_schedule(): void
    {
        $schedule = $this->ownerSchedule();

        $this->actingAs($this->intruder)
            ->patch(route('scheduled.toggle', $schedule))
            ->assertNotFound();

        $this->assertTrue($schedule->fresh()->enabled);
    }

    public function test_intruder_cannot_delete_anothers_schedule(): void
    {
        $schedule = $this->ownerSchedule();

        $this->actingAs($this->intruder)
            ->delete(route('scheduled.destroy', $schedule))
            ->assertNotFound();

        $this->assertDatabaseHas('scheduled_runs', ['id' => $schedule->id]);
    }
}
