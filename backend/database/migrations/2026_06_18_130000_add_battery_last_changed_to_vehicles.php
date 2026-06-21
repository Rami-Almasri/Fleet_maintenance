<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Battery info from the OfficeManager API car card. The API only carries
 * `BatteryLastChanged` (a last-replacement date) — there is no battery type,
 * capacity, serial, or validity/interval counterpart like the other service items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // OM car-card "BatteryLastChanged" — date the battery was last replaced.
            $table->date('battery_last_changed')->nullable()->after('service_due_km');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('battery_last_changed');
        });
    }
};
