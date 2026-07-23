<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * service_records — performed ACTIONS (labor): what was done to a vehicle, when, by whom, at what
 * mileage, with what result (Asset Layer, Phase 1).
 *
 * Services are FACTS, not a workflow: the requested/approved/in-progress pre-life lives on the
 * maintenance ticket (Ticket = single source of truth). A record lands here when the work is a
 * done deal — on re-inspection PASS (confirmRoutineServices, Phase 2), by import from the
 * invoice_items Technical Service Log, or by manual entry. A failed service's retry is a new
 * ticket, never a state change here.
 *
 * Consumables (oil, coolant, …) live HERE via materials_cost — they never become
 * vehicle_components rows. That separation (component = physical thing, service = action) is a
 * standing business rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_records', function (Blueprint $table) {
            $table->id();

            // Every action happened to exactly one car.
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // Workflow-born records carry their ticket/fault; standalone cleaning/inspection = NULL.
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();

            // Canonical action key (oil_change, alignment, inspection, diagnostics, cleaning,
            // programming, repair_labor, tyre_rotation, ...) — aligned with ServiceReminder types
            // + findings categories via App\Support\ServiceTypes.
            $table->string('service_type', 40);
            $table->string('description', 255);

            $table->date('performed_at');
            $table->unsignedInteger('odometer')->nullable();

            $table->foreignId('workshop_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('technician_name', 120)->nullable();

            // Money — UI gates behind SHOW_FINANCIALS like every other cost surface.
            $table->decimal('labor_cost', 12, 2)->nullable();
            $table->decimal('materials_cost', 12, 2)->nullable();   // consumables used (the oil itself)
            $table->decimal('duration_hours', 6, 2)->nullable();

            $table->string('result', 20);                           // completed | partial | failed

            // "Brake service ON these pads" — links action to asset when meaningful.
            $table->foreignId('related_component_id')->nullable()->constrained('vehicle_components')->nullOnDelete();

            // Trust label + idempotency keys for import/backfill (a source row converts at most once).
            $table->string('source', 20)->index();                  // workflow_close | invoice_import | manual | legacy_backfill
            $table->foreignId('source_line_item_id')->nullable()->constrained('maintenance_line_items')->nullOnDelete();
            $table->foreignId('source_invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();

            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('performed_by_name', 120)->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'performed_at']);
            $table->index(['vehicle_id', 'service_type', 'performed_at'], 'sr_last_per_type_idx'); // "last oil change?" in one seek
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_records');
    }
};
