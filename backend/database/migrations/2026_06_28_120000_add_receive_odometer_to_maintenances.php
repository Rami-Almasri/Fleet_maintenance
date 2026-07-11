<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odometer reading captured when the garage confirms receipt of the car (UC-4 / Mark received).
 * Sits between dispatch_odometer (car leaves fleet) and return_odometer (car comes back),
 * giving a complete mileage trail: fleet → garage intake → fleet return.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->unsignedInteger('receive_odometer')->nullable()->after('dispatch_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('receive_odometer');
        });
    }
};
