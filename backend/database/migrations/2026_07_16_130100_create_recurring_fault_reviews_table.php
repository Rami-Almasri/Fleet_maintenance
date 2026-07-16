<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring Fault Reviews — a management review case opened when a CONFIRMED fault (verdict = confirmed,
 * recorded by the workshop at the "In Workshop" stage) matches the SAME fault that was already FIXED on
 * the same vehicle within the recurrence window. It means the car came back with a problem a garage had
 * already signed off — the exact signal a fleet must not miss.
 *
 * This table is a REVIEW CASE, not a verdict: it captures the evidence (previous ticket, garage, parts
 * used, days since repair, distance driven since repair, how many times the fault has recurred, and
 * whether that previous repair was verified) and lets management record ONE decision:
 *   same_repair_failed | new_unrelated_failure | workshop_responsibility | customer_misuse | investigation_required
 *
 * Deliberate rules baked in around it (enforced in RecurringFaultService):
 *   • Never opened unless the previous fault was actually marked Fixed.
 *   • Never opened while the car is still under the previous repair (previous fault must be completed).
 *   • The garage is NEVER auto-blamed — the system only opens the case; a human assigns responsibility.
 *
 * Mirrors the part_investigations accountability-record conventions. See [[maintenance-tasks-container-model]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_fault_reviews', function (Blueprint $table) {
            $table->id();

            $table->string('status', 20)->default('open')->index();   // 'open' | 'decided'
            $table->string('decision', 30)->nullable();
            //   same_repair_failed | new_unrelated_failure | workshop_responsibility | customer_misuse | investigation_required

            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // The CURRENT (confirmed, recurring) fault + its ticket.
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            $table->foreignId('maintenance_task_id')->constrained('maintenance_tasks')->cascadeOnDelete();

            // The PREVIOUS fixed occurrence this one matched.
            $table->foreignId('previous_maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->foreignId('previous_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();
            $table->foreignId('repair_inspection_id')->nullable()->constrained('repair_inspections')->nullOnDelete();

            // What the fault is.
            $table->string('symptom');
            $table->string('category_key', 60)->nullable();

            // Previous-repair snapshot (denormalized so the case is stable even if the old rows change).
            $table->foreignId('previous_garage_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('previous_garage_name')->nullable();
            $table->string('previous_result', 20)->nullable();        // 'verified_fixed' | 'fixed'
            $table->timestamp('previous_repaired_at')->nullable();
            $table->unsignedInteger('days_since_repair')->nullable();
            $table->unsignedInteger('previous_odometer')->nullable();
            $table->unsignedInteger('current_odometer')->nullable();
            $table->integer('distance_since_repair')->nullable();     // current − previous odometer (km)
            $table->unsignedInteger('occurrence_count')->default(1);  // how many times this fault has been fixed before + this
            $table->json('parts')->nullable();                        // [{description, part_number}] replaced in the previous repair
            $table->json('context')->nullable();                      // full snapshot for the detail view

            // --- audit stamps (nullable actor + name snapshot), mirrors part_investigations ---
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opened_by_name')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index(['status', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_fault_reviews');
    }
};
