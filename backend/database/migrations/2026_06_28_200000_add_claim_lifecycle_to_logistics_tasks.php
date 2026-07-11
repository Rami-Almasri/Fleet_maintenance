<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Claim-based Logistics — a dispatch is no longer assigned at creation. A coordinator raises an
 * UNASSIGNED request that lands in every driver's pool; the first driver to "claim" it locks the
 * move to themselves (Dispatched → En Route → Picked Up → Delivered → Returned). Three additive,
 * legacy-safe pieces make that possible:
 *
 *   maintenance_id     — the maintenance ticket this move belongs to (a task can be raised "within
 *                        a ticket"); loose id (indexed, no FK) mirroring the table's other links.
 *   claimed_at         — when a driver took ownership of a pooled task (null while unclaimed).
 *   returned_* / returned_at
 *                      — the GPS verification stamp captured the moment the driver marks the car
 *                        "Returned / Arrived" at base, proving the car is physically home.
 *
 * Everything is nullable so existing open rows keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('maintenance_id')->nullable()->after('vehicle_label')->index();
            $table->timestamp('claimed_at')->nullable()->after('dispatched_at');

            // GPS proof-of-return — stamped on the "Returned / Arrived" step only.
            $table->timestamp('returned_at')->nullable()->after('completed_at');
            $table->decimal('returned_lat', 10, 7)->nullable()->after('returned_at');
            $table->decimal('returned_lng', 10, 7)->nullable()->after('returned_lat');
            $table->decimal('returned_accuracy', 8, 2)->nullable()->after('returned_lng'); // metres
        });

        // The new lifecycle reuses 'delivered' as a MID-flow phase (car at the garage). The old code
        // used it as a TERMINAL marker (== completed). Every existing 'delivered' row is therefore a
        // finished move — fold it onto the new terminal status so the string is free for its new
        // meaning and those cars don't reappear on the board as still-in-transit. Safe because no
        // new-style 'delivered' rows can exist yet (this runs before the new code ships). `completed`
        // is set when a move closes, so it doubles as the canonical "is this still open?" flag.
        DB::table('logistics_tasks')->where('status', 'delivered')->update(['status' => 'returned']);
    }

    public function down(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance_id', 'claimed_at',
                'returned_at', 'returned_lat', 'returned_lng', 'returned_accuracy',
            ]);
        });
    }
};
