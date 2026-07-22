<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunScriptJob;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use Illuminate\Console\Command;

/**
 * Fails runs that are still marked Running long past the point their process
 * could possibly still be alive — the fingerprint of a queue worker that was
 * killed (deploy, OOM, crash) mid-run. Without this, such a run polls "Running"
 * forever in the UI. Scheduled every few minutes (see routes/console.php).
 *
 * A run literally cannot execute longer than RunScriptJob::TIMEOUT_SECONDS, so a
 * generous buffer on top of that guarantees we never reap a genuinely healthy,
 * still-executing run.
 */
class ReapStuckRuns extends Command
{
    protected $signature = 'runs:reap-stuck';

    protected $description = 'Mark runs stuck in Running (worker died mid-run) as Failed';

    // Beyond the hard process timeout, a wide margin so a healthy run near its
    // ceiling is never touched.
    private const BUFFER_SECONDS = 300;

    public function handle(): int
    {
        $cutoff = now()->subSeconds(RunScriptJob::TIMEOUT_SECONDS + self::BUFFER_SECONDS);

        $stuck = ScriptRun::query()
            ->where('status', ScriptRunStatus::Running)
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stuck as $run) {
            $note = "\n[stuck-run reaper] Marked failed at ".now()->toIso8601String()
                .' — still Running past the process timeout, so the queue worker'
                .' almost certainly died mid-run. Any partial output above is real;'
                .' re-run to continue.';

            $run->update([
                'status' => ScriptRunStatus::Failed,
                'stderr' => ($run->stderr ?? '').$note,
                'finished_at' => now(),
            ]);
        }

        $count = $stuck->count();
        $this->info("Reaped {$count} stuck run(s).");

        return self::SUCCESS;
    }
}
