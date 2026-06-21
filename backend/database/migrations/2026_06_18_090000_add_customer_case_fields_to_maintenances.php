<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Customer Cases" sheet tab (a customer-charge maintenance log) carries one
 * field the fleet maintenance log doesn't: "Bill Receive". Everything else maps
 * onto existing maintenance columns. Rows from that tab are stored with
 * origin = 'customer-sheet' so they never mix into the fleet maintenance board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('bill_receive')->nullable()->after('cost_notes');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('bill_receive');
        });
    }
};
