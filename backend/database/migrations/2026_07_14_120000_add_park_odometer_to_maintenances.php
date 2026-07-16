<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The odometer reading captured when the driver arrives back at OUR PARK on the return leg
 * (arriveAtPark, the second checkpoint after the garage-OUT collect reading). Its own column — not
 * reusing `return_odometer` (the garage-OUT reading at collect) nor `reinspect_odometer` (the final
 * QA sign-off) — so this distinct capture keeps its own row in the vehicle mileage timeline.
 * Additive + nullable — every legacy/other-path ticket is unaffected, and the field stays optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->unsignedInteger('park_odometer')->nullable()->after('reinspect_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('park_odometer');
        });
    }
};
