<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sheet-sourced oil-change/service fields on `vehicles`. These are SEPARATE from the
 * API's service_due_date/service_due_km (ServiceExpiryDate/ServiceExpiryMilage), which
 * are intentionally NOT used for the service-due calculation.
 *
 * The "Oil Change" sheet tab is the ONLY source of truth for the per-car interval and the
 * last-service baseline; current mileage (odometer) still comes from the API. Service-due
 * is computed strictly in km: (odometer - last_service_odometer) >= service_interval_km.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Odometer reading at the last oil change (Oil Change sheet "LAST CHANGE").
            $table->unsignedInteger('last_service_odometer')->nullable()->after('service_due_km');
            // Per-car service interval in km (Oil Change sheet "VALIDITY").
            $table->unsignedInteger('service_interval_km')->nullable()->after('last_service_odometer');
            // When the Oil Change sheet last matched/updated this car (null = never matched).
            $table->timestamp('service_synced_at')->nullable()->after('service_interval_km');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['last_service_odometer', 'service_interval_km', 'service_synced_at']);
        });
    }
};
