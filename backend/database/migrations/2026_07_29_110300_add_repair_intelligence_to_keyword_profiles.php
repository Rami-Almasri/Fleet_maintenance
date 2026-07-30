<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPAIR INTELLIGENCE — what fixing this fault actually costs in time, skill and money.
 *
 * The V1 profile said what a fault IS. This says what dealing with it TAKES, which is the input
 * every future maintenance recommendation needs: how long to book the bay for, whether the in-house
 * team can do it or it must go out, what tools it needs, and what order to inspect in.
 *
 * TWO NUMBERS PER FIELD, ON PURPOSE. Each estimate has an AI column and a FLEET column:
 *  - `labor_hours_min/max`   — what professional documentation says the job takes.
 *  - `fleet_labor_hours_avg` — what it has actually taken US, measured from closed tickets.
 * They are never merged. Book time from documentation, forecast from your own history, and when
 * they diverge that gap is itself the finding ("this job takes our team twice the book time").
 *
 * MONEY IS GATED, NOT HIDDEN. `cost_min` / `cost_max` are stored because a repair estimate is
 * incomplete without them, but they must be rendered behind the SHOW_FINANCIALS flag like every
 * other money surface in the app ([[financial-decoupling-flag]]) — the resource ships them only
 * when that flag is on.
 *
 * `inspection_order` is the one field that is not an estimate: it is the ordered diagnostic path
 * (cheapest/most-likely check first), which is what turns a list of possible causes into a
 * procedure a technician can follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so the migration is re-runnable. MySQL DDL is non-transactional, so a failure
        // part-way through this migration leaves the earlier statements committed — see
        // [[mariadb-local-mysql8-prod]]. Without the guard, fixing and re-running would then die on
        // "column already exists" instead of resuming.
        if (Schema::hasColumn('keyword_profiles', 'complexity')) {
            $this->swapUniqueIndex();

            return;
        }

        Schema::table('keyword_profiles', function (Blueprint $table) {
            // How hard the job is: trivial | routine | moderate | complex | specialist.
            // `specialist` is the operationally important one — it means this cannot be done
            // in-house and the ticket should be routed out from the start.
            $table->string('complexity', 20)->nullable()->after('repair_discipline');

            // Book time from documentation (a range, because vehicles differ).
            $table->decimal('labor_hours_min', 5, 2)->nullable()->after('complexity');
            $table->decimal('labor_hours_max', 5, 2)->nullable()->after('labor_hours_min');

            // Measured from our own closed tickets — see [[FleetEvidenceService]]. Null until
            // there is enough history to be worth trusting.
            $table->decimal('fleet_labor_hours_avg', 5, 2)->nullable()->after('labor_hours_max');
            $table->unsignedInteger('fleet_case_count')->default(0)->after('fleet_labor_hours_avg');

            // What the job needs. JSON arrays of short strings for the same reason as the V1
            // knowledge lists: read as a block, never joined on.
            $table->json('required_tools')->nullable()->after('fleet_case_count');
            $table->json('required_skills')->nullable()->after('required_tools');

            // The ordered diagnostic path — check this, then this, then this.
            $table->json('inspection_order')->nullable()->after('required_skills');

            // Estimated parts+labour band. Gated behind SHOW_FINANCIALS at the resource layer.
            $table->decimal('cost_min', 10, 2)->nullable()->after('inspection_order');
            $table->decimal('cost_max', 10, 2)->nullable()->after('cost_min');
            $table->string('cost_currency', 3)->default('AED')->after('cost_max');

            // How much of this profile is backed by retrieved documentation rather than the model's
            // own prior — the headline honesty number for the whole entry.
            $table->unsignedTinyInteger('grounding_score')->default(0)->after('confidence');

            // Vehicle scope of this profile. Null = the universal profile for the fault; a scoped
            // row is a manufacturer-specific override. Unique index is added below.
            $table->string('make', 60)->nullable()->after('grounding_score');
            $table->string('model_name', 60)->nullable()->after('make');
            $table->string('generation', 60)->nullable()->after('model_name');
            $table->string('scope_key', 120)->default('*')->after('generation');
        });

        $this->swapUniqueIndex();
    }

    /**
     * Widen the 1:1 unique on finding_keyword_id to 1-per-scope, so a fault can carry a universal
     * profile PLUS a Toyota-specific and a BMW-specific one.
     *
     * MySQL refuses to drop an index a foreign key is using ("needed in a foreign key constraint"),
     * so the FK has to come off first and go back on afterwards. The composite index is created
     * before the FK is restored so InnoDB has an index to attach it to throughout.
     */
    private function swapUniqueIndex(): void
    {
        $indexes = collect(Schema::getIndexes('keyword_profiles'))->pluck('name');

        if ($indexes->contains('keyword_profiles_concept_scope_unique')) {
            return;     // already swapped
        }

        Schema::table('keyword_profiles', function (Blueprint $table) {
            $table->dropForeign(['finding_keyword_id']);
        });

        Schema::table('keyword_profiles', function (Blueprint $table) {
            $table->dropUnique('keyword_profiles_finding_keyword_id_unique');
            $table->unique(['finding_keyword_id', 'scope_key'], 'keyword_profiles_concept_scope_unique');
        });

        Schema::table('keyword_profiles', function (Blueprint $table) {
            $table->foreign('finding_keyword_id')->references('id')->on('finding_keywords')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('keyword_profiles', function (Blueprint $table) {
            $table->dropForeign(['finding_keyword_id']);
        });

        Schema::table('keyword_profiles', function (Blueprint $table) {
            $table->dropUnique('keyword_profiles_concept_scope_unique');
            $table->unique('finding_keyword_id');
        });

        Schema::table('keyword_profiles', function (Blueprint $table) {
            $table->foreign('finding_keyword_id')->references('id')->on('finding_keywords')->cascadeOnDelete();
        });

        Schema::table('keyword_profiles', function (Blueprint $table) {

            $table->dropColumn([
                'complexity', 'labor_hours_min', 'labor_hours_max',
                'fleet_labor_hours_avg', 'fleet_case_count',
                'required_tools', 'required_skills', 'inspection_order',
                'cost_min', 'cost_max', 'cost_currency',
                'grounding_score', 'make', 'model_name', 'generation', 'scope_key',
            ]);
        });
    }
};
