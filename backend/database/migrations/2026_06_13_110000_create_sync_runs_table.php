<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks each manual/scheduled data-sync run so the UI can show live progress + ETA
 * and "what changed" (created / updated counts) afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('action');                 // label, e.g. "Contracts (API)"
            $table->string('phase')->nullable();      // current sub-task while running
            $table->string('status')->default('running'); // running | done | failed
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->json('result')->nullable();       // {created, updated, ...}
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
