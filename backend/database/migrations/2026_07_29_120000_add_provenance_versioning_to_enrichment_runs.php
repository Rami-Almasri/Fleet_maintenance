<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPRODUCIBILITY — stamp every enrichment run with everything that could change its output.
 *
 * Six months from now someone regenerates a keyword and the result is different. Without this, the
 * only available explanation is "the AI changed", which is unfalsifiable and erodes trust in the
 * whole knowledge base. With it, the diff is attributable: the extraction model moved from
 * claude-opus-5 to something else, or the prompt version went 2.0 → 2.1, or the corpus grew by 40
 * documents, or a retriever was switched off.
 *
 * WHY SEPARATE PROVIDER AND MODEL COLUMNS PER CAPABILITY. Research and extraction can legitimately
 * run on different vendors (see config/knowledge_platform.php), so "the model" is not one value.
 * Recording them separately is what lets you answer "did this change because research moved to a
 * different provider, or because extraction did?".
 *
 * `source_snapshot` is the one non-obvious field: a hash plus counts of the corpus state at run
 * time. Two runs with identical versions but different snapshots mean the KNOWLEDGE changed, not
 * the software — which is usually the real answer and the hardest one to reconstruct afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_enrichment_runs', function (Blueprint $table) {
            // Who generated it. `model` already exists (kept for backwards compatibility with runs
            // logged before this migration); these name the capability that produced each half.
            $table->string('research_provider', 40)->nullable()->after('model');
            $table->string('research_model', 80)->nullable()->after('research_provider');
            $table->string('extraction_provider', 40)->nullable()->after('research_model');
            $table->string('extraction_model', 80)->nullable()->after('extraction_provider');
            $table->string('embedding_model', 80)->nullable()->after('extraction_model');

            // What the software was doing. Bumped by hand in config when behaviour changes.
            $table->string('prompt_version', 20)->nullable()->after('embedding_model');
            $table->string('ontology_version', 20)->nullable()->after('prompt_version');
            $table->string('retrieval_version', 20)->nullable()->after('ontology_version');

            // What the knowledge looked like: {chunks, documents, sources, fleet_edges, hash}.
            // The hash is over document checksums, so an ingested or removed document changes it
            // even when the counts happen to match.
            $table->json('source_snapshot')->nullable()->after('retrieval_version');

            // Which retrievers actually contributed, and how many passages each supplied — the
            // fastest way to see that an answer regressed because a source went dark.
            $table->json('retrieval_summary')->nullable()->after('source_snapshot');

            // The confidence vector this run produced, kept per-dimension rather than blended so a
            // historical score can be re-explained under today's weights.
            $table->json('confidence_breakdown')->nullable()->after('retrieval_summary');

            // Vehicle scope of the run: '*' for universal, or a VehicleScope key.
            $table->string('scope_key', 120)->default('*')->after('confidence_breakdown');

            $table->index(['prompt_version', 'ontology_version'], 'enrichment_runs_versions_idx');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_enrichment_runs', function (Blueprint $table) {
            $table->dropIndex('enrichment_runs_versions_idx');
            $table->dropColumn([
                'research_provider', 'research_model', 'extraction_provider', 'extraction_model',
                'embedding_model', 'prompt_version', 'ontology_version', 'retrieval_version',
                'source_snapshot', 'retrieval_summary', 'confidence_breakdown', 'scope_key',
            ]);
        });
    }
};
