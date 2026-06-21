<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link to the OfficeManager API source: vehicles and contracts there are keyed by
 * CarSerial. We store it on our rows so API contracts can resolve to our cars and
 * re-syncs stay idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('car_serial')->nullable()->after('code')->index();
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('car_serial')->nullable()->after('vehicle_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('car_serial');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('car_serial');
        });
    }
};
