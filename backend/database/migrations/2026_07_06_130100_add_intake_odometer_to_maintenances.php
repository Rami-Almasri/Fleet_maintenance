<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The odometer reading captured at the Inspector's-Pad "Pick Up" — the mandatory gate on that intake
 * (a ticket is never minted without it). Kept as its own column rather than reusing `test_odometer`
 * (the inspector's test-drive reading) so the two intake paths don't overwrite each other's baseline:
 * a pick-up skips the test drive entirely. Additive + nullable — every legacy/other-path ticket is
 * unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->unsignedInteger('intake_odometer')->nullable()->after('test_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('intake_odometer');
        });
    }
};
