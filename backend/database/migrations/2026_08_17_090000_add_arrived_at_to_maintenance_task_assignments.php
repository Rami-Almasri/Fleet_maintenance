<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN THE CAR ACTUALLY REACHED THIS GARAGE.
 *
 * A stint already records when a fault was SENT to a garage (`assigned_at`) and when it left
 * (`released_at`). What it never recorded is when the car physically ARRIVED there — and on a transfer
 * those are not the same moment: the fault is re-pointed at the new garage the instant the supervisor
 * decides, then the car is collected and driven across town.
 *
 * The arrival IS captured today, but only on the ticket (`maintenances.repair_started_at`), which is a
 * single column re-stamped at every check-in — so the moment garage B confirms arrival, garage A's
 * arrival is gone. That makes two real questions unanswerable after the fact:
 *
 *   - how long was the car AT this garage (rather than in custody, drive included)?
 *   - how long did the move between two garages actually take?
 *
 * This column stamps the arrival per stint, so both are answerable per garage instead of only for the
 * last one. Nullable and never backfilled: rows written before this migration have no arrival on record,
 * and every reader must say "measured from dispatch" for those rather than quietly presenting a custody
 * figure as garage time.
 *
 * dateTime(), not timestamp() — on MariaDB the first `timestamp` column in a table silently gains
 * ON UPDATE CURRENT_TIMESTAMP, which would rewrite the arrival on every later save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_task_assignments', function (Blueprint $table) {
            $table->dateTime('arrived_at')->nullable()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_task_assignments', function (Blueprint $table) {
            $table->dropColumn('arrived_at');
        });
    }
};
