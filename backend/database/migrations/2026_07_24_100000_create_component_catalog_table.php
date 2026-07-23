<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * component_catalog — the dictionary of component TYPES the fleet tracks (Asset Layer, Phase 1).
 *
 * A catalog entry says what KIND of thing a part is and how it is tracked; it never stores a
 * physical part. `tracking_mode` drives all validation downstream:
 *   serialized  — individual identity (engine, battery, AC compressor): serial required at creation.
 *   batch       — quantity/position tracked, serial optional (brake pads, tyres).
 *   consumable  — NEVER instantiates a vehicle_components row (oil, coolant); such work is a
 *                 service_records entry only.
 *
 * `category_key` uses the SAME keys as config/maintenance_findings.php so a fault's category joins
 * straight to the component types that could be responsible — no string matching.
 * Entries are retired via `is_active=false`, never deleted (incoming FKs are restrictOnDelete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_catalog', function (Blueprint $table) {
            $table->id();

            // Stable machine key for seeds/config — survives display renames. Seeder upserts by slug.
            $table->string('slug', 120)->unique();
            $table->string('name', 120);

            // Joins faults <-> component types (same vocabulary as the findings catalog).
            $table->string('category_key', 40)->index();

            // serialized | batch | consumable — the rulebook selector.
            $table->string('tracking_mode', 20)->index();

            $table->string('default_part_number', 80)->nullable();
            $table->unsignedSmallInteger('default_warranty_months')->nullable();

            // Expected service life — foresight inputs; auto-tunable later from fleet actuals.
            $table->unsignedInteger('expected_life_km')->nullable();
            $table->unsignedSmallInteger('expected_life_months')->nullable();

            // Which position vocabulary instances of this type use:
            // 'axle_corner' (FL/FR/RL/RR), 'axle' (front/rear), or null = positionless.
            $table->string('position_scheme', 20)->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_catalog');
    }
};
