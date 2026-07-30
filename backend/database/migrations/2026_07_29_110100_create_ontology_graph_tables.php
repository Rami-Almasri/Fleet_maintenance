<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ONTOLOGY GRAPH — the relationship half of the Automotive Knowledge Engine.
 *
 * V1 stored a fault's components, causes and repairs as JSON arrays of strings on the profile. That
 * is a *description*, not a *model*: "Brake Pads" appearing under three different faults was three
 * unrelated strings, so you could never ask "what else fails on this component?", "which faults
 * share a root cause?", or "what parts will this repair need?".
 *
 * Here every one of those strings becomes a NODE, and every arrow between them an EDGE:
 *
 *      Brake noise ──presents_as──▶ metallic sound when braking
 *            │
 *            ├──affects_component──▶ Brake pads ──requires_part──▶ Front pad set
 *            ├──caused_by──────────▶ Worn friction material  (weight 82, 214 fleet observations)
 *            ├──fixed_by───────────▶ Replace pads            (weight 91, 92% of fleet cases)
 *            ├──inspected_by───────▶ Measure pad thickness
 *            └──related_to─────────▶ Brake vibration
 *
 * Traversal, not keyword matching. See [[OntologyGraphService]].
 *
 * THREE THINGS THAT ARE NOT OBVIOUS FROM THE COLUMN NAMES
 *
 * 1. `scope_key` is what makes the graph VEHICLE-SPECIFIC. The same fault means different things on
 *    a Toyota and a BMW, so an edge carries the vehicle scope it was asserted for — '*' for
 *    universal, 'bmw' for make-wide, 'bmw|3-series|2019-2025' for a generation. A query for a BMW
 *    reads universal edges AND BMW edges; a Toyota never sees the BMW ones. Denormalised into one
 *    string because MySQL treats NULLs as distinct in unique indexes, which would let six nullable
 *    scope columns silently accumulate duplicate edges.
 *
 * 2. `source` distinguishes ASSERTED from OBSERVED knowledge. `ai` and `human` edges are claims
 *    about how cars work; `fleet` edges are counted facts about OUR cars ("of 214 tickets with this
 *    symptom, 92% were closed by replacing pads"). They are stored side by side and weighted
 *    differently — fleet evidence is rarer but far more predictive, and keeping the provenance means
 *    the confidence score can say WHY it is high.
 *
 * 3. `observed_count` / `observed_rate` are only meaningful on fleet edges, and they are what turn
 *    the graph into a ranking engine rather than a diagram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ontology_nodes', function (Blueprint $table) {
            $table->id();

            // What kind of thing this is. Deliberately a small, closed vocabulary — a graph whose
            // node types grow without discipline stops being queryable.
            //   fault | symptom | component | cause | repair | part | procedure | system | tool | skill
            $table->string('type', 20)->index();

            // Stable comparison key (normalised label) + the display forms.
            $table->string('key', 191)->index();
            $table->string('label', 191);
            $table->string('label_ar', 191)->nullable();

            // Fault nodes mirror a findings keyword 1:1 — that is the bridge between the graph and
            // the keyword library, so a ticket's finding is already a graph entry point.
            $table->foreignId('finding_keyword_id')->nullable()->constrained()->cascadeOnDelete();

            // Vehicle scope — see the class doc.
            $table->string('make', 60)->nullable()->index();
            $table->string('model', 60)->nullable();
            $table->string('generation', 60)->nullable();
            $table->string('engine', 60)->nullable();
            $table->string('scope_key', 120)->default('*')->index();

            // ai | human | fleet | seed
            $table->string('source', 12)->default('ai')->index();
            $table->unsignedTinyInteger('confidence')->default(70);

            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            // One node per (type, key, scope). "Brake pads" universal and "Brake pads (BMW)" are
            // legitimately different nodes; two universal "Brake pads" are a bug.
            $table->unique(['type', 'key', 'scope_key'], 'ontology_nodes_type_key_scope_unique');
        });

        Schema::create('ontology_edges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('from_node_id')->constrained('ontology_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('ontology_nodes')->cascadeOnDelete();

            // The relation vocabulary. Closed set — see OntologyEdge::RELATIONS.
            //   presents_as | affects_component | caused_by | fixed_by | requires_part
            //   | inspected_by | related_to | part_of | requires_tool | requires_skill | precedes
            $table->string('relation', 24)->index();

            // 0–100. On an asserted edge: how strong the relationship is. On a fleet edge: derived
            // from the observed rate. This is what ranks "probable causes" rather than listing them.
            $table->unsignedTinyInteger('weight')->default(50);
            $table->unsignedTinyInteger('confidence')->default(70);

            // ai | human | fleet | seed. See the class doc — asserted vs observed.
            $table->string('source', 12)->default('ai')->index();

            // Fleet observation counters. Null/zero on asserted edges.
            $table->unsignedInteger('observed_count')->default(0);
            $table->unsignedTinyInteger('observed_rate')->default(0);   // % of cases, 0–100
            $table->timestamp('last_observed_at')->nullable();

            $table->string('scope_key', 120)->default('*')->index();
            $table->string('note', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            // One edge per (from, to, relation, scope) — re-running enrichment or fleet mining
            // updates the weight rather than stacking duplicates.
            $table->unique(['from_node_id', 'to_node_id', 'relation', 'scope_key'], 'ontology_edges_unique');
            $table->index(['from_node_id', 'relation', 'weight'], 'ontology_edges_traverse_idx');
            $table->index(['to_node_id', 'relation'], 'ontology_edges_reverse_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ontology_edges');
        Schema::dropIfExists('ontology_nodes');
    }
};
