<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery (towing) + Test-Kind columns for the refined Workflow Hub intake.
 *
 * RECOVERY — when a car is a Breakdown (won't start) the "pickup" leg is NOT a normal driver transport;
 * it is a Recovery Truck (winch) towing our vehicle to the garage. The dispatch step captures the towing
 * unit instead of an assigned driver:
 *   • recovery_unit_name  — the winch/tow unit or external towing company ("Recovery Truck #05").
 *   • recovery_unit_phone — the recovery operator's mobile.
 * The mandatory odometer/condition photo gate is unchanged; only the entity is a "Recovery Unit", never a
 * driver. Null for every non-recovery leg. See MaintenanceWorkflowService::dispatchRecovery().
 *
 * TEST-KIND — which intake tab produced the ticket, for traceability (the "no black boxes" rule):
 *   • 'routine_check'      — the Routine tab: an Oil / Battery / Tyres check.
 *   • 'scheduled_dormancy' — the Scheduled tab: a park-time (idle-duration) based check.
 * Null for complaints, breakdowns reported directly, and every legacy / non-workflow row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('recovery_unit_name')->nullable()->after('assigned_driver_id');
            $table->string('recovery_unit_phone')->nullable()->after('recovery_unit_name');
            $table->string('test_kind')->nullable()->after('trigger_reason')->index();
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['recovery_unit_name', 'recovery_unit_phone', 'test_kind']);
        });
    }
};
