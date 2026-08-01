<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete `maintenances` — the change that stops evidence being destroyed rather than merely
 * recording that it was.
 *
 * WHAT A HARD DELETE ACTUALLY DID. Eleven tables reference `maintenances` with ON DELETE CASCADE
 * (faults and their garage stints, line items, invoices, repair inspections, recommendation decisions
 * and — largest of all — `maintenance_signatures`, the projection the whole intelligence layer reads).
 * Ten more reference it with `nullOnDelete`, chief among them `vehicle_log_events`. So deleting one
 * ticket destroyed its faults outright AND detached its timeline, and did both silently.
 *
 * A soft delete fires NEITHER. The row stays, so no cascade runs and no link is nulled: the ticket
 * simply stops being visible. Restoring it brings back a ticket whose faults, stints, inspections and
 * timeline are all still attached — the difference between recoverable and re-created.
 *
 * ── THE TWO UNIQUE INDEXES, AND WHY THEY ARE LEFT ALONE ──────────────────────────────────────────
 * `maintenances_row_hash_unique` and `maintenances_contract_id_unique` are NOT relaxed here. A trashed
 * row keeps occupying both, which is a real constraint on the code above — the sheet importer must now
 * look through the trash before deciding to insert, or it will collide with a row it cannot see.
 *
 * The tempting fix is a composite unique on (row_hash, deleted_at). It does not work: MySQL treats
 * NULLs as distinct in a unique index, so two LIVE rows (deleted_at NULL) with the same hash would both
 * be accepted and live-row uniqueness would quietly disappear — trading a loud failure for a silent
 * one. The correct fix lives in the importer (`withTrashed()` + restore), where the intent is explicit.
 *
 * Reversible: dropping the column simply makes every trashed row live again, which is the safe
 * direction to fail in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
            // Every read now filters on this, so it earns its own index rather than riding along on
            // whatever composite happens to be nearby.
            $table->index('deleted_at', 'maintenances_deleted_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex('maintenances_deleted_at_index');
            $table->dropSoftDeletes();
        });
    }
};
