<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\MarketplaceCredential;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MarketplaceCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_round_trip_through_a_fresh_query(): void
    {
        $credential = MarketplaceCredential::factory()->create([
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'fake-app-id-123', 'cert_id' => 'fake-cert-id-456'],
        ]);

        // A fresh query, not the same in-memory instance — proves it actually
        // survived a round trip through the database, not just PHP memory.
        $reloaded = MarketplaceCredential::query()->findOrFail($credential->id);

        $this->assertSame(
            ['app_id' => 'fake-app-id-123', 'cert_id' => 'fake-cert-id-456'],
            $reloaded->credentials
        );
    }

    public function test_credentials_are_actually_encrypted_at_rest(): void
    {
        // This is the test that would catch a dropped `encrypted:` prefix in
        // casts() — a plain 'array' cast would pass every other test here.
        $credential = MarketplaceCredential::factory()->create([
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'fake-app-id-should-not-appear-in-plaintext'],
        ]);

        $rawColumnValue = DB::table('marketplace_credentials')
            ->where('id', $credential->id)
            ->value('credentials');

        $this->assertIsString($rawColumnValue);
        $this->assertStringNotContainsString('fake-app-id-should-not-appear-in-plaintext', $rawColumnValue);
        $this->assertNotSame(
            json_encode(['app_id' => 'fake-app-id-should-not-appear-in-plaintext']),
            $rawColumnValue
        );
    }

    public function test_same_user_cannot_have_two_rows_for_one_marketplace_account_pair(): void
    {
        $user = User::factory()->create();
        MarketplaceCredential::factory()->create(['user_id' => $user->id, 'marketplace' => 'ebay', 'account' => 'dows']);

        $this->expectException(QueryException::class);

        MarketplaceCredential::factory()->create(['user_id' => $user->id, 'marketplace' => 'ebay', 'account' => 'dows']);
    }

    public function test_two_users_can_each_hold_their_own_row_for_the_same_account(): void
    {
        // The whole point of per-user credentials: the unique key is
        // (user_id, marketplace, account), so two teammates each keep their own
        // ebay/dows tokens without colliding.
        MarketplaceCredential::factory()->create(['user_id' => User::factory(), 'marketplace' => 'ebay', 'account' => 'dows']);
        MarketplaceCredential::factory()->create(['user_id' => User::factory(), 'marketplace' => 'ebay', 'account' => 'dows']);

        $this->assertSame(2, MarketplaceCredential::forAccount('ebay', 'dows')->count());
    }

    public function test_for_user_scope_isolates_one_users_rows_from_another(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $credential = MarketplaceCredential::factory()->create(['user_id' => $mine->id, 'marketplace' => 'ebay', 'account' => 'dows']);
        MarketplaceCredential::factory()->create(['user_id' => $theirs->id, 'marketplace' => 'ebay', 'account' => 'dows']);

        $found = MarketplaceCredential::forUser($mine->id)->forAccount('ebay', 'dows')->get();

        $this->assertCount(1, $found);
        $this->assertSame($credential->id, $found->first()->id);
    }

    public function test_for_account_scope_finds_the_matching_row(): void
    {
        $credential = MarketplaceCredential::factory()->create(['marketplace' => 'ebay', 'account' => 'dows']);
        MarketplaceCredential::factory()->create(['marketplace' => 'ebay', 'account' => 'ige']);

        $found = MarketplaceCredential::forAccount('ebay', 'dows')->first();

        $this->assertNotNull($found);
        $this->assertSame($credential->id, $found->id);
    }

    public function test_for_account_scope_returns_null_for_an_unknown_account(): void
    {
        MarketplaceCredential::factory()->create(['marketplace' => 'ebay', 'account' => 'dows']);

        $found = MarketplaceCredential::forAccount('ebay', 'unknown')->first();

        $this->assertNull($found);
    }
}
