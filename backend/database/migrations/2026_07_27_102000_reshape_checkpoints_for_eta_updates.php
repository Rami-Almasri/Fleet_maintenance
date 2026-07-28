<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance Checkpoint — reshape from a manual "Progress outcome" verdict into a pure ETA update.
 *
 * A checkpoint no longer asks a human to classify the job (On Track / Delayed / Critical); the dashboard
 * DERIVES that from the promised completion date (today ≤ ETA → On Schedule, today > ETA → Overdue, plus
 * the workflow's own Ready-for-Pickup / Completed states). Each update now captures the ETA change itself:
 *   - previous_expected_date : the ETA in force BEFORE this update (audit trail of every extension)
 *   - next_expected_date     : the new ETA (now required at submit — already existed)
 *   - delay_reason(+_other)  : WHY the ETA moved (now required only when the date actually changes)
 *
 * So we drop `outcome` and add `previous_expected_date`. See [[maintenance-checkpoint-feature]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_checkpoints', function (Blueprint $table) {
            // The ETA in force before this update — pairs with next_expected_date so the timeline can show
            // "Previous ETA → New ETA" for every extension.
            $table->date('previous_expected_date')->nullable()->after('summary');
        });

        // Drop the retired management verdict. Guarded so re-runs / fresh installs don't fail.
        if (Schema::hasColumn('maintenance_checkpoints', 'outcome')) {
            Schema::table('maintenance_checkpoints', function (Blueprint $table) {
                $table->dropColumn('outcome');
            });
        }
    }

    public function down(): void
    {
        Schema::table('maintenance_checkpoints', function (Blueprint $table) {
            $table->dropColumn('previous_expected_date');
        });

        if (! Schema::hasColumn('maintenance_checkpoints', 'outcome')) {
            Schema::table('maintenance_checkpoints', function (Blueprint $table) {
                $table->string('outcome', 20)->nullable();
            });
        }
    }
};
