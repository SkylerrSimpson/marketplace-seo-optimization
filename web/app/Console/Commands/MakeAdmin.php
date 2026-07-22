<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bootstraps (or repairs) an admin from the CLI — the only way to create the
 * FIRST admin in production, since there's no seeded admin there and the /admin
 * UI can only be reached by an existing admin. The person registers themselves
 * first, then an operator with server access runs this against their email.
 *
 * Also ensures the account is active and email-verified, so a genuine bootstrap
 * admin isn't locked behind the verification gate if mail isn't wired up yet.
 */
class MakeAdmin extends Command
{
    protected $signature = 'users:make-admin {email : Email of an already-registered user to promote}';

    protected $description = 'Promote an existing user to admin (also marks them active + email-verified)';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email \"{$email}\". Have them register first, then re-run this.");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true, 'is_active' => true])->save();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $this->info("{$user->email} is now an admin (active + verified).");

        return self::SUCCESS;
    }
}
