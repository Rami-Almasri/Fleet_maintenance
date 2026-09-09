<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a curator's edit survive the next deploy.
 *
 * FaultCatalogSeeder upserts by `slug` and rewrites name, name_ar, category_key, default_severity,
 * on_site and sort_order every time it runs — which is every container start. Without this flag, a
 * fault renamed on the admin page would be silently reverted by the next deploy, and the curator would
 * have no way of knowing why. The same read-config/write-DB trade `vehicle_locations` and
 * `component_catalog` already make.
 *
 * Rows created IN the app are stamped too: the config file has no entry to re-assert over them, but
 * the flag is also what tells the seeder (and a human reading the table) that the authored file is not
 * where this row came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fault_catalog', function (Blueprint $table) {
            $table->boolean('edited_in_app')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('fault_catalog', function (Blueprint $table) {
            $table->dropColumn('edited_in_app');
        });
    }
};
