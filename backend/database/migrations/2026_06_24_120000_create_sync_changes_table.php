<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-record change feed for a CMD sync — the "what's new / what changed" detail behind
 * a SyncRun. Two row kinds:
 *   - operation=insert : a brand-new contract. `snapshot` holds the full record we wrote.
 *   - operation=update : an existing contract whose fields the sync actually rewrote.
 *                        `changes` holds ONLY the fields that changed: {field:{old,new}}.
 *
 * Both are built in-memory during flush() (getDirty()/getOriginal() — no extra queries) and
 * bulk-inserted once at the end of the run, so they add no per-record sync cost. Read-only
 * audit trail powering the Sync Audit "news feed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('contract_no')->nullable();
            $table->string('external_id')->nullable();
            $table->string('operation', 16);                 // insert | update
            $table->json('snapshot')->nullable();            // full record (inserts only)
            $table->json('changes')->nullable();             // {field:{old,new}} (updates only)
            $table->unsignedInteger('changed_count')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['sync_run_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
    }
};
