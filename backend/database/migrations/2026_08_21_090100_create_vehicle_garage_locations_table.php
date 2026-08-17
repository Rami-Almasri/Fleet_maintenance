<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The N-Location tab of the maintenance sheet, mirrored — "which car is at which garage",
 * hand-kept by the Controllers as two columns: `Car` and `Garage`.
 *
 * This is the list the In the Garage board was built to replace, and it is also the ONLY place
 * the garage is written down for a car OfficeManager sent out on a maintenance contract (OM has
 * no garage field at all). So we read it rather than ask anyone to type the same thing twice.
 *
 * A MIRROR, NOT A LEDGER. The tab is a live list — a row disappears the moment the car comes back
 * — so every import REPLACES the whole table. Nothing here is history; `vehicle_garage_locations`
 * only ever answers "what did the sheet say at `imported_at`". Rows whose car we cannot resolve
 * are kept with a null vehicle_id and reported, never silently dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_garage_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('car_label');                        // the sheet's Car cell, verbatim
            $table->string('plate_text', 32)->nullable();        // the plate as written, e.g. "H 52979"
            $table->string('garage_name');                       // the sheet's Garage cell, verbatim
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->unsignedInteger('sheet_row');                // 1-based row in the tab, for "go look at it"
            // dateTime(), not timestamp() — see the MariaDB ON UPDATE CURRENT_TIMESTAMP trap.
            $table->dateTime('imported_at');
            $table->timestamps();

            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_garage_locations');
    }
};
