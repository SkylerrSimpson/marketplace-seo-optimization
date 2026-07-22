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
        Schema::table('script_runs', function (Blueprint $table) {
            $table->foreignId('preview_run_id')->nullable()->after('user_id')
                ->constrained('script_runs')->nullOnDelete();
            $table->string('confirmation_text')->nullable()->after('finished_at');
            $table->timestamp('confirmed_at')->nullable()->after('confirmation_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('script_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preview_run_id');
            $table->dropColumn(['confirmation_text', 'confirmed_at']);
        });
    }
};
