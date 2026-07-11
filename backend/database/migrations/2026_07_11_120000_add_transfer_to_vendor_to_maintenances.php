<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planned Garage Transfer — the DESTINATION garage a car is being moved to, while `vendor_id` keeps
 * pointing at the garage the car is PHYSICALLY at right now (the ground truth). Set the moment the
 * Supervisor requests a garage→garage transfer (the ticket drops to "Awaiting Pickup"); cleared the
 * moment the car actually arrives at the destination ("Now at Garage" check-in — markUnderRepair),
 * where the fault stints + `vendor_id` finally hand over. Nullable + additive: any ticket that isn't
 * mid-transfer simply has it null, so no existing path is affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->foreignId('transfer_to_vendor_id')->nullable()->after('vendor_id')
                ->constrained('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_to_vendor_id');
        });
    }
};
