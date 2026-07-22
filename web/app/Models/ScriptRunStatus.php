<?php

declare(strict_types=1);

namespace App\Models;

enum ScriptRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Single source of truth for "this run is done, nothing further will
     * happen to it" — used everywhere a page decides whether to keep
     * polling/auto-refreshing (script page's live panel, a single run's own
     * page, the dashboard/runs list) so a cancelled run stops them exactly
     * like a succeeded or failed one does.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }
}
