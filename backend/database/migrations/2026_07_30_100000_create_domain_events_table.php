<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical event log — the system's record of what was observed and what was concluded.
 *
 * WHY A NEW TABLE RATHER THAN `vehicle_log_events`. That table is a narrative timeline: its payload
 * is a human sentence, it is keyed to workflow status, and it exists to be read by a person. Under
 * the governance rule that nothing derived may become canonical truth, a read model cannot also be
 * the source of truth — so the timeline stays exactly what it is, and can itself be projected from
 * this log over time. Nothing about it changes today.
 *
 * TWO LAYERS LIVE HERE, AND ONLY TWO. Facts (what was observed) and judgements (what a person
 * concluded). Derived knowledge is deliberately absent: it is never recorded, only ever recomputed
 * from this log, which is what keeps projections disposable and rebuildable.
 *
 * FULLY IMMUTABLE — NO COLUMN IS EVER UPDATED. There is no `superseded_at`, no `is_current` flag,
 * because maintaining either would mean writing to a row that has already been recorded, and "facts
 * are immutable" would then be a convention rather than a guarantee. Supersession is expressed only
 * by the NEW row pointing back at the old one; currency is derived at read time by asking whether
 * anything supersedes a row. This costs an index and buys an append-only log in the strict sense: it
 * can be copied, replayed and audited without anyone needing to trust that no one edited it.
 *
 * BITEMPORAL. `occurred_at` is when it happened in the world; `recorded_at` is when the system
 * learned it. They differ constantly — a garage reports Tuesday's repair on Thursday — and analytics
 * that conflates them will misattribute effects to the wrong week. Keeping both is what makes a
 * projection reproducible "as we knew it on date X" rather than only as we know it now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table) {
            $table->id();

            // --- what happened ------------------------------------------------------------------
            $table->string('event_type', 64);

            // Payload shapes evolve. Stamping the version each event was written under means a
            // replay years from now can interpret old events under old rules instead of guessing.
            $table->unsignedSmallInteger('event_version')->default(1);

            // fact | judgement. Never 'derived' — see the class doc.
            $table->string('layer', 16);

            // --- what it is about ---------------------------------------------------------------
            // Denormalised subject keys: every meaningful question ("everything about this car",
            // "everything about this ticket") is answered by an index here rather than by opening
            // the JSON payload, which no database can index usefully at this scale.
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('maintenance_id')->nullable();
            $table->unsignedBigInteger('maintenance_task_id')->nullable();

            // For events about anything else (a part, a vendor, a contract) without adding a column
            // per subject type as the platform grows.
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->json('payload');

            // === PROVENANCE — the trust axis, orthogonal to the layer ============================
            // A fact measured by an instrument and a fact reported by the party being graded are
            // both facts. Without these columns they rank identically, and the first supplier
            // comparison that runs is quietly wrong.

            $table->unsignedBigInteger('observed_by')->nullable();      // the user, when there is one
            $table->string('observed_by_name')->nullable();             // kept verbatim: the log must stay
                                                                        // readable after a user is deleted
            $table->string('actor_type', 24);                           // staff|garage|customer|system|device
            $table->unsignedBigInteger('organization_id')->nullable();  // vendor/garage the actor belongs to
            $table->string('capture_method', 24);                       // measured|scanned|visual|reported|imported|computed
            $table->unsignedTinyInteger('trust_level');                 // 0–100, derived from the above at write time
            $table->string('source_system', 32)->default('fleet');      // fleet|garage_portal|officemanager|import|api

            // True when the actor has an interest in the answer — a garage reporting its own repair
            // outcome. Stored explicitly rather than inferred from actor_type at query time, because
            // every consumer would have to re-derive it and one of them would get it wrong.
            $table->boolean('is_self_reported')->default(false);

            // --- correction & versioning ---------------------------------------------------------
            // A corrected fact or a revised diagnosis is a NEW row pointing at the one it replaces.
            // The chain IS the history: for a judgement, how the diagnosis evolved is often worth
            // more than where it landed.
            $table->unsignedBigInteger('supersedes_event_id')->nullable();
            $table->string('supersede_reason')->nullable();

            // Groups every event produced by one capture session, so a replay can reconstruct
            // "everything the technician entered in that one visit" as a unit.
            $table->uuid('correlation_id')->nullable();

            // --- time ----------------------------------------------------------------------------
            $table->timestamp('occurred_at');                           // when it happened in the world
            $table->timestamp('recorded_at')->useCurrent();             // when we learned it

            // No updated_at. Nothing here is ever updated.
            $table->index(['vehicle_id', 'occurred_at']);
            $table->index(['maintenance_id', 'event_type']);
            $table->index(['maintenance_task_id', 'event_type']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('correlation_id');

            // The index that makes read-time currency cheap: "is there an event superseding this
            // one?" is the question every read of a fact or judgement has to answer.
            $table->index('supersedes_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
