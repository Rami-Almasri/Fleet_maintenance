<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trigger Detail — a snapshot of WHY the system raised an inspection request, captured at creation so
 * the Inspection Review Queue can explain a machine-generated request the way a human requester would.
 *
 * Populated only for SYSTEM-generated (periodic) requests by MaintenanceWorkflowService::systemRequestInspection()
 * from DiagnosticGateService::conditionsDue(). It records the rule(s) that fired (oil/service overdue,
 * mileage threshold, battery age, post-downtime idle …), the human "why" for each, the suggested findings
 * checklist, and the relevant values at the moment of detection (current mileage, interval, overdue amount,
 * due date). Null for every human-raised request and every legacy / non-workflow row.
 *
 * A read-time snapshot on purpose: it is the reason the ticket was created THEN, and must not drift if the
 * car is serviced or its odometer moves afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->json('trigger_detail')->nullable()->after('suggested_findings');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('trigger_detail');
        });
    }
};
