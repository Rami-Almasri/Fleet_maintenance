<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Responsibility tag for type-'U' MAINTENANCE contracts, copied from the Google-Sheet workshop log's
 * `base_on` column (the sheet records who a workshop visit was "based on" — a driver name, or
 * 'Customer' when the customer caused it). The OM contract payload has no such field, so it is
 * back-filled by matching each type-'U' contract to its sheet maintenance row (same vehicle, OUT date
 * within a few days) — see the `contracts:link-baseon` command.
 *
 * Fleet Utilization uses it to keep downtime honest: a maintenance visit with base_on='Customer' is
 * the customer's responsibility (e.g. they damaged the car), so it is NOT counted as company downtime
 * / dead capacity. Only 'Customer' changes behaviour; null / driver-name values count as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('base_on')->nullable()->after('reference')->index();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['base_on']);
            $table->dropColumn('base_on');
        });
    }
};
