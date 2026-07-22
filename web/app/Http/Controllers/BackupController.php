<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Scripts\BackupChecker;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function index(BackupChecker $backupChecker): View
    {
        $accounts = collect($this->knownAccounts())->map(fn (string $account) => [
            'account' => $account,
            'hasBackup' => $backupChecker->hasBackupFor('ebay', $account),
            'backups' => $this->listBackups($account),
            'outputFiles' => $this->listOutputFiles($account),
        ]);

        return view('backups.index', ['accounts' => $accounts]);
    }

    public function download(string $account, string $backupName, string $filename): BinaryFileResponse
    {
        abort_unless(in_array($account, $this->knownAccounts(), true), 404);

        // basename() on each user-controlled segment strips any '../'
        // traversal attempt, same idiom as RunController::download() — the
        // only path this can ever resolve to is a file directly inside this
        // account's own backups/{backupName}/ directory.
        $safeBackupName = basename($backupName);
        $safeFilename = basename($filename);

        $path = $this->repoRoot()."/marketplaces/ebay/data/{$account}/backups/{$safeBackupName}/{$safeFilename}";
        abort_unless(is_file($path), 404);

        return response()->download($path, $safeFilename);
    }

    public function downloadOutput(string $account, string $filename): BinaryFileResponse
    {
        abort_unless(in_array($account, $this->knownAccounts(), true), 404);

        $safeFilename = basename($filename);
        abort_unless(str_ends_with($safeFilename, '.csv'), 404);

        $path = $this->repoRoot()."/marketplaces/ebay/data/{$account}/output/{$safeFilename}";
        abort_unless(is_file($path), 404);

        return response()->download($path, $safeFilename);
    }

    /**
     * @return list<array{name: string, createdAt: ?string, fileCount: int, totalBytes: int, hasManifest: bool, hasReadme: bool}>
     */
    private function listBackups(string $account): array
    {
        $dir = $this->repoRoot()."/marketplaces/ebay/data/{$account}/backups";
        if (! is_dir($dir)) {
            return [];
        }

        $backups = [];
        foreach (glob("{$dir}/*", GLOB_ONLYDIR) ?: [] as $sub) {
            $manifestPath = "{$sub}/manifest.json";
            $createdAt = null;
            if (is_file($manifestPath)) {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                $createdAt = is_array($manifest) ? ($manifest['created_at'] ?? null) : null;
            }

            $totalBytes = 0;
            $fileCount = 0;
            foreach ($this->allFilesRecursive($sub) as $f) {
                $totalBytes += (int) filesize($f);
                $fileCount++;
            }

            $backups[] = [
                'name' => basename($sub),
                'createdAt' => $createdAt ?? date('c', (int) filemtime($sub)),
                'fileCount' => $fileCount,
                'totalBytes' => $totalBytes,
                'hasManifest' => is_file($manifestPath),
                'hasReadme' => is_file("{$sub}/README.md"),
            ];
        }

        usort($backups, fn (array $a, array $b) => $b['createdAt'] <=> $a['createdAt']);

        return $backups;
    }

    /** @return list<array{name: string, sizeBytes: int, modifiedAt: string}> */
    private function listOutputFiles(string $account): array
    {
        $dir = $this->repoRoot()."/marketplaces/ebay/data/{$account}/output";
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (glob("{$dir}/*.csv") ?: [] as $path) {
            $files[] = [
                'name' => basename($path),
                'sizeBytes' => (int) filesize($path),
                'modifiedAt' => date('c', (int) filemtime($path)),
            ];
        }

        usort($files, fn (array $a, array $b) => $b['modifiedAt'] <=> $a['modifiedAt']);

        return $files;
    }

    /** @return list<string> */
    private function allFilesRecursive(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function repoRoot(): string
    {
        return config('paths.repo_root');
    }

    /**
     * eBay's configured account roster (config/credentials.php) — the same
     * source of truth the credentials UI and dashboard read. Backups are eBay-
     * only today (see BackupChecker's docblock), so this is scoped to eBay.
     *
     * @return list<string>
     */
    private function knownAccounts(): array
    {
        return array_values(config('credentials.ebay.accounts', []));
    }
}
