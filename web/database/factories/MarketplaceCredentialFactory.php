<?php

namespace Database\Factories;

use App\Models\MarketplaceCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceCredential>
 */
class MarketplaceCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'marketplace' => fake()->randomElement(['ebay', 'shopify', 'walmart', 'amazon']),
            'account' => fake()->unique()->lexify('acct-????'),
            // Obviously-fake, prefixed so nobody mistakes these for real secrets —
            // CONTRIBUTING.md: "use fake tokens, never real secrets, even in test
            // fixtures." Shape matches EbayClient's real credential keys (checked
            // directly against ../ebay/scripts/lib/EbayClient.php), not guessed.
            'credentials' => [
                'app_id' => 'fake-app-id-'.fake()->uuid(),
                'cert_id' => 'fake-cert-id-'.fake()->uuid(),
                'dev_id' => 'fake-dev-id-'.fake()->uuid(),
                'ru_name' => 'fake-ru-name-'.fake()->word(),
                'refresh_token' => 'fake-refresh-token-'.fake()->sha256(),
            ],
        ];
    }
}
