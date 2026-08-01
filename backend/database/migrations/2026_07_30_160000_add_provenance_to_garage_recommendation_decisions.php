<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHICH engine, under WHICH rules, against WHICH data made each decision.
 *
 * A recommendation is a judgement produced by a specific engine build, a specific set of policy rules
 * and a corpus as it stood on a specific day. All three move independently. Without this, "why did we
 * send that car there in March?" is unanswerable: re-running today's engine answers a different
 * question, and a quiet weight change six months ago is invisible.
 *
 * `config_fingerprint` is computed from the tuning arrays rather than declared, so a decision made under
 * edited weights can never masquerade as one made under the published policy version — the version label
 * says what someone remembered to bump, the fingerprint says what actually ran.
 *
 * See config/garage_recommendation.php (engine_version / policy_version) and
 * GarageRecommendationService::provenance().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->string('engine_version', 40)->nullable()->after('actor_id')->index();
            $table->string('policy_version', 40)->nullable()->after('engine_version');
            $table->string('config_fingerprint', 16)->nullable()->after('policy_version');
            $table->date('data_snapshot')->nullable()->after('config_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('garage_recommendation_decisions', function (Blueprint $table) {
            $table->dropColumn(['engine_version', 'policy_version', 'config_fingerprint', 'data_snapshot']);
        });
    }
};
