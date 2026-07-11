<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual mileage corrections for the Mileage Chain Audit — a NON-DESTRUCTIVE overlay on top of
 * the synced contract readings. OfficeManager owns the raw out_milage/in_milage on `contracts`;
 * a re-sync would wipe any direct edit, so a typo'd handover reading is corrected here instead.
 *
 * One row per (contract, field) where field is the reading being corrected:
 *   - field=out_milage : the car's odometer when this contract STARTED (its "start mileage")
 *   - field=in_milage  : the car's odometer when this contract ENDED  (its "end mileage")
 *
 * `corrected_value` is the value the audit should use; NULL means the row is note-only (a flag
 * left on a reading without changing it). `original_value` snapshots the synced reading at the
 * time of override so the trail shows what was changed. Mirrors the SyncCorrection /
 * PolicyOverrideAudit audit-trail pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mileage_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('field', 16);                          // out_milage | in_milage
            $table->unsignedInteger('original_value')->nullable(); // synced reading at override time
            $table->unsignedInteger('corrected_value')->nullable(); // the override (NULL = note only)
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->timestamps();

            // At most one active override per reading — the audit upserts on this pair.
            $table->unique(['contract_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mileage_overrides');
    }
};
