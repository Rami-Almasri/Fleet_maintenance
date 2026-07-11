<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odometer reading captured by the Inspector (Abu Maroof) at the VERY START of the test drive —
 * the first, anchoring link in the maintenance mileage chain:
 *
 *   test_odometer  →  dispatch_odometer  →  receive_odometer  →  return_odometer
 *   (start drive)     (leaves for garage)   (garage intake)      (back from garage)
 *
 * Two uses (see MaintenanceWorkflowService::applyTestOdometer):
 *   1. Test-drive distance = dispatch_odometer − test_odometer (measures how thoroughly the car
 *      was actually driven during the inspection).
 *   2. Canonical current reading — heals the vehicle's live odometer forward, like a contract
 *      handover does ([[global-mileage-baseline]]).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->unsignedInteger('test_odometer')->nullable()->after('findings');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('test_odometer');
        });
    }
};
