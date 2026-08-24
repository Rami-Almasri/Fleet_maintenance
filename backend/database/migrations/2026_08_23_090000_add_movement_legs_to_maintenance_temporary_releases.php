<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary Vehicle Release, rev. 2 — the release is no longer a bare "someone drove off with it" note;
 * it is a REAL round trip that moves through the same dispatch lanes as a garage run:
 *
 *   Needs Dispatch → Awaiting Pickup → En Route → (parked at the destination) Returned — Resume Due
 *   → Needs Dispatch → Awaiting Pickup → En Route → back in the workshop
 *
 * The out leg sends the car OUT of the garage to a plain location (a parking yard, the office, a
 * showroom); the return leg brings it back to a GARAGE — by default the very garage it left, which the
 * supervisor may change at the return dispatch. Throughout, the ticket's workflow_status is untouched
 * (the repair stays frozen at its stage, the faults stay exactly as they were, the car stays counted as
 * in-maintenance) — the release row alone carries the movement. See [[temporary-vehicle-release-feature]]
 * and [[logistics-dispatch-canonical]] (each physical leg still raises a LogisticsTask).
 *
 * `odometer_out` becomes NULLABLE: the reading is now captured when the driver PHYSICALLY collects the
 * car from the garage (the pickup step), not at the moment the controller decides to let it go — between
 * the decision and the pickup the car hasn't moved, so an odometer taken at the decision would be a
 * guess. Rows written before this migration all carry a reading already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_temporary_releases', function (Blueprint $table) {
            // Where the release currently stands — one of MaintenanceTemporaryRelease::STAGES. The DEFAULT
            // is the first stage, because that is where a release begins; rows already open when this
            // migration ran are moved to 'at_destination' below, which is the only honest place for them.
            $table->string('stage', 32)->default('out_dispatch')->after('reason_note');

            // WHERE the car goes while it is out — free text with quick-picks
            // (LogisticsTask::COMMON_DESTINATIONS). Chosen at the out dispatch, not at the release decision.
            $table->string('destination', 160)->nullable()->after('stage');

            // The garage the car was sitting in when it was released — the return leg's default
            // destination, so "bring it back" needs no one to remember where it came from.
            $table->unsignedBigInteger('vendor_id_snapshot')->nullable()->after('workflow_status_snapshot');
            $table->string('garage_snapshot', 190)->nullable()->after('vendor_id_snapshot');

            // The garage the RETURN leg is actually heading to. Defaults to the snapshot above; a
            // supervisor may point it at a different shop at the return dispatch (the repair moves with
            // the car — the ticket's vendor is re-pointed on arrival).
            $table->unsignedBigInteger('return_vendor_id')->nullable()->after('garage_snapshot');
            $table->string('return_garage', 190)->nullable()->after('return_vendor_id');

            // Who drives each leg (nullable = the leg is open to the whole driver pool).
            $table->unsignedBigInteger('out_driver_id')->nullable()->after('return_garage');
            $table->unsignedBigInteger('return_driver_id')->nullable()->after('out_driver_id');

            // The moments each leg turned over. dateTime(), never timestamp() — MariaDB silently attaches
            // ON UPDATE CURRENT_TIMESTAMP to a nullable timestamp column and rewrites stored moments.
            $table->dateTime('out_assigned_at')->nullable()->after('return_driver_id');
            $table->dateTime('out_started_at')->nullable()->after('out_assigned_at');
            $table->dateTime('arrived_at')->nullable()->after('out_started_at');
            $table->dateTime('return_requested_at')->nullable()->after('arrived_at');
            $table->dateTime('return_assigned_at')->nullable()->after('return_requested_at');
            $table->dateTime('return_started_at')->nullable()->after('return_assigned_at');

            $table->index('stage');
        });

        // The out reading is captured at the pickup now — see the class docblock.
        Schema::table('maintenance_temporary_releases', function (Blueprint $table) {
            $table->unsignedInteger('odometer_out')->nullable()->change();
        });

        // Releases already OPEN when this shipped were raised under rev. 1: the car is physically out,
        // nobody recorded a destination, and the only thing left to do is bring it back — which is
        // exactly what 'at_destination' means. Anything else would put a car that is already gone into a
        // lane that asks someone to drive it away again. Closed rows keep the default; they are history
        // and their stage is never read (stageLabel() answers "Completed"/"Cancelled" off returned_at).
        DB::table('maintenance_temporary_releases')
            ->whereNull('returned_at')
            ->update(['stage' => 'at_destination']);

        // …and give those legacy rows the garage the car left, so "bring it back" knows where back is.
        // Their ticket still carries it: nothing about the repair moved while the car was out.
        DB::statement("
            UPDATE maintenance_temporary_releases r
            JOIN maintenances m ON m.id = r.maintenance_id
            LEFT JOIN vendors v ON v.id = m.vendor_id
            SET r.vendor_id_snapshot = m.vendor_id,
                r.garage_snapshot    = COALESCE(v.name, m.garage)
            WHERE r.returned_at IS NULL AND r.vendor_id_snapshot IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('maintenance_temporary_releases', function (Blueprint $table) {
            $table->dropIndex(['stage']);
            $table->dropColumn([
                'stage', 'destination', 'vendor_id_snapshot', 'garage_snapshot',
                'return_vendor_id', 'return_garage', 'out_driver_id', 'return_driver_id',
                'out_assigned_at', 'out_started_at', 'arrived_at',
                'return_requested_at', 'return_assigned_at', 'return_started_at',
            ]);
        });
    }
};
