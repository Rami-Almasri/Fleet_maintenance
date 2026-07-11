<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a hand-editable `vehicle_class` to vehicles — the specialisation axis the Smart Routing Engine
 * uses to prefer, say, a luxury-SUV specialist over a standard-fleet garage.
 *
 * Deliberately a free-ish slug (validated against config('garage_routing.vehicle_classes') at write
 * time, NOT a DB enum) so the manager can add / rename classes without a migration. Nullable: an
 * unclassified car falls back to config('garage_routing.default_vehicle_class') in the engine, so
 * routing never breaks on a blank. Indexed because the router filters/joins garages by class.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('vehicle_class', 40)->nullable()->index()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['vehicle_class']);
            $table->dropColumn('vehicle_class');
        });
    }
};
