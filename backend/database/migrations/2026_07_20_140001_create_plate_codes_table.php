<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADDITIVE + REVERSIBLE. The plate-code dictionary from OfficeManager (the `VehiclesRegistration
 * SQL[EmNo]` lookup, supplied as PlateCode.xlsx): each numeric code → its plate symbol/letter,
 * English + Arabic. A vehicle's `plate_code` (from OM `PlateColorNo`) joins here to render a real
 * plate like "CC 43460" instead of the raw "485 43460". Seeded from database/data/plate_codes.csv
 * via `plate:import-codes`. down() drops the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plate_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('em_no')->unique();   // OM PlateColorNo / EmNo
            $table->string('letter_en', 32)->nullable();  // plate symbol/letter, e.g. F, H, P, CC
            $table->string('letter_ar', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plate_codes');
    }
};
