<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Reminders — recurring TECHNICAL maintenance due points per vehicle (oil,
 * filters, brakes, tyres, …). The Fleetio "Service Reminders" analogue, kept separate
 * from Inspection Schedules (safety/ops).
 *
 * Auto-derive + manual override:
 *   - The "oil_change" reminder for each car is AUTO-seeded from the Oil Change sheet
 *     data already on `vehicles` (last_service_odometer + service_interval_km) — source
 *     = 'auto'. The seeder mirrors Vehicle::serviceStatus() so numbers stay consistent.
 *   - A user can edit any reminder (or add air_filter/brake_pads/…); that flips
 *     source = 'manual' and the auto-seeder then leaves it alone (manual wins).
 *
 * Status (overdue / due-soon / ok) is derived live from the car's odometer + clock, so
 * it's never stale; only the anchors (interval + last service) are stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_reminders', function (Blueprint $table) {
            $table->id();

            // A reminder is meaningless without its car — cascade so purging a car cleans up.
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            $table->string('service_type', 40);              // oil_change | air_filter | brake_pads | tire_rotation | general | ...
            $table->string('name')->nullable();              // display label, e.g. "Oil Change"

            // Cadence. km drives the odometer engine; days drives the calendar engine; either/both.
            $table->unsignedInteger('interval_km')->nullable();
            $table->unsignedInteger('interval_days')->nullable();

            // Anchor of the last time this service was performed.
            $table->unsignedInteger('last_service_odometer')->nullable();
            $table->date('last_service_at')->nullable();

            // Computed next-due point (stored for sorting/filtering; recomputed on save).
            $table->unsignedInteger('next_due_odometer')->nullable();
            $table->date('next_due_at')->nullable();

            $table->string('source', 10)->default('manual'); // auto (from Oil Change sheet) | manual (user override)
            $table->boolean('is_muted')->default(false);     // snoozed: keep the reminder but stop alerting
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // One reminder per car per service type — makes the auto-seed idempotent.
            $table->unique(['vehicle_id', 'service_type']);
            $table->index('next_due_at');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reminders');
    }
};
