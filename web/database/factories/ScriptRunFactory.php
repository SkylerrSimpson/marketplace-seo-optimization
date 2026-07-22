<?php

namespace Database\Factories;

use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScriptRun>
 */
class ScriptRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'script_slug' => 'ebay.enrich-listings',
            'user_id' => User::factory(),
            'params' => ['account' => 'dows'],
            'status' => ScriptRunStatus::Pending,
        ];
    }
}
