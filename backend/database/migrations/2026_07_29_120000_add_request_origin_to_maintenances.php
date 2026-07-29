<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQUEST ORIGIN — WHERE a maintenance ticket came from, stored separately from WHY the car needs
 * attention (`trigger_reason`).
 *
 * The two were previously conflated: escalating a Driver Observation wrote trigger_reason = 'periodic',
 * so the review queue showed a driver's note as "Routine (system)" and the true source was lost. Origin
 * is now its own column, so "how many issues did drivers find vs. inspectors vs. the scheduler?" is a
 * plain GROUP BY instead of an inference. See [[driver-observation-entity]].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('request_origin', 30)->nullable()->after('trigger_reason')->index();
        });

        // Backfill — infer the origin of every EXISTING ticket from the strongest signal available.
        // Ordered weakest → strongest so the more certain rules overwrite the general ones.

        // Default for any workflow ticket: an inspector-opened diagnostic.
        DB::table('maintenances')->whereNotNull('workflow_status')->update(['request_origin' => 'inspector']);

        // A periodic ticket with no human requester is the mileage scanner / service intake.
        DB::table('maintenances')
            ->whereNotNull('workflow_status')
            ->where('trigger_reason', 'periodic')
            ->whereNull('requested_by')
            ->update(['request_origin' => 'system_schedule']);

        // Customer complaints — whether raised through the Complaint entity or the legacy intake.
        DB::table('maintenances')
            ->whereNotNull('workflow_status')
            ->where('trigger_reason', 'customer_reported')
            ->update(['request_origin' => 'customer']);

        // Breakdown intake — reported from the workshop floor, never through a diagnostic drive.
        DB::table('maintenances')
            ->whereNotNull('workflow_status')
            ->where('trigger_reason', 'breakdown')
            ->update(['request_origin' => 'workshop']);

        // Strongest signal of all: the observation table already records which ticket it spawned, so
        // these rows get their true origin back rather than the 'periodic' the old code stamped on them.
        if (Schema::hasTable('driver_observations')) {
            $ids = DB::table('driver_observations')
                ->whereNotNull('inspection_request_id')
                ->pluck('inspection_request_id')
                ->all();
            if ($ids) {
                DB::table('maintenances')->whereIn('id', $ids)->update([
                    'request_origin' => 'driver_observation',
                    // The reason was never really "scheduled service" — restore it to what it was:
                    // a fault a driver reported. Only touch the rows the old code mislabelled.
                    'trigger_reason' => 'driver_reported',
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex(['request_origin']);
            $table->dropColumn('request_origin');
        });
    }
};
