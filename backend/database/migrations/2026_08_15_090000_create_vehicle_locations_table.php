<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE — the third axis of an event, promoted to a first-class catalog.
 *
 * An event already knew WHAT it is (fault/service/inspection/damage, from its catalog) and HOW BAD
 * (severity). It never knew WHERE, so "where" was smuggled into the type wording — `door_dent`,
 * `rim_scratch`, `front_lip_damage` — and the moment a fourth panel got dented somebody typed free
 * text and the fleet lost the ability to ask "how much damage do we take on rear bumpers?".
 *
 * This table is the shared vocabulary of places on a car. It is a CATALOG, not a per-event record:
 * the link from an event to its places is `maintenance_task_locations`.
 *
 * NOT A SECOND LOCATION SYSTEM. Every row carries `inspection_zone`, the id of the matching panel in
 * the inspection hotspot diagram (VehicleDiagram.js → `inspection_records.body_part`), and `area_key`,
 * the coarse hint `damage_catalog.area_key` already speaks. The four wheel corners reuse
 * `component_catalog`'s axle_corner keys verbatim. Nothing here replaces those; this unifies them so
 * a fault, a photo and a fitted part can all name the same corner of the same car.
 *
 * @see config/vehicle_locations.php  the content, and the rule for what belongs in it
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_locations')) {
            Schema::create('vehicle_locations', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 60)->unique();
                $table->string('name', 120);
                $table->string('name_ar', 120)->nullable();

                // Which section of the picker this lives in (exterior / wheels / glass / lights /
                // interior / mechanical). Mirrors config('vehicle_locations.groups').
                $table->string('group_key', 40)->index();

                // How SPECIFIC an answer this is: panel | corner | zone | component | whole.
                // Kept so a report can separate "we know the panel" from "somewhere on the body"
                // instead of guessing from the name. See [[treat-data-as-source-of-truth]].
                $table->string('precision', 20)->default('panel')->index();

                // The matching VehicleDiagram zone id (inspection_records.body_part), or null when the
                // diagram has no such panel. This is the join that keeps the two vocabularies one.
                $table->string('inspection_zone', 40)->nullable()->index();

                // The damage_catalog.area_key this location satisfies (many locations → one area).
                $table->string('area_key', 40)->nullable()->index();

                // Search synonyms, EN + AR. JSON matched with a plain LIKE exactly as
                // component_catalog.aliases is — JSON_CONTAINS cannot do substrings and behaves
                // differently on the local MariaDB vs production MySQL 8. See [[mariadb-local-mysql8-prod]].
                $table->json('aliases')->nullable();

                // Retired with is_active=false, NEVER deleted: tasks hold references, and a deleted
                // place would erase where a historical fault was.
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_locations');
    }
};
