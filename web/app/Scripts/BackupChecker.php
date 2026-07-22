<?php

declare(strict_types=1);

namespace App\Scripts;

final class BackupChecker
{
    public function __construct(private readonly ?string $repoRoot = null) {}

    /**
     * Coarse, account-level check: does ANY non-empty backup directory exist
     * for this account, regardless of what it actually contains? Not scoped to
     * the specific write about to happen — a deliberate simplification (see
     * the plan's "Explicitly out of scope" section) so this ships now rather
     * than waiting on a backup-scope-tagging convention that doesn't exist yet.
     *
     * Only eBay is gated today — Shopify has no per-account concept and no
     * backups/ directory anywhere (confirmed by direct repo search), so
     * gating it would either block every Shopify write immediately with no
     * way to unblock, or require building a Shopify-specific backup fetcher
     * blind. Returns true (not gated) for any other marketplace or a null/
     * empty account so callers never need their own marketplace special-case.
     */
    public function hasBackupFor(string $marketplace, ?string $account): bool
    {
        if ($marketplace !== 'ebay' || $account === null || $account === '') {
            return true;
        }

        $dir = $this->backupsDir($account);
        if (! is_dir($dir)) {
            return false;
        }

        foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if ((new \FilesystemIterator($sub))->valid()) {
                return true;
            }
        }

        return false;
    }

    public function backupsDir(string $account): string
    {
        return $this->repoRoot().'/marketplaces/ebay/data/'.$account.'/backups';
    }

    private function repoRoot(): string
    {
        return $this->repoRoot ?? config('paths.repo_root');
    }
}
