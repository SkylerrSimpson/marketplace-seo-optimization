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
            // The real OS process ID of the running CLI script, captured the
            // moment RunScriptJob starts it — the only way a separate HTTP
            // request (cancel) can signal a process running in a different
            // PHP process (the queue worker).
            $table->integer('pid')->nullable()->after('exit_code');
            // Set the moment a cancel is requested, before any signal is
            // sent — RunScriptJob trusts THIS, not the raw exit code, to
            // decide whether a run that stopped was cancelled vs. genuinely
            // failed (a killed process's exit code isn't a reliable signal
            // on its own).
            $table->timestamp('cancel_requested_at')->nullable()->after('finished_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('script_runs', function (Blueprint $table) {
            $table->dropColumn(['pid', 'cancel_requested_at']);
        });
    }
};
