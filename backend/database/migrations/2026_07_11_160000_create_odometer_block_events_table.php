<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odometer Block Events — the durable audit trail of REJECTED odometer readings across the maintenance
 * workflow. When an operator tries to enter a value that violates the continuity rules HARD enough to be
 * blocked (a >5 km / backward reading at an internal park spot-check, or a garage-arrival reading that
 * isn't higher than pickup), the transition is thrown away — so the ticket itself keeps NO trace of the
 * attempt. This table captures it anyway: who tried it, on which stage, the value they attempted vs. what
 * was expected. It is written OUTSIDE the (rolled-back) workflow transaction so the record survives the
 * rejection, and it is surfaced — alongside the recorded odometer_flags — on /oversight/mileage, the
 * single audit board for everything odometer-related.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odometer_block_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            // The capture point, using the SAME key as MaintenanceWorkflowService::recordOdometerFlag /
            // WorkflowOversightController::STAGE_MAP (test_drive|report|dispatch|receive|return|reinspect|transfer)
            // so the oversight board can reuse its stage labels for these rows.
            $table->string('stage_key', 32);
            // The continuity verdict that caused the block (exact_required | must_increase).
            $table->string('status', 32);
            $table->unsignedInteger('previous')->nullable(); // what the reading was expected to match/exceed
            $table->integer('reading');                       // the value the operator ATTEMPTED (rejected)
            $table->integer('delta')->nullable();             // reading − previous
            $table->text('note')->nullable();                 // any note the operator tried to submit with it
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // who tried it
            $table->timestamps();

            $table->index(['maintenance_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odometer_block_events');
    }
};
