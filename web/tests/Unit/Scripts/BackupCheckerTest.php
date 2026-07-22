<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Scripts\BackupChecker;
use PHPUnit\Framework\TestCase;

final class BackupCheckerTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/backup_checker_test_'.uniqid();
        mkdir($this->tmpRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_true_when_a_non_empty_backup_directory_exists(): void
    {
        $backupDir = "{$this->tmpRoot}/marketplaces/ebay/data/dows/backups/pre_write_2026-01-01";
        mkdir($backupDir, 0775, true);
        file_put_contents("{$backupDir}/manifest.json", '{}');

        $checker = new BackupChecker($this->tmpRoot);

        $this->assertTrue($checker->hasBackupFor('ebay', 'dows'));
    }

    public function test_false_when_backups_directory_does_not_exist(): void
    {
        $checker = new BackupChecker($this->tmpRoot);

        $this->assertFalse($checker->hasBackupFor('ebay', 'dows'));
    }

    public function test_false_when_backups_directory_exists_but_every_subdirectory_is_empty(): void
    {
        mkdir("{$this->tmpRoot}/marketplaces/ebay/data/dows/backups/empty_one", 0775, true);

        $checker = new BackupChecker($this->tmpRoot);

        $this->assertFalse($checker->hasBackupFor('ebay', 'dows'));
    }

    public function test_true_for_a_backup_covering_something_else_entirely(): void
    {
        // The gate is deliberately coarse — a backup for an unrelated purpose
        // still satisfies it, by design (see BackupChecker's own docblock).
        $backupDir = "{$this->tmpRoot}/marketplaces/ebay/data/dows/backups/pre_totally_unrelated_change";
        mkdir($backupDir, 0775, true);
        file_put_contents("{$backupDir}/whatever.txt", 'x');

        $checker = new BackupChecker($this->tmpRoot);

        $this->assertTrue($checker->hasBackupFor('ebay', 'dows'));
    }

    public function test_true_not_gated_for_a_non_ebay_marketplace(): void
    {
        $checker = new BackupChecker($this->tmpRoot);

        $this->assertTrue($checker->hasBackupFor('shopify', null));
    }

    public function test_true_not_gated_for_a_null_or_empty_account(): void
    {
        $checker = new BackupChecker($this->tmpRoot);

        $this->assertTrue($checker->hasBackupFor('ebay', null));
        $this->assertTrue($checker->hasBackupFor('ebay', ''));
    }

    public function test_different_accounts_are_checked_independently(): void
    {
        mkdir("{$this->tmpRoot}/marketplaces/ebay/data/dows/backups/some_backup", 0775, true);
        file_put_contents("{$this->tmpRoot}/marketplaces/ebay/data/dows/backups/some_backup/f.txt", 'x');

        $checker = new BackupChecker($this->tmpRoot);

        $this->assertTrue($checker->hasBackupFor('ebay', 'dows'));
        $this->assertFalse($checker->hasBackupFor('ebay', 'ige'));
    }
}
