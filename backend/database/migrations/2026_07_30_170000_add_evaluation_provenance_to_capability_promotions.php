<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full provenance on every promotion decision: what was compared, against what data, by what method.
 *
 * `capability_version` and `query_layer_version` were already recorded — enough to know WHICH
 * capability decided, but not enough to reconstruct WHY. A decision is a claim about two models
 * evaluated on a dataset by a method, and all three move independently:
 *
 *   · the operating point can be retuned (14-day window → 30-day)
 *   · the projection can be rebuilt (a classifier change relabels 49,501 rows)
 *   · the backtest methodology can change (a different forward window, a different exclusion rule)
 *
 * Without these columns, a refusal recorded today and a promotion recorded next quarter would be
 * indistinguishable from a change of mind — when in fact they may be two correct answers to two
 * different questions. With them, "why was this refused in July?" is answerable in full, and a
 * decision can be invalidated deliberately when the thing it rested on moves.
 *
 * Nullable: decisions written before this migration genuinely lack the provenance, and backfilling
 * a guess would be the rewriting of history the append-only guards exist to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capability_promotions', function (Blueprint $table) {
            // The two models compared — capability version PLUS the operating point that defines the
            // firing rule, because the same v2 capability tuned differently is a different model.
            $table->string('proxy_model_version', 120)->nullable()->after('to_basis');
            $table->string('measured_model_version', 120)->nullable()->after('proxy_model_version');

            // The corpus as it stood: classifier version, row count, last observation. A projection
            // rebuild changes the answer without changing a line of code.
            $table->string('dataset_version', 120)->nullable()->after('query_layer_version');

            // The methodology — forward window, exclusion rules, sufficiency floor.
            $table->string('backtest_version', 20)->nullable()->after('dataset_version');

            // The exact rule applied, in words, so the rationale survives a change to the rule.
            $table->string('decision_rule', 255)->nullable()->after('reason');

            // The concrete parameters behind the model version strings.
            $table->json('operating_point')->nullable()->after('measured_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('capability_promotions', function (Blueprint $table) {
            $table->dropColumn([
                'proxy_model_version', 'measured_model_version',
                'dataset_version', 'backtest_version', 'decision_rule', 'operating_point',
            ]);
        });
    }
};
