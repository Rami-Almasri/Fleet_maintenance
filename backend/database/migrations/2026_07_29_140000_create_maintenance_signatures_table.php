<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPAIR INTELLIGENCE — the canonical signature projection.
 *
 * This is a DOMAIN PROJECTION, not a source of truth. Every row is derived from `maintenances`
 * (`service_main` when a human labelled it, `maintenance_notes` otherwise) by
 * [[RepairSignatureClassifier]], and the whole table can be dropped and rebuilt from scratch at any
 * time with `intelligence:rebuild-signatures`. Nothing may write to it except that rebuild and the
 * classifier hook — if a human changes a label, they change it on the ticket and the projection
 * follows.
 *
 * WHY IT EXISTS AT ALL. `maintenances.service_main` is free text on 322 distinct strings and is
 * populated on only 27.5% of events. Every question the maintenance intelligence loop asks —
 * "has this fault happened on this car before?", "how do garages compare on THIS fault?", "when in
 * the year does it peak?" — is unanswerable against that column and trivial against this table.
 * Coverage here is 78.7%, validated at 80.4% agreement with human labels
 * (`intelligence:validate-classifier`). See docs/Fleet-Knowledge-Engine-Discovery-Log.md (D1).
 *
 * OWNERSHIP. This belongs to the maintenance domain, deliberately NOT to the generic knowledge /
 * ontology platform. Repair Intelligence may consume the Knowledge Platform's reusable capabilities
 * (keyword extraction, embeddings, semantic search); the Knowledge Platform must never need to know
 * what a comeback or a garage recommendation is. The dependency is one-way, and keeping these
 * projections in maintenance-owned tables is what lets us version, invalidate or redesign them
 * without touching the ontology.
 *
 * DENORMALISATION IS INTENTIONAL. `vehicle_id` and `occurred_at` are copied from the parent ticket
 * so the hot query — "same vehicle, same signature, within 90 days" — is served by one index
 * without a join. That query is the comeback detector, the single highest-value card in the
 * platform, and it runs on every case open. A projection is allowed to be shaped for its reads.
 *
 * `is_exposure` is likewise denormalised from the signature: BODY and RIM recur at 78.6% and 62.4%
 * because customers keep damaging cars, not because workshops keep failing. Every workshop-quality
 * metric must exclude them, and carrying the flag on the row makes that exclusion a WHERE clause
 * nobody can forget rather than a rule everyone must remember. See Discovery Log (D3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_signatures', function (Blueprint $table) {
            $table->id();

            $table->foreignId('maintenance_id')->constrained()->cascadeOnDelete();

            // Denormalised from the parent ticket — see the class doc. Nullable because a small
            // number of historical rows carry neither a resolved vehicle nor an out_date.
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->date('occurred_at')->nullable();

            // One of the 22 canonical signatures. Not an FK: the vocabulary is a code constant in
            // RepairSignatureClassifier, versioned with the classifier rather than with the data.
            $table->string('signature', 24);

            //  human    — the ticket carried a service_main label, mapped onto the vocabulary
            //  derived  — inferred from maintenance_notes by the classifier
            //  confirmed— derived, then confirmed by a human at intake (the Layer 4 upgrade path)
            $table->string('source', 12)->default('derived');

            // BODY / RIM. Denormalised so quality metrics cannot accidentally include exposure.
            $table->boolean('is_exposure')->default(false);

            // The terms that fired, so a Decision Card can always show why this label was applied.
            $table->json('matched_terms')->nullable();

            // Invalidation handle: bump when the pattern set changes, then rebuild. Lets us keep an
            // old projection readable while a new one is built and compared.
            $table->string('classifier_version', 20)->index();

            $table->timestamps();

            // Idempotent rebuild — one row per (ticket, signature), re-runnable without duplicates.
            $table->unique(['maintenance_id', 'signature'], 'maint_sig_unique');

            // THE comeback query: same vehicle, same signature, inside a date window.
            $table->index(['vehicle_id', 'signature', 'occurred_at'], 'maint_sig_vehicle_lookup');

            // Cohort queries: seasonality, per-signature baselines, garage scoring.
            $table->index(['signature', 'occurred_at'], 'maint_sig_cohort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_signatures');
    }
};
