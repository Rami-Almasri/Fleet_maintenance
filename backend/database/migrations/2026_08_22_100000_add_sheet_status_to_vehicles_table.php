<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fleet register's own word for a car — "Active" / "For sale" / "Office" / "Under process" /
 * "Insurance claim" / "Sold" — kept verbatim, exactly as `sheet_category` already keeps the
 * register's rental segment.
 *
 * Until now that word was read, mapped through config/vehicle_status.php into `vehicles.status`,
 * and then thrown away. Only the verdict survived, never the sentence that produced it — so when
 * the register and our live state disagree, nobody could see it. Plate 44648 is a real example:
 * the register calls it "Office" while an open rental contract has it out earning, and no screen
 * in the app could show both halves of that contradiction.
 *
 * This column is the register's claim, NOT our state. `vehicles.status` stays the operational
 * answer; this is the paper trail behind it, so a page can say "the sheet says For sale" without
 * re-reading Google every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('sheet_status')->nullable()->after('sheet_category');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('sheet_status');
        });
    }
};
