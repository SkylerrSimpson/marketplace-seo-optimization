<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credentials become per-user: each teammate configures (and can only see or
     * use) their own marketplace tokens, rather than one shared row per account.
     * The uniqueness invariant moves from (marketplace, account) to
     * (user_id, marketplace, account) so two users can each hold their own
     * ebay+dows row without colliding.
     */
    public function up(): void
    {
        Schema::table('marketplace_credentials', function (Blueprint $table) {
            $table->dropUnique(['marketplace', 'account']);
        });

        Schema::table('marketplace_credentials', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'marketplace', 'account']);
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_credentials', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'marketplace', 'account']);
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('marketplace_credentials', function (Blueprint $table) {
            $table->unique(['marketplace', 'account']);
        });
    }
};
