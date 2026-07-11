<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The odometer reading captured at the final re-inspection sign-off — the inspector's QC reading the
 * moment the car passes and returns to service. Its own column (not reusing `return_odometer`, the
 * garage-OUT reading at mark-ready) so the two distinct captures each keep their own row in the
 * vehicle mileage timeline. Additive + nullable — every legacy/other-path ticket is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->unsignedInteger('reinspect_odometer')->nullable()->after('return_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('reinspect_odometer');
        });
    }
};
