<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary Vehicle Release — the car physically leaves the workshop MID-REPAIR (a road test, a
 * customer test/delivery, an external inspection, short-term storage, …) while the maintenance ticket
 * stays exactly where it is (e.g. under_repair). DISTINCT from Pause & Return to Service
 * (maintenance_handovers): a pause interrupts the repair and frees the car back into the rentable pool
 * (workflow_status → paused_returned_to_service); a temporary release does NOT change workflow_status,
 * does NOT free the car for rental, and does NOT close/complete the ticket — the same repair simply
 * continues when the car comes back. This table is the immutable log of each out→in round trip so the
 * odometer story stays complete (odometer_out → odometer_in → distance) and it's always clear WHO took
 * the car, WHY and WHEN.
 *
 * One row per release; `returned_at` (+ odometer_in/distance) is filled in on the return. While a row is
 * open (returned_at IS NULL) it is pointed at by maintenances.active_temporary_release_id.
 *
 * Loose unsignedBigInteger links (no FK constraint) — mirrors maintenance_handovers / maintenance_media.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_temporary_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id')->index();
            $table->unsignedBigInteger('vehicle_id')->index();

            // Why the car left the shop — one of MaintenanceTemporaryRelease::REASONS
            // (road_test | customer_test | external_inspection | other). `reason_note` carries the free-text
            // detail (mandatory when reason = other).
            $table->string('reason', 30);
            $table->text('reason_note')->nullable();

            // WHO physically took the car — a free-text name (may be an external party, not a system user),
            // and the system user who LOGGED the release.
            $table->string('taken_by', 120);
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamp('released_at');
            $table->unsignedInteger('odometer_out');
            // The workflow_status the ticket held when the car left — pure audit (the ticket keeps this
            // status the whole time the car is out).
            $table->string('workflow_status_snapshot', 40)->nullable();

            // Filled in on return. distance_km = odometer_in − odometer_out (the mileage accrued while out).
            $table->timestamp('returned_at')->nullable();
            $table->unsignedBigInteger('returned_by')->nullable();
            $table->unsignedInteger('odometer_in')->nullable();
            $table->integer('distance_km')->nullable();
            $table->text('return_note')->nullable();

            $table->timestamps();
        });

        Schema::table('maintenances', function (Blueprint $table) {
            // The currently-open temporary release (returned_at IS NULL), if the car is out right now.
            // Null the rest of the time. Mirrors active_incident_id — a cheap "is the car temporarily
            // released?" flag the resource / livePosition read without a sub-query.
            $table->unsignedBigInteger('active_temporary_release_id')->nullable()->after('last_resume_handover_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('active_temporary_release_id');
        });
        Schema::dropIfExists('maintenance_temporary_releases');
    }
};
