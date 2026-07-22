<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ScheduledRun;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Focused tests for the due/next-run logic (no DB — models are built in memory).
 * isDue() is the gate the every-minute dispatcher trusts, so its edge cases
 * (disabled, cron miss, and the same-minute double-fire guard) are worth pinning
 * directly rather than only through the dispatcher command.
 */
final class ScheduledRunTest extends TestCase
{
    private function schedule(string $cron, bool $enabled = true, ?string $lastRunAt = null): ScheduledRun
    {
        $run = new ScheduledRun;
        $run->cron = $cron;
        $run->enabled = $enabled;
        $run->last_run_at = $lastRunAt === null ? null : Carbon::parse($lastRunAt);

        return $run;
    }

    public function test_due_when_the_cron_matches_and_it_has_never_run(): void
    {
        $this->assertTrue(
            $this->schedule('0 6 * * *')->isDue(Carbon::parse('2026-07-22 06:00:30'))
        );
    }

    public function test_not_due_when_disabled(): void
    {
        $this->assertFalse(
            $this->schedule('0 6 * * *', enabled: false)->isDue(Carbon::parse('2026-07-22 06:00:00'))
        );
    }

    public function test_not_due_when_the_cron_does_not_match_this_minute(): void
    {
        $this->assertFalse(
            $this->schedule('0 7 * * *')->isDue(Carbon::parse('2026-07-22 06:00:00'))
        );
    }

    public function test_not_due_a_second_time_in_the_same_minute(): void
    {
        // The double-fire guard: already fired at 06:00, dispatcher runs again at
        // 06:00:59 — must not fire twice for the same minute.
        $this->assertFalse(
            $this->schedule('0 6 * * *', lastRunAt: '2026-07-22 06:00:05')
                ->isDue(Carbon::parse('2026-07-22 06:00:59'))
        );
    }

    public function test_due_again_on_the_next_matching_minute(): void
    {
        // Every-minute cron: fired at 06:00, and the next minute is a fresh due.
        $this->assertTrue(
            $this->schedule('* * * * *', lastRunAt: '2026-07-22 06:00:05')
                ->isDue(Carbon::parse('2026-07-22 06:01:00'))
        );
    }

    public function test_next_run_at_is_null_for_a_malformed_cron(): void
    {
        $this->assertNull($this->schedule('not a cron')->nextRunAt());
    }
}
