<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the FORECAST the supervisor was shown, next to the decision they made on it.
 *
 * The recommendation now predicts what will happen — turnaround, comeback risk, cost, when the garage
 * can start. Those are claims, and a claim nobody records is a claim nobody can be held to. Storing the
 * forecast at dispatch turns it into something checkable: once the ticket closes, actual turnaround and
 * actual recurrence can be compared against what was promised, which is the only way the forecaster
 * ever gets better (and the only way we find out when it is confidently wrong).
 *
 * `expected_outcomes` holds the chosen garage's forecast INCLUDING each figure's basis and sample, so a
 * later audit can tell a garage-specific prediction from a fleet median that was standing in for one —
 * scoring those two the same way would make the accuracy numbers meaningless.
 *
 * `fault_criticality` records how the ticket's faults were weighted, because the same garage can be the
 * right or wrong call depending on which fault was allowed to dominate.
 *
 * Both nullable and additive. See [[garage-recommendation-engine]] and [[evidence-layer-governance]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->json('expected_outcomes')->nullable()->after('strategy');
            $table->json('fault_criticality')->nullable()->after('expected_outcomes');
        });
    }

    public function down(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->dropColumn(['expected_outcomes', 'fault_criticality']);
        });
    }
};
