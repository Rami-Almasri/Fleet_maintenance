<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH GARAGE the car on this maintenance contract is actually sitting at.
 *
 * A car goes into the shop one of two ways. Through the workflow, the Supervisor picks the garage
 * and the ticket carries it (`maintenances.vendor_id`) — the system already knows. Through
 * OfficeManager, a type-'U' maintenance contract is opened and the car leaves; that contract says
 * the car is in the shop but has no field for WHERE, so the garage lived only in someone's head
 * (and in a hand-kept WhatsApp list). These three columns are that missing fact, written by a
 * person on the In the Garage board and stamped with who said so and when.
 *
 * On the CONTRACT, not the vehicle: the placement belongs to one visit. When OM closes the
 * contract the car leaves the board, and the next visit starts with an empty garage rather than
 * inheriting a stale one. ContractImporter writes an explicit column list, so a re-import of the
 * contract never clears what a person recorded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('garage_vendor_id')->nullable()->after('condition_ack_at')
                ->constrained('vendors')->nullOnDelete();
            $table->unsignedBigInteger('garage_recorded_by')->nullable()->after('garage_vendor_id');
            // dateTime(), not timestamp() — MariaDB silently adds ON UPDATE CURRENT_TIMESTAMP to a
            // nullable timestamp, which would keep re-stamping "when someone said this" on every
            // unrelated write to the row.
            $table->dateTime('garage_recorded_at')->nullable()->after('garage_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['garage_vendor_id']);
            $table->dropColumn(['garage_vendor_id', 'garage_recorded_by', 'garage_recorded_at']);
        });
    }
};
