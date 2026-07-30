<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE RECOMMENDATION LIFECYCLE — generic, append-only, capability-agnostic.
 *
 * Every recommendation the platform has ever made, and everything that happened to it. Nothing here
 * mentions comebacks, garages, parts or vehicles-as-a-concept: any capability written in the next
 * five years participates in learning by producing a Decision Card, with no feedback model of its
 * own. That is the difference between a platform that learns and a set of features that each
 * reinvent a `*_feedback` table.
 *
 * TWO TABLES, ONE JOB EACH
 *  - recommendations       — an IMMUTABLE SNAPSHOT of what was said, and why, at the moment it was said.
 *  - recommendation_events — the append-only lifecycle: presented → viewed → accepted / overridden /
 *                            dismissed / expired → outcome_recorded.
 *
 * WHY THE SNAPSHOT IS FULL TEXT, NOT A FOREIGN KEY. The card's observation, recommendation,
 * reasoning, evidence, confidence and the capability version are all copied in. Capabilities will
 * improve; patterns will be retuned; the projection will be rebuilt. When a supervisor asks in
 * eighteen months "why did it tell me to do that?", the answer must be the reasoning it ACTUALLY
 * showed — not the reasoning today's code would generate from today's data. A recommendation is a
 * historical observation, and observations are not editable.
 *
 * APPEND-ONLY IS ENFORCED IN THE MODELS ([[Recommendation]], [[RecommendationEvent]] both throw on
 * update and delete), not merely documented here. A correction is a new event, never a mutation.
 *
 * This is also the dataset the learning loop reads: accepted-vs-overridden by capability, override
 * reasons as text, and — once outcomes land — recommendation accuracy, confidence calibration, and
 * the tripwire that matters most, whether OVERRIDDEN recommendations outperform ACCEPTED ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();

            // ---- provenance: who said it, and which version of them ----
            $table->string('capability_id', 60)->index();   // 'comeback-warning'
            $table->string('card_id', 60);                  // usually == capability_id; kept separate
            $table->string('capability_version', 20)->default('v1');
            $table->string('engine_version', 20)->default('v1');

            // ---- what it was about (polymorphic: a ticket today, a part request tomorrow) ----
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Denormalised so "every recommendation ever made about this car" is one index hit.
            $table->unsignedBigInteger('vehicle_id')->nullable();

            // ---- the decision moment ----
            $table->string('workflow_state', 40)->nullable()->index();

            // ---- the card, frozen ----
            $table->unsignedTinyInteger('tier');
            $table->string('confidence', 12);               // strong | moderate | limited
            $table->string('strength', 12);                 // must | should | consider
            $table->text('observation');
            $table->text('recommendation');
            $table->text('reasoning');
            $table->json('evidence');                       // sample size, source ids, proxy note, coverage
            $table->json('actions')->nullable();

            // ---- presentation ----
            $table->foreignId('presented_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('presented_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'reco_subject');
            $table->index(['vehicle_id', 'created_at'], 'reco_vehicle');
            $table->index(['capability_id', 'created_at'], 'reco_capability');
        });

        Schema::create('recommendation_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recommendation_id')->constrained()->cascadeOnDelete();

            // created | presented | viewed | accepted | overridden | dismissed | expired | outcome_recorded
            $table->string('event', 24)->index();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // The override reason. The single most valuable free text in the platform: it is the
            // user telling us what the model could not see.
            $table->text('reason')->nullable();

            // What eventually happened, whenever that is knowable — a QC verdict, a later ticket,
            // a 90-day watch result. Polymorphic because "outcome" means something different per
            // capability, and the lifecycle must not care which.
            $table->string('outcome_type', 60)->nullable();
            $table->unsignedBigInteger('outcome_id')->nullable();
            $table->string('outcome_result', 24)->nullable();   // e.g. correct | incorrect | inconclusive

            $table->json('payload')->nullable();

            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['recommendation_id', 'event'], 'reco_event_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_events');
        Schema::dropIfExists('recommendations');
    }
};
