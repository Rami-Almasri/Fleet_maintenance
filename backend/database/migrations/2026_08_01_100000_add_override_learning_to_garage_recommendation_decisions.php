<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the loop: record not just THAT a supervisor overrode the recommendation, but why — and where the
 * garage they actually chose stood in the engine's own ranking.
 *
 * `followed` alone cannot be learned from. It says a human disagreed, and nothing about whether the
 * engine was wrong. An override is only informative next to three things: the stated reason, the size of
 * the score gap the supervisor was willing to accept, and what the chosen garage was MEASURABLY better
 * at. With those, "supervisors keep overriding us" becomes a testable claim — either they are trading
 * score for something we already measure and underweight, or for something we do not measure at all,
 * and those need opposite responses.
 *
 * ⚠️ An override is NOT an error. Most will be legitimate operational judgement — a customer request, a
 * standing relationship, a garage that can take the car today. The columns are deliberately neutral;
 * nothing here is named "mistake" or "violation", because the moment the log implies fault, supervisors
 * start picking whichever reason ends the argument and the data stops meaning anything.
 *
 * See [[garage-recommendation-engine]] and [[explainability-platform]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            // The supervisor's own account of the trade, from a fixed taxonomy so it can be counted.
            $table->string('override_reason', 40)->nullable()->after('followed');
            $table->text('override_note')->nullable()->after('override_reason');

            // Where the chosen garage actually stood. Rank 2 with a 4-point gap is a near-tie the engine
            // arguably got wrong; rank 9 with a 40-point gap is a decision made on grounds we never saw.
            $table->unsignedSmallInteger('chosen_rank')->nullable()->after('rank');
            $table->unsignedTinyInteger('chosen_match_score')->nullable()->after('match_score');
            $table->smallInteger('score_gap')->nullable()->after('chosen_match_score');

            // What the chosen garage was MEASURABLY better at — cheaper, faster, freer, holds repairs.
            // This is what makes the stated reason checkable: "lower cost" against a garage that was not
            // cheaper in our data is a signal about the data or the perception, not about the weights.
            $table->json('chosen_advantages')->nullable()->after('score_gap');
        });

        // The learning queries are "all decisions since X, grouped by reason" and "acceptance over time".
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->index(['followed', 'created_at'], 'grd_followed_created_index');
            $table->index('override_reason', 'grd_override_reason_index');
        });
    }

    public function down(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->dropIndex('grd_followed_created_index');
            $table->dropIndex('grd_override_reason_index');
            $table->dropColumn([
                'override_reason', 'override_note', 'chosen_rank',
                'chosen_match_score', 'score_gap', 'chosen_advantages',
            ]);
        });
    }
};
