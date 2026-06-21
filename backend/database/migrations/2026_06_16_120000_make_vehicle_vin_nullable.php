<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow a NULL vin. Cars now come from the OfficeManager API (owner 1541 + extra serials),
 * and some OM cars have no ChasisNo. The vin UNIQUE index permits multiple NULLs, so VIN-less
 * cars coexist; they are keyed by external_id ("OM:{CarSerial}") instead. Raw ALTER avoids the
 * doctrine/dbal dependency that ->change() would otherwise need.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `vehicles` MODIFY `vin` VARCHAR(32) NULL');
    }

    public function down(): void
    {
        // Best-effort revert; will fail if NULL vins exist (expected once API cars are imported).
        DB::statement('ALTER TABLE `vehicles` MODIFY `vin` VARCHAR(32) NOT NULL');
    }
};
