<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes old CSV/TSV uploads under storage/app/private/script-uploads/. Each run
 * with a file param stores its upload in a per-run uuid directory; nothing ever
 * cleaned them up, so they grew unbounded.
 *
 * Retention is deliberately generous: a Failed/Cancelled run can be resumed,
 * which re-dispatches with the SAME stored upload path — pruning too eagerly
 * would break resume. 14 days is well past when a resume is realistic; older runs
 * still carry their param path in the log, they just need a fresh upload to re-run.
 */
class PruneUploads extends Command
{
    protected $signature = 'uploads:prune {--days=14 : Delete uploads older than this many days}';

    protected $description = 'Delete old script-uploads directories to bound disk growth';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days)->getTimestamp();
        $disk = Storage::disk('local');

        if (! $disk->exists('script-uploads')) {
            $this->info('No uploads to prune.');

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($disk->directories('script-uploads') as $dir) {
            // A run's upload dir holds one file; use the newest mtime in it as the
            // dir's age so a still-referenced-but-old upload is judged on its
            // actual last activity.
            $files = $disk->files($dir);
            $newest = 0;
            foreach ($files as $file) {
                $newest = max($newest, $disk->lastModified($file));
            }

            if ($files === [] || $newest < $cutoff) {
                $disk->deleteDirectory($dir);
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} upload director(ies) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
