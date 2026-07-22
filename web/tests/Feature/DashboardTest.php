<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketplaceCredential;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use App\Scripts\BackupChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $backupRoot;

    private ?User $actingUser = null;

    /** Memoized signed-in user; runs/credentials default to being owned by them
     * so the dashboard's per-user sections resolve their own data. */
    private function actingUser(): User
    {
        return $this->actingUser ??= User::factory()->create();
    }

    /**
     * Same hermetic-BackupChecker pattern as RunConfirmationFlowTest — a
     * temp dir with a 'dows' backup already present, 'ige' left empty, so
     * the dashboard's backup-coverage section has one of each state to
     * assert on without depending on whatever real backups exist on disk.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->backupRoot = sys_get_temp_dir().'/dashboard_test_'.uniqid();
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get(route('dashboard'))->assertRedirect('/login');
    }

    public function test_home_redirects_to_dashboard(): void
    {
        $this->actingAs($this->actingUser())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_shows_recent_runs_and_credential_summary(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.enrich-listings',
        ]);
        MarketplaceCredential::factory()->create([
            'user_id' => $this->actingUser()->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'fake-app-id'],
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('dashboard'));

        $response->assertOk();
        // The resolved title, not the raw slug — see ScriptRegistry::findOrNull().
        $response->assertSee('Enrich Listings (pull aspects)');
        $response->assertSee('ebay');
        $response->assertSee('dows');
    }

    public function test_dashboard_shows_a_colored_status_pill_and_resolved_title_for_a_since_removed_script(): void
    {
        ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.no-longer-registered',
            'status' => ScriptRunStatus::Succeeded,
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('dashboard'));

        $response->assertOk();
        // findOrNull() falls back to the raw slug rather than 500ing the page.
        $response->assertSee('ebay.no-longer-registered');
        $response->assertSee('bg-green-100', false);
    }

    public function test_dashboard_auto_refreshes_only_while_a_run_is_non_terminal(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Running,
        ]);

        $running = $this->actingAs($this->actingUser())->get(route('dashboard'));
        $running->assertSee('http-equiv="refresh"', false);

        $run->update(['status' => ScriptRunStatus::Succeeded]);

        $done = $this->actingAs($this->actingUser())->get(route('dashboard'));
        $done->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_nav_bar_dropdowns_open_on_hover_and_close_on_mouse_leave(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('dashboard'));

        $response->assertOk();
        // Debounced close (not an immediate open=false on mouseleave) — a bare,
        // instant close reintroduces the classic hover-menu dead-zone bug: crossing
        // the visual gap between trigger and panel (mt-2) or any pixel-level
        // boundary jitter fires mouseleave and slams the panel shut mid-transition.
        $response->assertSee('@mouseenter="clearTimeout(closeTimer); open = true"', false);
        $response->assertSee('@mouseleave="closeTimer = setTimeout(() => open = false, 200)"', false);
    }

    public function test_dashboard_shows_backup_coverage_for_every_known_ebay_account(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Backed up — writes allowed');
        $response->assertSee('No backup — writes blocked');
        $response->assertSee('ebay / dows');
        $response->assertSee('ebay / ige');
    }

    public function test_dashboard_backup_coverage_links_to_the_backups_page(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('href="'.route('backups.index').'"', false);
    }
}
