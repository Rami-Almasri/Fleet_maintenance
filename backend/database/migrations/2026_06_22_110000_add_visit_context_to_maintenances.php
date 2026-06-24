<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Rental-First" tagging: how to treat a workshop visit operationally.
 *   - routine          = planned service (oil, filters, periodic) — NOT a failure; the
 *                        foresight engine ignores these for Chronic / Act-now signals.
 *   - accident_rental  = accident repair logged while the car is on a live rental.
 *   - standard / null  = an ordinary off-rent workshop visit (the pre-existing behaviour).
 * Only `routine` changes any calculation; null is left untouched so historical rows count as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('visit_context')->nullable()->after('maintenance_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex(['visit_context']);
            $table->dropColumn('visit_context');
        });
    }
};
