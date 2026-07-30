<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyword ENRICHMENT RUNS — one row per AI call against one keyword.
 *
 * The keyword database is now partly machine-written, which makes the question "where did this
 * come from?" a first-class one ([[traceability-visibility-requirement]]). This is the answer: every
 * attempt is logged whether it succeeded or not, with the model used, the token cost, how many
 * terms it added versus updated, how long it took, and the raw error when it failed.
 *
 * It also gives the batch command something to be idempotent against — `keywords:enrich --stale`
 * only re-runs concepts whose last SUCCESSFUL run is older than N days, so a nightly job is cheap
 * and a re-run after a crash resumes rather than re-billing the whole library.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_enrichment_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('finding_keyword_id')->constrained()->cascadeOnDelete();

            // pending | success | failed. Written 'pending' before the call so a hard crash mid-call
            // still leaves evidence that the attempt happened.
            $table->string('status', 12)->default('pending')->index();

            $table->string('model', 60)->nullable();

            // What the run actually changed — the headline the admin screen shows per run.
            $table->unsignedSmallInteger('terms_added')->default(0);
            $table->unsignedSmallInteger('terms_updated')->default(0);
            $table->boolean('profile_written')->default(false);

            // Cost + latency, straight off the API response usage block.
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);

            // Populated only on failure — the exception message, truncated.
            $table->string('error', 500)->nullable();

            // Who triggered it. Null = the scheduled/CLI batch rather than a person clicking Enrich.
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The `--stale` query: newest successful run per keyword.
            $table->index(['finding_keyword_id', 'status', 'created_at'], 'keyword_runs_concept_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_enrichment_runs');
    }
};
