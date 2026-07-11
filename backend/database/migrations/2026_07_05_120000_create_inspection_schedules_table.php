<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection Schedules — recurring SAFETY / OPERATIONS inspection plans (Fleetio
 * "Schedules"). Distinct from Service Reminders (which cover technical maintenance
 * like oil/filters): a schedule says "inspect this car every N days and/or every N
 * km" and the app computes a next-due point + an overdue flag from the car's clock
 * and odometer.
 *
 * A schedule is a PLAN, not a captured event — the captured events live in
 * `inspection_records`. Completing a scheduled inspection rolls last_inspected_* +
 * next_due_* forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_schedules', function (Blueprint $table) {
            $table->id();

            // The car this plan covers. Nullable so a fleet-wide plan is possible later;
            // today the UI always sets it. nullOnDelete keeps the row if the car is purged.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            $table->string('name');                          // e.g. "Weekly Safety Walk-around"
            $table->text('description')->nullable();
            $table->string('pillar', 30)->nullable();        // safety | operations | ... (the inspection pillars)

            // Cadence: time-based, meter-based, or both (whichever comes first is "due").
            $table->string('interval_type', 10)->default('time');   // time | meter | both
            $table->unsignedInteger('interval_days')->nullable();
            $table->unsignedInteger('interval_km')->nullable();

            // Anchor of the last completed inspection for this plan.
            $table->timestamp('last_inspected_at')->nullable();
            $table->unsignedInteger('last_inspected_odometer')->nullable();

            // Computed next-due point (recomputed on save / on completion).
            $table->timestamp('next_due_at')->nullable();
            $table->unsignedInteger('next_due_odometer')->nullable();

            // The inspector responsible for this recurring check.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('next_due_at');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_schedules');
    }
};
