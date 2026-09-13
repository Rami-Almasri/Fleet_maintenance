<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT WAS BROKEN — one row per damaged area, not one paragraph per accident.
 *
 * A single "damage" text field is the reason nobody can answer "how much of last year's accident
 * spend was bumpers?". Each row here is a countable item that can carry its own estimate, its own
 * insurer decision and, once the car is in the shop, its own repair task — which is what lets the
 * money on the case reconcile against the work actually done.
 *
 * IT REUSES THE DAMAGE VOCABULARY RATHER THAN INVENTING ONE. `damage_catalog_id` points at the
 * existing authoritative "what was done to the car" list (@see \App\Models\DamageCatalog), and
 * `vehicle_location_id` at the curated WHERE axis (@see [[fault-location-axis]]). Free text is kept
 * as a fallback only — an accident is reported at the roadside by somebody who may not have the
 * catalog in front of them, and refusing the report to protect the vocabulary would lose the report.
 *
 * `maintenance_task_id` is the join back to the repair. It is filled when the case's repair ticket is
 * raised, so "was the left door on the estimate ever actually fixed?" is a query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accident_damage_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accident_case_id')->constrained('accident_cases')->cascadeOnDelete();

            // The vocabulary, when the reporter could name it; the words, when they could not.
            $table->foreignId('damage_catalog_id')->nullable()->constrained('damage_catalog')->nullOnDelete();
            $table->foreignId('vehicle_location_id')->nullable()->constrained('vehicle_locations')->nullOnDelete();
            $table->string('area_label');                       // always present — the display name
            $table->string('severity', 16)->default('unknown'); // minor|moderate|severe|unknown
            $table->text('description')->nullable();
            $table->boolean('requires_replacement')->nullable();
            // An ESTIMATE for this item alone. The case's money lives in accident_financial_entries;
            // this is the per-item working figure that adds up to the first of them.
            $table->decimal('estimated_cost', 12, 2)->nullable();

            // Filled when the case's repair ticket is raised. Nullable forever for items the insurer
            // refused or the owner chose to live with — an unrepaired dent is still a recorded fact.
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index('accident_case_id');
            $table->index('damage_catalog_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accident_damage_items');
    }
};
