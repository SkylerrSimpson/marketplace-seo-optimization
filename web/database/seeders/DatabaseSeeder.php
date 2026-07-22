<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // firstOrCreate, not create — db:seed should be safely re-runnable without
        // tripping the unique email constraint on an already-seeded dev database.
        // factory()->raw() supplies the password hash / remember_token defaults
        // firstOrCreate's own second argument doesn't generate on its own.
        // The bootstrap admin — the first account that can reach /admin to create
        // the rest of the team. Promote a real person and rotate this one out
        // before launch.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->raw(['name' => 'Test User', 'email' => 'test@example.com', 'is_admin' => true]),
        );
    }
}
