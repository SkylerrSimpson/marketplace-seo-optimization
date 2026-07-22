<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MakeAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_promotes_an_existing_user_to_an_active_admin(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('users:make-admin', ['email' => 'boss@example.com'])->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->is_active);
    }

    public function test_it_verifies_an_unverified_bootstrap_admin_so_they_are_not_locked_out(): void
    {
        // The first prod admin may register when mail isn't wired up yet — the
        // command must clear the verification gate for them.
        $user = User::factory()->unverified()->create(['email' => 'boss@example.com']);

        $this->artisan('users:make-admin', ['email' => 'boss@example.com'])->assertSuccessful();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_it_fails_clearly_for_an_unknown_email(): void
    {
        $this->artisan('users:make-admin', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('No user found')
            ->assertFailed();
    }
}
