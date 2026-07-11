<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The editable "Garage Preference Rules" matrix behind the Smart Routing Engine.
 *
 * Each row is ONE weighted preference: "this garage (vendor) is good at this thing (match_key) along
 * this axis (dimension), worth this many points (weight)." The engine sums the matching rules for a
 * ticket's fault categories + the car's vehicle_class, nudges by the garage's rating, subtracts a
 * SOFT quality penalty (never a hard block — see config/garage_routing.php), and suggests the winner.
 *
 * Why one flat table instead of two matrices: fault-mapping and vehicle-specialisation are the same
 * shape ("garage is good at X"), so a single `dimension` column keeps them together and lets a new
 * axis be added later with zero schema change. Weights (not booleans) are what make the matrix
 * "easily updatable as we learn which garages perform better" — you re-tune a number, not the code.
 *
 * Same lifecycle as finding_keywords / fault_causes: config/garage_routing.php can seed defaults,
 * GarageRoutingRuleSeeder upserts them idempotently, and the admin dashboard is the day-to-day editor
 * (GarageRoutingRuleController, to follow). The DB is the runtime source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garage_routing_rules', function (Blueprint $table) {
            $table->id();

            // The garage this rule is about. Cascade so deleting a garage removes its stale rules.
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            // Which axis this rule scores on: 'fault_category' or 'vehicle_class'
            // (config('garage_routing.dimensions')). Kept a string, validated in the controller, so a
            // future axis needs no migration.
            $table->string('dimension', 30)->index();

            // What it matches on WITHIN that axis: a findings category slug (engine / ac / electrical,
            // from config('maintenance_findings.categories')) for fault_category, or a vehicle-class
            // slug (luxury_suv / standard …) for vehicle_class. 60 keeps the composite unique index
            // comfortably inside InnoDB's utf8mb4 key-length limit.
            $table->string('match_key', 60)->index();

            // Preference strength. SIGNED on purpose: a positive value prefers this garage, a negative
            // value marks it as known-weak for this match without needing a separate "avoid" flag.
            // Default mirrors config('garage_routing.scoring.default_weight').
            $table->integer('weight')->default(10);

            // Headline specialist? Drives the badge copy ("Specialist in Electrical") and outranks a
            // plain positive weight when the engine picks the single reason to show.
            $table->boolean('is_specialist')->default(false);

            // Soft on/off, so a rule can be parked without losing its history (matches
            // finding_keywords.is_active). Indexed because the engine only loads active rules.
            $table->boolean('active')->default(true)->index();

            // Optional admin note explaining the rule ("German-marque electrical specialist").
            $table->string('note', 500)->nullable();

            // Light audit: who created / last touched the rule. Null on user deletion, never cascade.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One rule per (garage, axis, match): the seeder upserts on this triple and a duplicate
            // admin entry is rejected rather than double-counted in the score.
            $table->unique(['vendor_id', 'dimension', 'match_key'], 'garage_routing_rules_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garage_routing_rules');
    }
};
