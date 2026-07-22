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
        Schema::create('script_runs', function (Blueprint $table) {
            $table->id();
            $table->string('script_slug');               // e.g. 'ebay.enrich-listings'
            $table->foreignId('user_id')->constrained();  // who queued it
            $table->json('params');                       // submitted param values, e.g. {"account":"dows"}
            $table->string('status')->default('pending');
            // null = job/infra failure, process never ran; int = the process's real exit code
            $table->integer('exit_code')->nullable();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('script_runs');
    }
};
