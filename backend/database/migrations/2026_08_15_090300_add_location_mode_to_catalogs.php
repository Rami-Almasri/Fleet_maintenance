<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Does this type of event even HAVE a where?" — asked of the catalog, answered once, per type.
 *
 * Overheating has no panel. A wiper fault's location is the wiper. A scratch without a place is a
 * scratch nobody can find again. Those are three different answers and they are properties of the
 * TYPE, so they live on the type — beside `default_severity` and `on_site`, which are the same shape
 * of prefill/policy hint the catalogs already carry.
 *
 *   required — the report is refused without at least one location
 *   optional — the picker is offered, may be left empty (the default: silence is honest)
 *   none     — no picker; a location sent anyway is ignored, never an error
 *
 * Seeded FROM config('vehicle_locations.policy') so the whole vocabulary has one authored source,
 * but stored on the row so a curator can override a single type in the app without a deploy — the
 * same read-config/write-DB split `component_catalog` already uses (`edited_in_app`).
 *
 * `service_catalog` and `inspection_types` are deliberately NOT given this column: planned work and
 * checks are performed on the whole car, so the answer is always `none` and a column would only
 * invite someone to set it to something else.
 */
return new class extends Migration
{
    private const TABLES = ['fault_catalog', 'damage_catalog'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'location_mode')) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) {
                // 'optional' is the safe default for a row the policy config has no opinion on: the
                // picker appears, nothing is forced, and no existing report becomes invalid.
                $table->string('location_mode', 12)->default('optional')->after('category_key');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'location_mode')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('location_mode');
                });
            }
        }
    }
};
