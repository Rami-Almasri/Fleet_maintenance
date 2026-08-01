<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An immutable ticket reference on the vehicle timeline, so a deleted ticket can never orphan its history.
 *
 * THE DEFECT THIS CLOSES. `vehicle_log_events.maintenance_id` is declared
 * `->constrained('maintenances')->nullOnDelete()`, so destroying a ticket silently NULLs the link on every
 * event it owned. The rows survive; the linkage does not — which is worse than losing both, because the
 * timeline then reads as complete while 79% of its evidence (1,559 of 1,979 rows, measured 2026-08-01) sits
 * detached from any ticket. The `VehicleLogEvent` docblock's "append-only" claim was true of the ROWS and
 * false of the RELATIONSHIP.
 *
 * `maintenance_ref` is a plain integer with NO foreign key. That is the entire point: a constraint is what
 * gives the database permission to rewrite the column. Nothing can null this one.
 *
 * The FK on `maintenance_id` is deliberately KEPT. It still does useful work for live tickets (cascade
 * safety, join integrity); `maintenance_ref` is the archival record of what the link WAS. They agree for
 * every live ticket and diverge only once a ticket is destroyed — which is exactly the case we lost before.
 *
 * BACKFILL SCOPE, stated honestly: this copies `maintenance_id` where it is still present. The 1,559 rows
 * already orphaned CANNOT be recovered — their ticket id is gone from the row and re-deriving it by
 * matching vehicle + time window is reconstruction, not recovery, and was explicitly ruled out. Those rows
 * stay vehicle-level-only, permanently. This migration stops the bleeding; it does not heal the wound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_log_events', function (Blueprint $table) {
            // Nullable because genuine vehicle-level events (readiness, condition) have no ticket at all.
            $table->unsignedBigInteger('maintenance_ref')->nullable()->after('maintenance_id');
            $table->index(['maintenance_ref'], 'vle_maintenance_ref_index');
        });

        // Preserve every link that still exists at this moment.
        DB::table('vehicle_log_events')
            ->whereNotNull('maintenance_id')
            ->update(['maintenance_ref' => DB::raw('maintenance_id')]);
    }

    public function down(): void
    {
        Schema::table('vehicle_log_events', function (Blueprint $table) {
            $table->dropIndex('vle_maintenance_ref_index');
            $table->dropColumn('maintenance_ref');
        });
    }
};
