<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a PLANNED garage transfer will actually be moved — 'driver' (a company driver collects it) or
 * 'recovery' (a towing unit, for a car that isn't drivable). Chosen by the Supervisor at the moment
 * Transfer is requested (see MaintenanceWorkflowService::beginGarageTransfer), so the pickup screen the
 * ticket lands on afterwards (Driver dispatch vs Recovery dispatch) matches the real transport method
 * instead of always assuming a driver. Nullable + additive: any ticket that isn't mid-transfer, or was
 * transferred before this column existed, simply has it null and falls back to the legacy driver-only
 * pickup — no existing path is affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('transfer_transport_method', 20)->nullable()->after('transfer_to_vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('transfer_transport_method');
        });
    }
};
