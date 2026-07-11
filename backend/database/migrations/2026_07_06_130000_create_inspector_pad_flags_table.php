<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inspector's Pad — a lightweight scratchpad where the Inspector (Abu Maroof) flags a car with an
 * issue keyword and/or a free-text observation AT ANY TIME, without opening the full maintenance
 * workflow. Each row is a single pending note against one vehicle.
 *
 * These flags sit "pending" until the car is picked up for maintenance: the odometer-gated Pick-Up
 * action (InspectorPadController::pickup) mints a real maintenance ticket, copies every pending flag
 * onto the ticket's `findings` and stamps the flag `consumed` (linking it to the ticket it fed). So a
 * flag is a transient hand-off, never a parallel record of truth — the ticket owns the fault once
 * created. Deliberately NOT the `maintenances` table: a flag is a pre-ticket intent, and mixing it in
 * would resurrect the phantom-row / double-count problem the workflow was built to avoid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspector_pad_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            // At least one of keyword / observation is always present (enforced in the controller).
            $table->string('keyword')->nullable();          // e.g. "Battery", "Brakes" — a catalog reason
            $table->text('observation')->nullable();        // the inspector's free-text note
            // Reuses the ticket fault-severity scale (routine / moderate / critical); nullable = ungraded.
            $table->string('severity', 20)->nullable();
            // Lifecycle: 'pending' (waiting to be picked up) → 'consumed' (folded into a ticket).
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // The ticket this flag was folded into on pick-up (audit trail; nullOnDelete keeps the flag).
            $table->foreignId('consumed_by_maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // The hot query is "pending flags for this car" (pick-up + pad board).
            $table->index(['vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspector_pad_flags');
    }
};
