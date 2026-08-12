<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odometer provenance — say WHERE the current mileage came from.
 *
 * The live odometer is written by several hands (the OM API car card, the "Oil Change" sheet's
 * MILAGE column, contract handover readings, a closed service ticket, a manual edit). Until now
 * the number stood on the car card with no way to tell which hand last moved it, so nobody could
 * answer "is this the sheet's figure or OM's?" — the exact question the Traceability rule says
 * every page must be able to answer.
 *
 * `odometer_source`    — the writer that last RAISED the reading (see Vehicle::ODOMETER_SOURCES).
 * `odometer_source_at` — when that happened. dateTime(), not timestamp(): MariaDB silently pins
 *                        ON UPDATE CURRENT_TIMESTAMP to a nullable timestamp column, which would
 *                        re-stamp this on every unrelated vehicle save and make it lie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('odometer_source', 32)->nullable()->after('odometer');
            $table->dateTime('odometer_source_at')->nullable()->after('odometer_source');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['odometer_source', 'odometer_source_at']);
        });
    }
};
