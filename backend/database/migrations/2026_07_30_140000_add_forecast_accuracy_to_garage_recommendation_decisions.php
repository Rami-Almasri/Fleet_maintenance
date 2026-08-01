<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the loop: store what actually happened next to what we predicted.
 *
 * `expected_outcomes` already records the forecast the supervisor acted on. These columns record the
 * verdict on it — what the repair actually did (`actual_outcomes`) and how far off each figure was
 * (`forecast_accuracy`, carrying predicted/actual/error/accuracy per measure). `scored_at` marks a
 * decision as already judged so the scorer is idempotent and can run on a schedule.
 *
 * Deliberately stored per DECISION rather than aggregated: an average accuracy tells you the engine is
 * 91% right, but only the individual rows tell you WHICH garages it is wrong about and in which
 * direction — and the direction is the part anyone can act on.
 *
 * See App\Services\Garage\ForecastCalibration and [[garage-recommendation-engine]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->json('actual_outcomes')->nullable()->after('fault_criticality');
            $table->json('forecast_accuracy')->nullable()->after('actual_outcomes');
            $table->timestamp('scored_at')->nullable()->after('forecast_accuracy')->index();
        });
    }

    public function down(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->dropColumn(['actual_outcomes', 'forecast_accuracy', 'scored_at']);
        });
    }
};
