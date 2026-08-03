<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DAMAGE — the third operational kind, promoted to a first-class catalog.
 *
 * A vehicle event is one of three things, and until now the system only modelled two:
 *
 *   service — planned work. Recurring is normal.
 *   fault   — the vehicle failed. Recurring is a reliability signal.
 *   damage  — something was DONE TO the vehicle (kerbed rim, door dent, cracked screen, accident).
 *             Unplanned, like a fault — but it says nothing about whether the car is reliable, and it
 *             carries its own commercial life: a liable party, an insurance claim, a renter to bill.
 *
 * WHY A CATALOG AND NOT A CATEGORY FLAG. The obvious shortcut is "bodywork and interior are damage".
 * It is wrong, and measurably so: the `interior` category holds `Dashboard fault`, `Door lock fault`,
 * `Interior light fault`, `Seat adjustment fault`, `Infotainment / screen issue` and `Water leakage
 * into cabin` — six real FAULTS — next to `Seat / upholstery damage` and `Broken trim`. `bodywork`
 * likewise mixes `Rust / corrosion` (the car deteriorating) with `Dent` (someone hit it). Typing by
 * category would mislabel all of those, which is exactly the coarse-rule mistake the Service/Fault
 * audit was written to stop repeating. Damage is therefore typed per CONCEPT, from this catalog, the
 * same way service is.
 *
 * @see docs/Service-Fault-Damage-Domain.md
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('damage_catalog')) {
            Schema::create('damage_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 80)->unique();
                $table->string('name', 120);
                $table->string('name_ar', 120)->nullable();

                // Reuses the findings/component category keys (bodywork, interior, tyres, …) so damage
                // joins the same category joins every other kind already uses.
                $table->string('category_key', 40)->index();

                // WHERE on the car — the panel/zone vocabulary the inspection hotspot capture already
                // speaks (front_bumper, rear_door_left, windscreen, wheel_fr …). Nullable: not every
                // damage report is localised.
                $table->string('area_key', 40)->nullable()->index();

                // How the damage happened, when the wording implies it. Drives liability defaults and
                // the insurance lane; never inferred from cost.
                //   impact | scratch | crack | tear | vandalism | wear | unknown
                $table->string('damage_type', 20)->default('unknown')->index();

                // Commercial behaviour — this is what makes Damage a different KIND rather than a fault
                // with a label. A chargeable row is one the renter can be billed for; an insurable row
                // is one that can open a claim. Both are defaults the workflow may override per event.
                $table->boolean('is_chargeable')->default(true);
                $table->boolean('is_insurable')->default(false);

                // Cosmetic damage does not ground a car; structural/safety damage does. Read by the
                // readiness gate rather than re-derived from severity text.
                $table->boolean('affects_roadworthiness')->default(false);

                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_catalog');
    }
};
