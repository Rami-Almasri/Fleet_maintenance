<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Round-Trip Logistics — a dispatch is no longer a single one-way leg. The same task now carries the
 * car out AND back: Dispatched → In Transit to {destination} → At {destination} → In Transit to Base →
 * Completed. Two additive, legacy-safe columns make that possible:
 *
 *   round_trip        — was this dispatched as a there-and-back move (e.g. to the garage and home)?
 *                       When false the leg ends at the destination (the old one-way behaviour).
 *   status_changed_at — when the CURRENT phase began, so the board can show "At Garage · 2h" and the
 *                       team can see how long a car has sat in each leg.
 *
 * Existing open rows keep their `in_transit` status (treated as the "to destination" leg) and are
 * stamped with their dispatch time so the new "in this phase since" read has something to show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->boolean('round_trip')->default(false)->after('destination');
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        // Seed the new "phase since" anchor for rows that already exist, so the board reads sensibly
        // the moment this ships (best-effort — falls back to the dispatch time).
        DB::table('logistics_tasks')
            ->whereNull('status_changed_at')
            ->update(['status_changed_at' => DB::raw('dispatched_at')]);
    }

    public function down(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->dropColumn(['round_trip', 'status_changed_at']);
        });
    }
};
