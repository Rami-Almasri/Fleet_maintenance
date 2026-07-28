<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * garage_recommendation_decisions — a durable, first-class record of WHY a garage was chosen at dispatch.
 *
 * When a Supervisor assigns a garage (assign-dispatch), the data-driven GarageRecommendationService has
 * already ranked the options. This table snapshots that decision moment: what the engine recommended, what
 * the Supervisor actually chose, whether they followed the top pick, and the evidence (score, confidence,
 * reasons) behind it. One row per assign/re-assign — a re-dispatch writes a fresh row (we keep the history).
 *
 * The same summary is also written into the garage_assigned vehicle_log_event's `meta` for the timeline,
 * but THIS table is the canonical, queryable answer to "a month later, why did we send it here?" — so it
 * survives independently of the audit log and can be reported on (accept-rate, confidence mix, overrides).
 *
 * See [[garage-recommendation-engine]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garage_recommendation_decisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // The engine's top pick vs. the garage actually assigned.
            $table->foreignId('recommended_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('chosen_vendor_id')->constrained('vendors')->cascadeOnDelete();

            // accepted = the chosen garage was one of the recommendations; followed = it was the TOP one.
            $table->boolean('accepted')->default(false);
            $table->boolean('followed')->nullable();
            $table->unsignedSmallInteger('rank')->nullable();          // rank of the chosen garage in the list

            // Evidence behind the chosen garage (snapshot at decision time).
            $table->decimal('score', 8, 4)->nullable();
            $table->string('confidence', 12)->nullable();              // high | medium | low
            $table->json('reasons')->nullable();                       // [{t, s}] the "why" chips
            $table->json('criteria')->nullable();                      // {model, brand, faults} the query snapshot

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['maintenance_id', 'created_at']);
            $table->index(['vehicle_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garage_recommendation_decisions');
    }
};
