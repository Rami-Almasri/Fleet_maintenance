<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-field auto-corrections made during a CMD sync — the detail behind a SyncRun's
 * "correction count". Each row is one stale value the API no longer has that we cleared
 * (e.g. "Contract 88564: in_date cleared"). Read-only audit trail for the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('contract_no')->nullable();
            $table->string('external_id')->nullable();
            $table->string('field');                       // e.g. in_date, in_time
            $table->text('old_value')->nullable();         // what we removed
            $table->string('action')->default('cleared');  // cleared (room for future kinds)
            $table->timestamp('created_at')->nullable();
            $table->index(['sync_run_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_corrections');
    }
};
