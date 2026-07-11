<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ready for Pickup" → "In Our Park" — two new stages after In-Shop (under_repair), inserted between
 * the garage sign-off and the final QA re-inspection. Captures the two mandatory photo-evidence
 * checkpoints on the driver's return leg: collecting the car from the garage, and arriving at base.
 * See MaintenanceWorkflowService::collectFromGarage() / arriveAtPark().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->foreignId('picked_up_from_garage_by')->nullable()->after('ready_at')->constrained('users')->nullOnDelete();
            $table->timestamp('picked_up_from_garage_at')->nullable()->after('picked_up_from_garage_by');

            $table->foreignId('park_arrived_by')->nullable()->after('picked_up_from_garage_at')->constrained('users')->nullOnDelete();
            $table->timestamp('park_arrived_at')->nullable()->after('park_arrived_by');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('picked_up_from_garage_by');
            $table->dropConstrainedForeignKey('park_arrived_by');

            $table->dropColumn(['picked_up_from_garage_at', 'park_arrived_at']);
        });
    }
};
