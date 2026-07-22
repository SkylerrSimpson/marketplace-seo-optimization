<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Runs, schedules, and credentials are private to the user who created them.
     * A 404 (not 403) is deliberate: to someone who doesn't own a row it should
     * be indistinguishable from one that never existed, so this never leaks that
     * another user has a run/schedule with that id.
     */
    protected function abortUnlessOwned(?int $ownerId): void
    {
        abort_unless($ownerId !== null && $ownerId === auth()->id(), 404);
    }

    /**
     * Read-only variant: the owner, or any admin (who can audit all activity —
     * see the /admin area). Used for viewing a run; mutating one (cancel, resume,
     * promote-to-live) stays owner-only via abortUnlessOwned() even for admins.
     */
    protected function abortUnlessOwnedOrAdmin(?int $ownerId): void
    {
        if (auth()->user()?->is_admin === true) {
            return;
        }

        $this->abortUnlessOwned($ownerId);
    }
}
