<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADDITIVE + REVERSIBLE. Records the plate CODE on each timeline row so the plate identity is
 * (plate_key digits + plate_code), not digits alone. This is what lets the same digits under
 * two codes be kept as two separate plates (e.g. "P 76722" vs "U 76722"). plate_key stays the
 * digits for compatibility; grouping/resolution now also considers plate_code. down() drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plate_assignments', function (Blueprint $table) {
            $table->string('plate_code', 8)->nullable()->after('plate_key')->index();
        });
    }

    public function down(): void
    {
        Schema::table('plate_assignments', function (Blueprint $table) {
            $table->dropIndex(['plate_code']);
            $table->dropColumn('plate_code');
        });
    }
};
