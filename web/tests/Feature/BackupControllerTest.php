<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests against the real marketplaces/ebay/data/{dows,ige}/backups + output directories
 * (read-only — never mutated here), same convention already used by
 * ScriptController's reference-file download tests ("reference download
 * streams the real file" in ScriptRunFlowTest) rather than faking the
 * filesystem, since this controller only ever lists/streams what's really
 * there.
 */
final class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('backups.index'))->assertRedirect('/login');
    }

    public function test_index_lists_both_accounts_with_their_real_backups(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('backups.index'));

        $response->assertOk();
        $response->assertSee('Dows');
        $response->assertSee('Ige');
        $response->assertSee('pre_template_merge_2026-07-17');
        $response->assertSee('Backed up — writes allowed');
    }

    public function test_download_streams_a_real_file_from_a_backup(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('backups.download', [
                'account' => 'ige',
                'backupName' => 'pre_template_merge_2026-07-17',
                'filename' => 'manifest.csv',
            ]));

        $response->assertOk();
        $response->assertDownload('manifest.csv');
    }

    public function test_download_404s_for_an_account_not_on_the_allowlist(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('backups.download', [
                'account' => 'not-a-real-account',
                'backupName' => 'pre_template_merge_2026-07-17',
                'filename' => 'manifest.csv',
            ]))
            ->assertNotFound();
    }

    public function test_download_404s_for_a_path_traversal_attempt(): void
    {
        // basename() strips the traversal, so this resolves to looking for a
        // literal file named "passwd" inside the backup dir — which doesn't
        // exist, so it 404s rather than leaking anything outside it.
        $this->actingAs(User::factory()->create())
            ->get(route('backups.download', [
                'account' => 'ige',
                'backupName' => 'pre_template_merge_2026-07-17',
                'filename' => '..%2F..%2F..%2Fetc%2Fpasswd',
            ]))
            ->assertNotFound();
    }

    public function test_download_404s_for_a_filename_that_does_not_exist(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('backups.download', [
                'account' => 'ige',
                'backupName' => 'pre_template_merge_2026-07-17',
                'filename' => 'does-not-exist.csv',
            ]))
            ->assertNotFound();
    }

    public function test_download_output_streams_a_real_csv(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('backups.download-output', [
                'account' => 'dows',
                'filename' => 'apply_aspects_run.csv',
            ]));

        $response->assertOk();
        $response->assertDownload('apply_aspects_run.csv');
    }

    public function test_download_output_404s_for_a_non_csv_file(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('backups.download-output', [
                'account' => 'dows',
                'filename' => 'apply_set.json',
            ]))
            ->assertNotFound();
    }
}
