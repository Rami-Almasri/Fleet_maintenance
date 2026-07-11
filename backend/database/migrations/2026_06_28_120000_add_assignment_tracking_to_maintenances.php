<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Where is the car?" tracking for the Maintenance Workflow (Option A).
 *
 *  - assigned_driver_id : the CURRENT assigned driver (the logistics user responsible for the car at
 *    the ticket's active stage). Auto-set on dispatch, changeable by a supervisor via reassign() — so
 *    pings + status visibility always follow whoever currently holds the car.
 *  - last_status / last_status_at / last_status_by : the car's last-known location/status, set
 *    automatically on dispatch & ready and by the driver's one-click reply to a "Ping location".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->foreignId('assigned_driver_id')->nullable()->after('dispatched_by')->constrained('users')->nullOnDelete();
            $table->string('last_status')->nullable()->after('assigned_driver_id');
            $table->timestamp('last_status_at')->nullable()->after('last_status');
            $table->foreignId('last_status_by')->nullable()->after('last_status_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_driver_id');
            $table->dropConstrainedForeignId('last_status_by');
            $table->dropColumn(['last_status', 'last_status_at']);
        });
    }
};
