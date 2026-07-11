<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope an audit event to a specific FAULT.
 *
 * `vehicle_log_events` is the append-only audit trail of the workflow. Today every event is scoped to
 * the ticket (maintenance_id). Adding a nullable `maintenance_task_id` lets task-level actions —
 * a fault identified, assigned to a garage, transferred, resolved — be attributed to the one fault
 * they concern, so the per-fault TIMELINE is a single filtered read of this existing table rather than
 * a parallel event system. Null = a ticket-wide event (unchanged behaviour for every current writer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_log_events', function (Blueprint $table) {
            $table->foreignId('maintenance_task_id')
                ->nullable()
                ->after('maintenance_id')
                ->constrained('maintenance_tasks')
                ->nullOnDelete();
            $table->index(['maintenance_task_id', 'occurred_at']); // a fault's timeline, in order
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_log_events', function (Blueprint $table) {
            $table->dropIndex(['maintenance_task_id', 'occurred_at']);
            $table->dropConstrainedForeignId('maintenance_task_id');
        });
    }
};
