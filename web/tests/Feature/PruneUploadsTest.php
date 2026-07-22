<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PruneUploadsTest extends TestCase
{
    public function test_deletes_old_uploads_but_keeps_recent_ones(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $disk->put('script-uploads/old-uuid/data.csv', 'a,b');
        $disk->put('script-uploads/new-uuid/data.csv', 'a,b');

        // Age the "old" upload past the retention window.
        touch($disk->path('script-uploads/old-uuid/data.csv'), now()->subDays(20)->getTimestamp());

        $this->artisan('uploads:prune', ['--days' => 14])->assertSuccessful();

        $this->assertFalse($disk->exists('script-uploads/old-uuid'));
        $this->assertTrue($disk->exists('script-uploads/new-uuid/data.csv'));
    }

    public function test_is_a_no_op_when_there_are_no_uploads(): void
    {
        Storage::fake('local');

        $this->artisan('uploads:prune')->assertSuccessful();
    }
}
