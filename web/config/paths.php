<?php

declare(strict_types=1);

// This Laravel app lives in a subdirectory (web/) of the larger marketplace
// repo whose CLI scripts it wraps. Nearly everything here — spawning a script,
// copying its output, listing backups — is relative to that repo root, one level
// above base_path(). Computed in exactly one place so the "../" relationship isn't
// re-derived (and silently re-gettable-wrong) at a dozen call sites.
return [
    'repo_root' => dirname(base_path()),
];
