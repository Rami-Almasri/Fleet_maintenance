<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the replacement limit onto the fitted part.
 *
 * THE PROBLEM. Until now the only statement of "this part is good for 12 months or 20,000 km" lived
 * on `component_catalog` (expected_life_months / expected_life_km), and ComponentLifecycle::
 * serviceLife() read it FRESH on every request. The catalog is editable in the app (/parts-catalog,
 * `2026_08_04_100000_make_component_catalog_editable`), so the moment somebody corrects a type's
 * expectation, every part ever fitted — including parts removed two years ago — is silently
 * re-scored against the new number. The Replaced view then reports a limit that was never the limit
 * while that part was on the car, and "was it changed early?" becomes unanswerable.
 *
 * THE FIX. The limit in force AT INSTALL is copied onto the row, exactly like `purchase_cost` and
 * `warranty_months` already are: commercial facts are copied from the source and never recomputed.
 * A limit is the same kind of fact. Editing the catalog now changes what the NEXT part is judged
 * against and leaves the history alone.
 *
 * NULL IS NOT BACKFILLED, DELIBERATELY. Rows written before this migration have no snapshot, and
 * stamping today's catalog value onto them would manufacture the very fiction this column exists to
 * prevent. They keep NULL, the read model falls back to the catalog, and the payload says so via
 * `service_life.limit_source = 'catalog'` so the UI can mark the number as the type's current
 * expectation rather than this part's recorded one. Unknown beats invented — the same rule
 * ComponentService::makeComponent runs on for serials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            // The limit as it stood when THIS part was fitted. Same units and the same nullability
            // as their catalog counterparts: null means "no expectation was stated", never zero.
            $table->unsignedInteger('expected_life_km')->nullable()->after('warranty_until');
            $table->unsignedSmallInteger('expected_life_months')->nullable()->after('expected_life_km');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            $table->dropColumn(['expected_life_km', 'expected_life_months']);
        });
    }
};
