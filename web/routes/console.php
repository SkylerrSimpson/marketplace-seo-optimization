<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recover runs orphaned by a dead queue worker so they don't poll "Running"
// forever. Cheap DB-only sweep; safe to run often. Requires the scheduler to be
// running in production: `php artisan schedule:work` (or a cron entry calling
// `schedule:run` every minute) — see DEPLOYMENT.md.
Schedule::command('runs:reap-stuck')->everyFiveMinutes()->withoutOverlapping();

// Bound disk growth from CSV/TSV uploads. Daily is plenty; retention (14 days)
// leaves a wide window for resuming a file-param run.
Schedule::command('uploads:prune')->dailyAt('03:00')->withoutOverlapping();

// Fire user-defined scheduled runs that are due. Every minute so per-entry cron
// resolution is honored; the command itself is cheap when nothing is due.
Schedule::command('runs:dispatch-scheduled')->everyMinute()->withoutOverlapping();
