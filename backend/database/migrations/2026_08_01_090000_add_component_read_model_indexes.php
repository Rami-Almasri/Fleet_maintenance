<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the Vehicle Installed Components READ surfaces (the fleet Component Intelligence
 * board). The Phase-1 table was indexed for the WRITE path — the slot lookup and the per-vehicle
 * "what is on this car" read — but the fleet cards ask two questions it never anticipated:
 *
 *   "what has been replaced lately?"   → WHERE removed_at >= ?           (recently_replaced)
 *   "which types churn hardest?"       → WHERE removed_at IS NOT NULL
 *                                        GROUP BY component_catalog_id   (frequently_replaced)
 *
 * Neither had an index, so both degrade into a full scan of the whole component ledger that grows
 * with every replacement the fleet ever makes.
 *
 * `status, warranty_until` covers the warranty-expiring card: the existing single-column
 * warranty_until index cannot skip the retired rows first, and retired rows are the majority of the
 * table over time.
 *
 * Additive and reversible: index-only, no column or data change, so it is safe to run on a live
 * database and safe to roll back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            // recently_replaced: newest-first range scan over removals.
            $table->index('removed_at', 'vc_removed_at_idx');

            // frequently_replaced: group the removals by type without touching live rows.
            $table->index(['component_catalog_id', 'removed_at'], 'vc_catalog_removed_idx');

            // warranty_expiring: narrow to active first, then range-scan the expiry date.
            $table->index(['status', 'warranty_until'], 'vc_status_warranty_idx');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            $table->dropIndex('vc_removed_at_idx');
            $table->dropIndex('vc_catalog_removed_idx');
            $table->dropIndex('vc_status_warranty_idx');
        });
    }
};
