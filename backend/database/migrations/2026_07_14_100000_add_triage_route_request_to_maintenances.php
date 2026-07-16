<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Triage Routing Approval gate. When Abu Maroof (the Inspector) triages a customer complaint and decides
 * the car must be sent in — to a garage or to his own diagnostic — his decision is now a RECOMMENDATION
 * that a Supervisor/delegate must approve, not an executed move. This JSON column holds his pending
 * routing recommendation (destination, optional replacement vehicle, note, and who/when recommended it)
 * while the ticket sits in workflow_status = 'triage_approval_pending'. It is cleared once a Supervisor
 * approves (the real route runs) or rejects (the complaint returns to triage). Null for every other row.
 *
 * See MaintenanceWorkflowService::recommendTriageRoute() / approveTriageRoute() / rejectTriageRoute().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->json('triage_route_request')->nullable()->after('recommendation_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('triage_route_request');
        });
    }
};
