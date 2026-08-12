<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN the current mileage was actually observed — as distinct from when we imported it.
 *
 * `odometer_source_at` answers "when did our database learn this"; it is an import timestamp and
 * is always ~now. That is useless for projecting a car forward, which needs the moment a human
 * actually read the dashboard. The "Oil Change" sheet carries exactly that in its LAST EDIT
 * column, and without storing it the sheet's MILAGE can never become an oil-projection anchor
 * (no date ⇒ no days-elapsed ⇒ no projection).
 *
 * Nullable and unset for sources that carry no observation date of their own (the OM car card
 * has none) — a missing date must read as "we don't know when", never as "today".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('odometer_reading_on')->nullable()->after('odometer_source_at');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('odometer_reading_on');
        });
    }
};
