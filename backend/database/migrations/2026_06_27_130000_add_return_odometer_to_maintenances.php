<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final odometer reading captured when a workflow ticket is marked READY (UC-5) — the car's
 * mileage as it leaves the garage, the counterpart to `dispatch_odometer` (captured on the way
 * in). Mandatory at the "Mark ready" step so the fleet always knows the post-repair odometer
 * before the Inspector signs the car back into service. Additive + nullable (legacy rows unaffected).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->unsignedInteger('return_odometer')->nullable()->after('dispatch_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('return_odometer');
        });
    }
};
