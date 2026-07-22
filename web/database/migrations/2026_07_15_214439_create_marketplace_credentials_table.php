<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketplace_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace'); // 'ebay', 'shopify', 'walmart', 'amazon'
            $table->string('account');     // 'dows', 'ige', ...
            $table->text('credentials');   // whole-bag JSON, encrypted at rest — see MarketplaceCredential::casts()
            $table->timestamps();

            // DB-level invariant, not just an app-level convention: makes "two rows
            // for ebay+dows" impossible regardless of which code path writes here
            // (seeder, tinker, a future controller) — see plan for the reasoning.
            $table->unique(['marketplace', 'account']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_credentials');
    }
};
