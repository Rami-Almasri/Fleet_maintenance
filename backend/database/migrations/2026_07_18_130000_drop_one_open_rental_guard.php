<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the one-open-rental-per-vehicle DB guard (index + generated column) added in
 * 2026_07_16_090000.
 *
 * The unique index blocks importing real OfficeManager data: OM is the source of truth and
 * legitimately contains vehicles with more than one "open" contract (contracts never closed,
 * exchange/overlap chains, etc.). om:sync then fails with:
 *   SQLSTATE[23000] 1062 Duplicate entry '<vehicle>' for key
 *   'contracts_one_open_rental_per_vehicle_unique'
 *
 * Double-booking is still prevented at the APPLICATION layer (ContractService open-rental check +
 * row lock on the web create path). This only removes the redundant DB-level guard that is
 * incompatible with syncing source-of-truth data.
 */
return new class extends Migration
{
    private const COL = 'open_rental_vehicle_id';
    private const IDX = 'contracts_one_open_rental_per_vehicle_unique';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // MySQL has no "DROP INDEX IF EXISTS", so check information_schema first.
        $hasIndex = DB::selectOne(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contracts' AND INDEX_NAME = ? LIMIT 1",
            [self::IDX]
        );
        if ($hasIndex) {
            DB::statement('ALTER TABLE contracts DROP INDEX ' . self::IDX);
        }

        if (Schema::hasColumn('contracts', self::COL)) {
            DB::statement('ALTER TABLE contracts DROP COLUMN ' . self::COL);
        }
    }

    public function down(): void
    {
        // Intentionally NOT recreated — the guard is incompatible with source-of-truth OM data.
        // Double-booking prevention lives in the application layer.
    }
};
