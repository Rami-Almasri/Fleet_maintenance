<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generic action vocabulary — what can be DONE to a vehicle, independent of why.
 *
 * DELIBERATELY NOT MAINTENANCE-SPECIFIC. `replace / brake_pads` is the same action whether it comes
 * from a breakdown, a scheduled service, an inspection finding or a recall. Scoping the vocabulary
 * to one workflow would mean re-authoring it for the next one and then reconciling the two, which is
 * how "replace pads" and "Replace Brake Pads" end up as different things nothing can group.
 *
 * NOT THE SAME AS THE ONTOLOGY'S REPAIR NODES, and the distinction is worth holding:
 *
 *   ontology TYPE_REPAIR   "brake noise is fixed by replacing pads"   KNOWLEDGE — learned, versioned,
 *                                                                    confidence-scored, may be wrong
 *   action_catalog         "Replace Brake Pads · 1.2h · needs a part" VOCABULARY — operational, stable,
 *                                                                    edited by staff, not by a model
 *
 * Different lifecycles and different owners, so they are different tables with a one-way link from
 * the catalog to the graph — the same direction as every other dependency on the knowledge platform.
 *
 * VERB + TARGET, NOT A SENTENCE. Storing "Replaced the front brake pads and machined the rotor" as
 * one row makes it unanalysable: two actions, no sequence, no way to ask how often machining a rotor
 * alone is enough. Splitting the verb from the target is what lets the same question be asked across
 * every target ("how often does REPLACE beat REPAIR?") and across every verb on one component.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 96)->unique();       // replace_brake_pads

            $table->string('verb', 32);                 // replace | repair | machine | bleed | adjust | clean …
            $table->string('target', 96);               // brake_pads
            $table->string('label');                    // "Replace brake pads"
            $table->string('label_ar')->nullable();

            $table->string('category_key', 32)->nullable();  // brakes, engine … aligns with finding_keywords

            // Which vehicle systems this action is valid for. A JSON list rather than one category,
            // because `bleed / hydraulic_system` is legitimately both brakes and clutch — and it is
            // exactly that reuse that keeps the catalog generic instead of per-domain.
            $table->json('compatible_systems')->nullable();

            // A part-consuming action with no part line against it is a data-quality check that runs
            // itself — no report needed, the contradiction is visible in the record.
            $table->boolean('requires_part')->default(false);

            // Road tests and scans are actions too, but they PROVE rather than FIX. Flagged so a
            // repair claiming completion with no verification action is detectable.
            $table->boolean('is_verification')->default(false);

            $table->decimal('default_labor_hours', 5, 2)->nullable();
            $table->string('required_skill', 48)->nullable();

            // One-way link into the knowledge graph. Nullable on purpose: the vocabulary must stand
            // on its own for a workshop that never runs enrichment.
            $table->unsignedBigInteger('ontology_repair_node_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category_key', 'is_active']);
            $table->index(['verb', 'target']);
            $table->index('is_verification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_catalog');
    }
};
