<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make the garage decision's EXPLANATION durable, not just its verdict.
 *
 * `score` already stored the engine's internal weighted number — which is not what the Supervisor saw or
 * acted on. They saw a 0–100 match score and a five-factor breakdown, and on a multi-fault ticket they saw
 * a single-vs-split recommendation. Reconstructing any of that later is impossible: the history dataset
 * moves under us (new repairs land daily), so re-running the engine next month answers a different
 * question than the one that was asked at dispatch.
 *
 * So we snapshot what was actually on screen:
 *   match_score — the 0–100 the operator acted on (the internal `score` stays for engine diagnostics)
 *   breakdown   — the five components with their awarded/max and the facts behind them
 *   strategy    — the one-garage-vs-split call, its reason and its stated trade-off
 *
 * All nullable and additive, so old rows stay valid and a decision recorded without a recommendation
 * (a straight manual pick) is unaffected. See [[garage-recommendation-engine]] and the standing
 * [[traceability-visibility-requirement]] rule: no black boxes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->unsignedTinyInteger('match_score')->nullable()->after('score');
            $table->json('breakdown')->nullable()->after('reasons');
            $table->json('strategy')->nullable()->after('breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->dropColumn(['match_score', 'breakdown', 'strategy']);
        });
    }
};
