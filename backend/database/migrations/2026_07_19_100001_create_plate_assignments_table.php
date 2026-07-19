<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADDITIVE + REVERSIBLE. A timeline of which vehicle held a plate over which period — the
 * durable record behind "Plate History". History is DISCOVERABLE, never transferred: each
 * row just links a vehicle_id to a plate_key for a date window; no maintenance/inspection/
 * cost data ever moves between vehicles. One row per (vehicle, plate). down() drops the table.
 *
 *   is_current = this car is the plate's live holder (null for a fully-historical plate, or
 *                for an unresolved plate awaiting manual review).
 *   confidence = high (clear) | derived (auto-resolved, low stakes) | low (needs human review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plate_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('plate_key', 40)->index();
            $table->string('plate_raw', 64)->nullable();        // plate_no as stored on the vehicle
            $table->date('from_date')->nullable();              // when this car took the plate (~purchase)
            $table->date('to_date')->nullable();                // when the next car took it; null = open/current
            $table->boolean('is_current')->default(false);      // this car is the live holder of the plate
            $table->string('source', 20)->default('backfill');  // backfill | om_sync | manual
            $table->string('confidence', 12)->default('high');  // high | derived | low
            $table->text('note')->nullable();                   // provenance / ambiguity detail
            $table->timestamps();

            $table->unique(['vehicle_id', 'plate_key']);        // one row per car+plate (idempotent backfill)
            $table->index(['plate_key', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plate_assignments');
    }
};
