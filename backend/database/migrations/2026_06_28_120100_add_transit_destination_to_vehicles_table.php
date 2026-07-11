<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A denormalised mirror of the car's CURRENT dispatch destination, set while operational_status is
 * 'in_transit'. Like operational_status itself (which mirrors the open contract for fast lookups), this
 * lets the vehicle grid render "In Transit to Deals on Wheels" from the vehicle row alone — no join to
 * the logistics_tasks table per row. Set on dispatch, cleared when the task is delivered / cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('transit_destination')->nullable()->after('operational_status');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('transit_destination');
        });
    }
};
