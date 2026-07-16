<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Defense-in-depth against DOUBLE BOOKING: a car may have at most ONE open rental contract.
 *
 * The application already enforces this inside a locked transaction (ContractService::store and
 * OperationsService::startOperation), but the database is the last line of defense. MySQL has no
 * partial indexes, so we materialise the guarded key in a STORED generated column that is the
 * vehicle_id ONLY while the contract is a live rental (state=open, not returned, type C) and NULL
 * otherwise — then a plain UNIQUE index on that column rejects a second live rental per car (NULLs
 * are distinct, so closed/returned/non-rental contracts are unconstrained).
 *
 * SAFETY: this migration REFUSES to run while a car already has more than one open rental — it lists
 * the offending vehicles and stops, rather than crashing with a cryptic duplicate-key error or, worse,
 * silently doing nothing. Resolve those conflicts (close/return the wrong contract) and re-run. This
 * is intentional: you should not launch with two customers holding the same car.
 */
return new class extends Migration
{
    private const COL = 'open_rental_vehicle_id';
    private const IDX = 'contracts_one_open_rental_per_vehicle_unique';

    public function up(): void
    {
        // Only the live MySQL database gets the generated column + index. SQLite (tests) relies on the
        // application-level lock, which is exercised by the test suite directly.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Guard: never install the constraint over data that already violates it — surface the conflict.
        $dupes = DB::table('contracts')
            ->where('state', 'open')
            ->whereNull('in_date')
            ->where('contract_type', 'C')
            ->select('vehicle_id', DB::raw('COUNT(*) as n'))
            ->groupBy('vehicle_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('n', 'vehicle_id');

        if ($dupes->isNotEmpty()) {
            $list = collect($dupes)->map(fn ($n, $vid) => "vehicle {$vid} ({$n} open rentals)")->implode('; ');
            throw new \RuntimeException(
                'Cannot add the one-open-rental-per-vehicle guard: these cars already have multiple open '
                . 'rental contracts and must be reconciled first (close/return the incorrect contract): '
                . $list . '. See the contracts table for contract_no + customer_id of each.'
            );
        }

        if (! Schema::hasColumn('contracts', self::COL)) {
            DB::statement(
                'ALTER TABLE contracts ADD COLUMN ' . self::COL . ' BIGINT UNSIGNED '
                . "GENERATED ALWAYS AS (CASE WHEN state = 'open' AND in_date IS NULL AND contract_type = 'C' "
                . 'THEN vehicle_id ELSE NULL END) STORED'
            );
        }

        DB::statement('CREATE UNIQUE INDEX ' . self::IDX . ' ON contracts (' . self::COL . ')');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ' . self::IDX . ' ON contracts');

        if (Schema::hasColumn('contracts', self::COL)) {
            DB::statement('ALTER TABLE contracts DROP COLUMN ' . self::COL);
        }
    }
};
