<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RunControllerOutputTest extends TestCase
{
    use RefreshDatabase;

    private ?User $actingUser = null;

    /** Memoized signed-in user; runs default to being owned by them so the
     * per-user ownership guard passes. */
    private function actingUser(): User
    {
        return $this->actingUser ??= User::factory()->create();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $run = ScriptRun::factory()->create(['user_id' => User::factory()]);

        $this->get(route('runs.output', $run))->assertRedirect('/login');
    }

    public function test_returns_current_status_and_output_as_json(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Running,
            'stdout' => 'partial output so far',
            'stderr' => '',
            'exit_code' => null,
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.output', $run));

        $response->assertOk();
        $response->assertJson([
            'status' => 'running',
            'stdout' => 'partial output so far',
            'stderr' => '',
            'exit_code' => null,
            'is_terminal' => false,
        ]);
    }

    public function test_is_terminal_true_for_a_succeeded_run(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Succeeded,
            'exit_code' => 0,
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.output', $run));

        $response->assertJson(['status' => 'succeeded', 'is_terminal' => true, 'exit_code' => 0]);
    }

    public function test_is_terminal_true_for_a_failed_run(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Failed,
            'exit_code' => 1,
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.output', $run));

        $response->assertJson(['status' => 'failed', 'is_terminal' => true, 'exit_code' => 1]);
    }

    public function test_unknown_run_id_404s(): void
    {
        $response = $this->actingAs($this->actingUser())->get('/runs/999999/output');

        $response->assertNotFound();
    }

    /**
     * Every long-running script in marketplaces/ebay/scripts/ already prints its own
     * progress as a plain "N/M" tick (audit_shipping_policy.php,
     * check_upc_category_support.php, build_gtin_report.php, etc.) — this
     * reuses that existing convention rather than requiring every CLI
     * script to emit some new structured format.
     */
    public function test_progress_percent_reads_the_last_n_of_m_tick_in_stdout(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Running,
            'stdout' => "checking 344 categories\n  40/344\n  80/344\n  120/344",
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.output', $run));

        $response->assertJson(['progress_percent' => (int) round(120 / 344 * 100)]);
    }

    public function test_progress_percent_is_null_when_stdout_has_no_matching_tick(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Running,
            'stdout' => "starting up...\nconnecting to eBay...",
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.output', $run));

        $response->assertJson(['progress_percent' => null]);
    }

    public function test_progress_percent_is_null_when_stdout_is_empty(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Pending,
            'stdout' => null,
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.output', $run));

        $response->assertJson(['progress_percent' => null]);
    }

    public function test_progress_percent_ignores_a_nonsensical_numerator_greater_than_denominator(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Running,
            'stdout' => 'some unrelated fraction-shaped text: 9/3',
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.output', $run));

        $response->assertJson(['progress_percent' => null]);
    }
}
