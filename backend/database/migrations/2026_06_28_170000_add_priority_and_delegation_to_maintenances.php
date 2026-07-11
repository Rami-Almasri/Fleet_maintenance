<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supervisor Notification & Delegation.
 *
 * Two additions to a workflow ticket, both deliberately SEPARATE from the strict `workflow_status`
 * repair state machine:
 *
 *   - priority: the inspector's whole-ticket urgency tag (low/medium/high → 🟢/🟡/🔴). Tagging it
 *     auto-watches the supervisors and rides into every alert as a colour + symbol.
 *   - the delegation overlay: a Supervisor assigns a specific Logistics driver to pick up / drop off
 *     the car. The DRIVER itself reuses the existing `assigned_driver_id` ("Where is the car?"
 *     tracking) so pings + status visibility follow whoever is delegated; these columns only add the
 *     pickup/dropoff task, the "Driver Assigned" marker, and who delegated (audit), without touching
 *     the board lanes / transitions / SLA timing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // Whole-ticket urgency the supervisor flow keys off. (Per-finding `severity` stays as-is.)
            $table->string('priority', 10)->nullable()->after('severity');

            // Delegation overlay (Supervisor → Logistics driver). The driver = assigned_driver_id.
            $table->string('delegation_task', 10)->nullable()->after('assigned_driver_id');   // pickup | dropoff
            $table->string('delegation_status', 20)->nullable()->after('delegation_task');     // driver_assigned
            $table->foreignId('delegated_by')->nullable()->after('delegation_status')->constrained('users')->nullOnDelete();
            $table->timestamp('delegated_at')->nullable()->after('delegated_by');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delegated_by');
            $table->dropColumn(['priority', 'delegation_task', 'delegation_status', 'delegated_at']);
        });
    }
};
