<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The join between the action vocabulary and the component dictionary.
 *
 * `action_catalog` stores verb + target (`replace` / `brake_pads`); `component_catalog` stores a
 * slug (`brake-pads`). They were authored independently, for different purposes, and they nearly
 * agree — which is the dangerous kind of nearly.
 *
 * WHY A COLUMN AND NOT str_replace('_', '-'). The transform works on most of today's 57 replace
 * targets and is wrong in both directions the moment anyone adds a row:
 *
 *   accessory_belt   → 'accessory-belt'   … the catalog entry is `drive-belt`
 *   battery          → 'battery'          … the catalog entry is `battery-12v`
 *   cooling_fan      → 'cooling-fan'      … the catalog entry is `radiator-fan`
 *   link_rod         → 'link-rod'         … the catalog entry is `stabilizer-link`
 *   clutch           → 'clutch'           … the catalog entry is `clutch-kit`
 *   tie_rod          → 'tie-rod'          … the catalog entry is `tie-rod-end`
 *
 * Six of the first twenty-eight are already wrong. A silent miss here does not throw — it just means
 * a replacement never reaches the ledger, which is precisely the failure this whole feature exists
 * to fix, reintroduced one catalog entry at a time and invisible until someone counts.
 *
 * NULL IS A REAL ANSWER, NOT A GAP. Most action targets are not assets and must never become
 * components: `brake_system` (bled), `throttle_body` (cleaned), `warning_light` (reset), `wiring`
 * (repaired), and every consumable. Nullable means "this target does not instantiate a component",
 * and the coverage report distinguishes that from "nobody has mapped this yet".
 *
 * The mapping lives in config/component_catalog.php beside the entry it belongs to and is upserted
 * by ComponentCatalogSeeder, same as every other catalog field — one place a component type is
 * described, not two.
 *
 * Unique, because two component types claiming the same action target would make the resolution
 * ambiguous, and ambiguity here silently picks whichever row the database returns first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_catalog', function (Blueprint $table) {
            $table->string('action_target', 96)->nullable()->unique()->after('category_key');
        });
    }

    public function down(): void
    {
        Schema::table('component_catalog', function (Blueprint $table) {
            $table->dropUnique(['action_target']);
            $table->dropColumn('action_target');
        });
    }
};
