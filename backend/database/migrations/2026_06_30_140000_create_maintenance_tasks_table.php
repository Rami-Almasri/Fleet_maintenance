<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * maintenance_tasks — the first-class, independently-routable FAULT.
 *
 * Until now every symptom a ticket dealt with lived as an object inside the `maintenances.findings`
 * JSON array: it could not carry its own status, its own garage, a transfer history or its own money.
 * This table promotes each finding to a real row. The parent `maintenances` ticket becomes a
 * CONTAINER: it can hold N tasks, each one Pending / In Progress / Completed / Transferred and routed
 * to its own garage, while the ticket's `cost` / `vendor_id` / `fault_severity` become roll-ups of
 * its tasks (see Maintenance::recalcFromTasks() and the MaintenanceTask model).
 *
 * Cost is NOT stored here as the source of truth — it rolls up from `maintenance_line_items` rows now
 * tagged with `maintenance_task_id` (parts_cost / labor_cost are cached sums kept honest on every
 * line write). The per-fault timeline (identified → which garage → moved → resolved) is reconstructed
 * from `maintenance_task_assignments` (the garage "stints") bracketed by identified_at / resolved_at,
 * plus the task-scoped rows in `vehicle_log_events`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tasks', function (Blueprint $table) {
            $table->id();
            // The PARENT ticket this fault belongs to. Cascade: deleting the container removes its tasks.
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            // Denormalised for fast per-car fault history without a join back through the ticket.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // ── What the fault IS (migrated from a findings[] entry) ──────────────────────────────
            $table->string('symptom', 255);                       // findings[].text  ("Brake noise")
            $table->string('category_key', 60)->nullable();       // findings-catalog key (brakes/engine/…)
            $table->string('source', 20)->default('inspector');   // inspector | garage (Maintenance::FINDING_SOURCES)
            $table->string('severity', 10)->nullable();           // critical | moderate | routine — now PER FAULT
            // Ties into the Symptom → Root-Cause knowledge base (fault_causes). Label denormalised so the
            // chosen cause persists with the task and is Odoo-sync ready even if the KB row is later edited.
            $table->foreignId('root_cause_id')->nullable()->constrained('fault_causes')->nullOnDelete();
            $table->string('root_cause', 191)->nullable();
            $table->text('notes')->nullable();

            // ── Independent routing & status ──────────────────────────────────────────────────────
            // pending → in_progress → completed ; transferred = transient (a new assignment flips it back) ;
            // cancelled = a non-issue, excluded from the "all faults resolved" gate so it never blocks close.
            $table->string('status', 20)->default('pending')->index();
            // The garage that owns the fault RIGHT NOW — the denormalised mirror of the open stint
            // (maintenance_task_assignments.released_at IS NULL). Kept in sync on assign / transfer.
            $table->foreignId('current_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            // ── Per-fault timeline anchors (the observability requirement) ────────────────────────
            $table->foreignId('identified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('identified_at')->nullable();       // when the fault was logged
            $table->timestamp('started_at')->nullable();          // first time a garage began work on it
            $table->timestamp('resolved_at')->nullable();         // finally fixed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            // ── Per-fault financial roll-up (cached Σ of this task's maintenance_line_items) ───────
            $table->decimal('parts_cost', 12, 2)->default(0);
            $table->decimal('labor_cost', 12, 2)->default(0);
            $table->decimal('repair_hours', 6, 2)->nullable();    // moves out of the findings JSON

            $table->timestamps();

            $table->index(['maintenance_id', 'status']);          // container board: tasks of a ticket by state
            $table->index(['current_vendor_id', 'status']);       // garage workload: open faults at a garage
            $table->index(['vehicle_id', 'status']);              // per-car open faults
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tasks');
    }
};
