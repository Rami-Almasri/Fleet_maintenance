<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `maintenance_temporary_releases.released_at` was created as a NOT NULL `timestamp` with no default, so
 * MariaDB silently gave it `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` — the trap described
 * in [[mariadb-timestamp-autoupdate-trap]]. Under rev. 1 the row was written once and never touched
 * again, so nothing ever noticed. Rev. 2 turned the release into a round trip whose row is SAVED ON
 * EVERY LEG (dispatch, pickup, arrival, return…), and each of those saves silently rewrote released_at
 * to "now" — so a car released last Tuesday would report that it left the workshop thirty seconds ago,
 * and "how long has this been out?" would answer nearly zero forever.
 *
 * `dateTime` carries no automatic behaviour. Same stored values, same range, no clock attached.
 *
 * Rows whose released_at was already overwritten are restored from `created_at`: rev. 1 set
 * `released_at = now()` inside the same create() call, so on any untouched row the two are equal to the
 * second, and created_at has never been writable through this path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_temporary_releases', function ($table) {
            $table->dateTime('released_at')->nullable(false)->change();
        });

        // Heal what the auto-update already ate. Only rows where the two disagree are touched, and only
        // ever backwards to the creation moment — a row written correctly is left exactly as it is.
        DB::statement('
            UPDATE maintenance_temporary_releases
               SET released_at = created_at
             WHERE created_at IS NOT NULL
               AND released_at <> created_at
        ');
    }

    public function down(): void
    {
        Schema::table('maintenance_temporary_releases', function ($table) {
            $table->timestamp('released_at')->nullable(false)->change();
        });
    }
};
