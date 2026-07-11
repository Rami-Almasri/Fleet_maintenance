<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Tombstones" for sheet-synced workshop events the user has deleted from the
 * dashboard. A sheet row has no stable id, so it is identified by the importer's
 * `row_hash` (plate|event|out_date|occurrence|origin). When a synced event is
 * deleted we hard-delete its `maintenances` row (so it leaves the board, cost and
 * utilization at once) and record a tombstone here. The importer cross-references
 * this list and SKIPS any tombstoned hash, so a future sync — even a full
 * wipe-and-reimport — never brings the event back.
 *
 *  - payload : the full `maintenances` attributes at delete time → a lossless,
 *              immediate Restore (the row is recreated verbatim).
 *  - display : a small UI snapshot so the Manage-Events list can render the
 *              greyed "removed" ghost (with a Restore button) without the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tombstones', function (Blueprint $table) {
            $table->id();
            $table->string('row_hash')->unique();          // the stable sheet identity the importer skips
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->string('origin')->default('sheet');     // sheet | customer-sheet
            $table->date('out_date')->nullable();           // for contract-window scoping in the manage list
            $table->json('payload');                        // full maintenances attributes — lossless restore
            $table->json('display');                        // UI snapshot for the ghost row
            $table->string('note')->nullable();             // optional reason
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tombstones');
    }
};
