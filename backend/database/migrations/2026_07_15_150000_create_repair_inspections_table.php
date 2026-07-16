<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-Repair Inspection — the DURABLE, STRUCTURED record of the quality-control verdict a car gets
 * when it comes back from the garage. The lifecycle already has the GATE for this
 * (Maintenance::WF_READY_REINSPECTION, "waiting for post-repair inspection") and the PASS / FAIL
 * transitions (close() / markReinspectionFailed()) — but until now the only trace an inspection left
 * was a transient per-fault counter on maintenance_tasks (reinspection_failures). This table makes
 * every verdict a first-class, queryable row: what fault was checked, who inspected it, the result
 * (fixed / still_exists / new_issue), WHY a repair failed, and a snapshot of the repair it is judging
 * (the garage + repair date) so repeated-failure / part-failure intelligence can be computed without
 * re-deriving history. It is a LAYER on top of the existing workflow — it never gates a transition and
 * old tickets without any inspection rows keep working untouched. See [[reinspection-qc-layer]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_inspections', function (Blueprint $table) {
            $table->id();

            // The ticket being signed off (the "maintenance_ticket_id" of the spec). Cascades with the
            // ticket, matching maintenance_tasks / maintenance_line_items.
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();

            // Denormalised vehicle anchor so the vehicle-history + recurrence queries never need to join
            // back through the ticket. nullOnDelete keeps the audit row if the car record is ever removed.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // The ORIGINAL fault this verdict is about (the repaired symptom). Nullable: a legacy ticket
            // may carry no first-class tasks, and a new_issue row is not tied to a pre-existing fault.
            $table->foreignId('fault_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();

            // Case C — the NEW fault this inspection spawned (original fixed, but a different problem
            // surfaced). Points at the freshly-created maintenance_tasks row.
            $table->foreignId('new_fault_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();

            // Who performed the post-repair inspection (inspector / supervisor — the sign-off actor).
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();

            // The verdict. Kept as a short string (not a DB enum) so a new outcome never needs a migration,
            // matching how workflow_status / task status are stored in this codebase.
            $table->string('result', 20)->index();           // fixed | still_exists | new_issue

            // Case B — the structured reason a repair did not hold (required by the app when
            // result = still_exists; null otherwise). wrong_diagnosis | part_failed | repair_incomplete
            // | wrong_part | customer_complaint | unknown.
            $table->string('failure_reason', 30)->nullable();

            $table->text('notes')->nullable();

            // Snapshot of the repair this verdict judges — captured at inspection time so the "REPAIR
            // FAILED" card and the repeated-failure watchdog read a stable record even if the fault is
            // later re-dispatched and its live garage/resolved_at change. previous_vendor_id = the garage
            // that did the repair; previous_repaired_at = when it reported done.
            $table->foreignId('previous_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->timestamp('previous_repaired_at')->nullable();
            $table->unsignedInteger('days_since_repair')->nullable();  // recurrence signal, snapshotted
            $table->boolean('is_recurrence')->default(false);          // same fault came back → HIGH alert fired

            // Nullable at the schema layer (MySQL strict mode rejects a defaultless non-null timestamp);
            // the app always stamps it on insert.
            $table->timestamp('inspection_date')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'result']);
            $table->index(['maintenance_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_inspections');
    }
};
