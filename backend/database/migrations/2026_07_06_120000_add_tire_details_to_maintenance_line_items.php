<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight tire tracking — three optional attributes on a PART line so that when a tire is
 * fitted during a maintenance ticket we keep an audit trail (brand, DOT batch code, and the
 * tread depth measured at install). Purely additive & nullable: only surfaced in the UI when the
 * line's category is 'tyres', and every existing part/labor line keeps working untouched.
 *
 * These sit alongside the existing durability fields (installed_on / installed_odometer /
 * warranty_until), so "which tire, when, how worn, under warranty until when" is one row read —
 * exactly what we need if a customer later disputes a tire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_line_items', function (Blueprint $table) {
            $table->string('tire_brand')->nullable()->after('part_number');        // e.g. Michelin, Bridgestone
            $table->string('tire_dot', 20)->nullable()->after('tire_brand');       // DOT batch code (week/year of manufacture)
            $table->decimal('tire_tread_mm', 4, 1)->nullable()->after('tire_dot');  // tread depth at install, in mm
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_line_items', function (Blueprint $table) {
            $table->dropColumn(['tire_brand', 'tire_dot', 'tire_tread_mm']);
        });
    }
};
