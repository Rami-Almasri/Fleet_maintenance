<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Concept Bridge evaluation benchmark — the human ground truth that decides whether we may
 * enrich 26,839 legacy tickets with ontology concepts.
 *
 * TWO TABLES, ONE PURPOSE.
 *
 *   concept_bridge_samples  the frozen question set. A stratified sample of real ticket segments
 *                           plus what the matcher predicted for each, captured at a point in time.
 *                           NEVER regenerated in place — a new sample is a new `sample_set`, so a
 *                           benchmark result always refers to a fixed set of questions.
 *
 *   concept_bridge_labels   the answers. One row per (sample, labeller). `source` separates HUMAN
 *                           ground truth from the AI baseline, and they are never averaged: the
 *                           human labels ARE the benchmark, the AI labels only measure how far a
 *                           machine pass can be trusted on rows nobody reviewed.
 *
 * WHY THE SPLIT MATTERS. An evaluation set that mixes machine and human judgement measures a model
 * agreeing with itself. Keeping `source` on every row is what makes the two tracks separable
 * forever, including after someone forgets which file came from where.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concept_bridge_samples', function (Blueprint $table) {
            $table->id();

            // Which frozen sample this row belongs to (v2, v3, …). Results are only comparable
            // within one set.
            $table->string('sample_set', 32)->default('v2')->index();
            $table->unsignedInteger('row_no');                 // position within the set

            // Why this row was chosen — precision is reported PER STRATUM, never as one blended
            // number, because the strata are deliberately not proportional to real traffic.
            $table->string('stratum', 32)->index();

            // The question.
            $table->unsignedBigInteger('maintenance_id')->nullable()->index();
            $table->string('v1_signature', 32)->nullable();    // context only — NOT ground truth
            $table->string('source_field', 32)->nullable();    // service_main | service_sup | maintenance_notes
            $table->text('segment_text');

            // What the matcher said, frozen with the question.
            $table->string('pred1_concept', 120)->nullable();
            $table->unsignedTinyInteger('pred1_score')->nullable();
            $table->string('pred1_primary_stage', 20)->nullable();   // exact|alias|phrase|token|fuzzy|semantic
            $table->string('pred1_all_stages', 60)->nullable();
            $table->string('pred1_matched_term', 160)->nullable();
            $table->string('pred2_concept', 120)->nullable();
            $table->unsignedTinyInteger('pred2_score')->nullable();
            $table->string('pred3_concept', 120)->nullable();
            $table->unsignedTinyInteger('pred3_score')->nullable();

            $table->timestamps();
            $table->unique(['sample_set', 'row_no']);
        });

        Schema::create('concept_bridge_labels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('concept_bridge_sample_id')->constrained()->cascadeOnDelete();

            // THE LINE THAT KEEPS THE BENCHMARK HONEST.
            $table->string('source', 10);                      // human | ai
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('labeller', 60)->nullable();         // model name for ai rows

            $table->string('segment_type', 20)->nullable();     // fault|action|part|procedure|operational|unclear
            $table->string('verdict', 20)->nullable();          // correct_specific|correct_broad|incorrect|none_predicted
            $table->string('evidence_quality', 10)->nullable(); // strong|medium|weak

            $table->text('valid_concepts')->nullable();
            $table->text('invalid_concepts')->nullable();
            $table->text('missing_concepts')->nullable();       // the ontology gap backlog
            $table->text('notes')->nullable();

            $table->timestamps();

            // One label per sample per labeller — re-answering updates rather than appends, so a
            // reviewer changing their mind mid-session doesn't double-count.
            $table->unique(['concept_bridge_sample_id', 'source', 'user_id'], 'cbl_sample_source_user_unique');
            $table->index(['source', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concept_bridge_labels');
        Schema::dropIfExists('concept_bridge_samples');
    }
};
