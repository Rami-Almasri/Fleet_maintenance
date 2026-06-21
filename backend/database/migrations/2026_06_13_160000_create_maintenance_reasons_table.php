<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The controlled maintenance reason -> status vocabulary, imported from the
 * "Main reason" tab of the maintenance Google Sheet. Drives the priority/colour
 * classification on the maintenance board (keyword -> status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_reasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sheet_ref')->nullable();      // the sheet's own ID column
            $table->string('reason_en')->unique();                 // English reason = the keyword
            $table->string('reason_ar')->nullable();               // Arabic name
            $table->string('status_raw')->nullable();              // raw sheet status, e.g. "Major / Critical"
            $table->string('level')->default('routine');           // critical | minor | routine | special
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_reasons');
    }
};
