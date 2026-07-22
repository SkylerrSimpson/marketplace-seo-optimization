<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunScriptJob;
use App\Models\ScheduledRun;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Scripts\ScriptRegistry;
use App\Scripts\ScriptType;
use Illuminate\Console\Command;

/**
 * Fires any scheduled runs due this minute. Scheduled every minute (see
 * routes/console.php) — the per-entry cron decides which actually run.
 *
 * SAFETY: a scheduled run is never allowed to perform a live write. Automating a
 * live write would bypass the human WRITE/retype confirmation that the whole
 * safety model depends on. Two guards enforce this: only read-type scripts can be
 * scheduled at all (ScheduledRunController), and this dispatcher independently
 * refuses to fire a non-read script and strips any live/confirm params before
 * dispatch. Belt and suspenders on purpose.
 */
class DispatchScheduledRuns extends Command
{
    protected $signature = 'runs:dispatch-scheduled';

    protected $description = 'Dispatch any scheduled runs that are due this minute';

    public function handle(ScriptRegistry $registry): int
    {
        $now = now();
        $fired = 0;

        // Only schedules owned by an active user fire — deactivating a user must
        // stop their unattended automation too, not just their interactive login
        // (the run would otherwise keep injecting a revoked user's credentials).
        $schedules = ScheduledRun::where('enabled', true)
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($schedules as $scheduled) {
            if (! $scheduled->isDue($now)) {
                continue;
            }

            $definition = $registry->findOrNull($scheduled->script_slug);

            // Guard: refuse anything that isn't a read script, and never carry a
            // live write into an unattended run.
            if ($definition === null || $definition->type !== ScriptType::Read) {
                $this->warn("Skipping scheduled run #{$scheduled->id}: {$scheduled->script_slug} is not a schedulable read script.");
                $scheduled->update(['last_run_at' => $now]);

                continue;
            }

            $params = collect($scheduled->params)->except(['live', 'confirm'])->all();

            $run = ScriptRun::create([
                'script_slug' => $scheduled->script_slug,
                'user_id' => $scheduled->user_id,
                'params' => $params,
                'status' => ScriptRunStatus::Pending,
            ]);

            RunScriptJob::dispatch($run);
            $scheduled->update(['last_run_at' => $now]);
            $fired++;
        }

        $this->info("Dispatched {$fired} scheduled run(s).");

        return self::SUCCESS;
    }
}
