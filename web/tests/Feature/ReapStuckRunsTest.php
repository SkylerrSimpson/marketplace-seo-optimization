<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunScriptJob;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReapStuckRunsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(ScriptRunStatus $status, ?int $startedSecondsAgo): ScriptRun
    {
        return ScriptRun::create([
            'script_slug' => 'ebay.export-listings',
            'user_id' => User::factory()->create()->id,
            'params' => ['account' => 'dows'],
            'status' => $status,
            'started_at' => $startedSecondsAgo === null ? null : now()->subSeconds($startedSecondsAgo),
        ]);
    }

    public function test_reaps_a_run_running_past_the_timeout(): void
    {
        $stuck = $this->makeRun(ScriptRunStatus::Running, RunScriptJob::TIMEOUT_SECONDS + 600);

        $this->artisan('runs:reap-stuck')->assertSuccessful();

        $stuck->refresh();
        $this->assertSame(ScriptRunStatus::Failed, $stuck->status);
        $this->assertNotNull($stuck->finished_at);
        $this->assertStringContainsString('stuck-run reaper', (string) $stuck->stderr);
    }

    public function test_leaves_a_healthy_running_run_alone(): void
    {
        // Well within the timeout — a real, still-executing run.
        $healthy = $this->makeRun(ScriptRunStatus::Running, 60);

        $this->artisan('runs:reap-stuck')->assertSuccessful();

        $healthy->refresh();
        $this->assertSame(ScriptRunStatus::Running, $healthy->status);
    }

    public function test_ignores_pending_and_terminal_runs(): void
    {
        $pending = $this->makeRun(ScriptRunStatus::Pending, null);
        $succeeded = $this->makeRun(ScriptRunStatus::Succeeded, RunScriptJob::TIMEOUT_SECONDS + 600);

        $this->artisan('runs:reap-stuck')->assertSuccessful();

        $this->assertSame(ScriptRunStatus::Pending, $pending->refresh()->status);
        $this->assertSame(ScriptRunStatus::Succeeded, $succeeded->refresh()->status);
    }
}
