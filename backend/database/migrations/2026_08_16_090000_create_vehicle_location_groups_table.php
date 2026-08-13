<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SECTIONS of the "where on the car?" picker — Exterior, Wheels & Tyres, Glass & Mirrors, …
 *
 * The places themselves became a curated catalog when `vehicle_locations` landed, but the sections
 * they sit in stayed in config('vehicle_locations.groups'), which meant half the picker was editable
 * from the app and half needed a deploy. Adding a place was a click; adding the section to put it in
 * was a code change. This table closes that gap: a group is a row, with the same rules as a place —
 * `key` is stable and never renamed, retirement is `is_active=false` and never a delete (locations
 * point at the key, and a deleted section would orphan them).
 *
 * Read DB-first with the config as fallback by {@see App\Services\FaultLocationService::groups()},
 * exactly as the locations themselves are, so a fresh install and a half-migrated environment still
 * render the authored sections instead of an empty picker.
 *
 * @see config/vehicle_locations.php  the authored sections, and the rule for what belongs in one
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_location_groups')) {
            Schema::create('vehicle_location_groups', function (Blueprint $table) {
                $table->id();

                // The key `vehicle_locations.group_key` points at. Stable forever: renaming one would
                // detach every place in the section. Change the LABEL instead — that is what shows.
                $table->string('key', 40)->unique();

                $table->string('label', 120);
                $table->string('label_ar', 120)->nullable();

                // Retired sections keep their places visible (groupedCatalog appends unknown groups
                // rather than hiding them) — this only decides whether the section is offered.
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_location_groups');
    }
};
