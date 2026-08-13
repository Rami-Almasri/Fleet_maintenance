<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "A human curated this row" — the flag that stops a deploy from undoing an admin's work.
 *
 * `vehicle_locations` is seeded from config on every deploy with an upsert by slug, which was right
 * while the vocabulary was authored in code only. Now that the Vehicle Locations admin page can
 * rename a place, move it between sections, re-grade its precision and add aliases, that same upsert
 * would revert all of it the next time the seeder ran.
 *
 * So a row edited in the app says so, and VehicleLocationSeeder skips it. This is the same trade
 * `component_catalog.edited_in_app` already makes: config is the authored starting point, the DB is
 * what runs, and a person always outranks the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicle_locations') && ! Schema::hasColumn('vehicle_locations', 'edited_in_app')) {
            Schema::table('vehicle_locations', function (Blueprint $table) {
                $table->boolean('edited_in_app')->default(false)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicle_locations') && Schema::hasColumn('vehicle_locations', 'edited_in_app')) {
            Schema::table('vehicle_locations', function (Blueprint $table) {
                $table->dropColumn('edited_in_app');
            });
        }
    }
};
