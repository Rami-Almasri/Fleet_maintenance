<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1, Step 1 (blueprint §3d) — give `part_purchases` its delivery phase, so the fulfillment
 * sub-state (awaiting-delivery / received) can be DERIVED from timestamps rather than stored:
 *
 *  - expected_delivery_date : the ETA a PO promises — source of "expected tomorrow" and, with
 *    `ordered_at ≡ purchased_at`, of "days waiting". Read by MaintenanceDelayResolver (Step 3).
 *  - delivered_at           : when the part reached the workshop. Its presence is what flips a
 *    purchase from "awaiting delivery" to "received"; the resolver (Step 2) treats a delivered,
 *    not-yet-installed part as no longer blocking the repair.
 *
 * Both nullable → no backfill, existing rows unaffected. No status column is added (fulfillment
 * state is derived, not persisted). This step does NOT touch `vehicles.operational_status`
 * (Addendum E / P1-D1) and adds no behaviour — the columns are inert until Step 6 wires them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_purchases', function (Blueprint $table) {
            $table->date('expected_delivery_date')->nullable()->after('purchased_at');
            $table->timestamp('delivered_at')->nullable()->after('expected_delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('part_purchases', function (Blueprint $table) {
            $table->dropColumn(['expected_delivery_date', 'delivered_at']);
        });
    }
};
