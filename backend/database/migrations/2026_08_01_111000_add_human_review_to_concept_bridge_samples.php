<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which samples we actually ask a human to answer.
 *
 * The full frozen set is 210 rows; a technician is asked for only ~90 of them — the strata where
 * human judgement changes a decision (the 60–79 score band, action-vs-fault, ambiguity, vocabulary
 * gaps). The remaining rows keep their AI baseline label and are reported as Track C.
 *
 * This is a FLAG rather than a separate sample set on purpose: one row per question means the
 * AI-vs-human agreement in Track B compares answers to the *same* questions, which is the only way
 * that comparison means anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concept_bridge_samples', function (Blueprint $table) {
            $table->boolean('human_review')->default(false)->after('stratum')->index();
        });
    }

    public function down(): void
    {
        Schema::table('concept_bridge_samples', function (Blueprint $table) {
            $table->dropColumn('human_review');
        });
    }
};
