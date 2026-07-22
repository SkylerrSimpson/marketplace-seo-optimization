<?php

declare(strict_types=1);

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ScheduledRun extends Model
{
    protected $fillable = [
        'script_slug',
        'params',
        'cron',
        'enabled',
        'last_run_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Due when the current minute matches the cron AND we haven't already fired
     * for this same minute (last_run_at guards the every-minute dispatcher against
     * double-firing if it somehow runs twice in the same minute).
     */
    public function isDue(\DateTimeInterface $now): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (! (new CronExpression($this->cron))->isDue($now)) {
            return false;
        }

        return $this->last_run_at === null
            || $this->last_run_at->format('Y-m-d H:i') !== Carbon::instance($now)->format('Y-m-d H:i');
    }

    public function nextRunAt(): ?\DateTimeInterface
    {
        try {
            return (new CronExpression($this->cron))->getNextRunDate();
        } catch (\Throwable) {
            return null;
        }
    }
}
