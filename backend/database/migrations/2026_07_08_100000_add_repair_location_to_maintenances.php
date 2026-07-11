<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair Location — the Supervisor's "where does this repair happen?" choice, captured at the Decide
 * step when a maintenance ticket is opened:
 *
 *   • 'in_shop'  — the car goes to a workshop (the existing dispatch → garage → re-inspection pipeline);
 *                  it reads as In-Maintenance and is prompted to move to the garage.
 *   • 'on_site'  — a mobile/minor job (battery, bulb, tyre check) done where the car is parked. The car
 *                  STAYS operationally Available (the ticket parks in the on_site_pending state, which is
 *                  deliberately OUTSIDE WF_TICKET_STATES) and only carries a "Pending Maintenance" tag; a
 *                  single "Mark as Serviced" closes it with no garage dispatch, no re-inspection, no QA.
 *
 * Null for every legacy / non-workflow row and for a cleared diagnostic — the column only matters for a
 * committed workflow ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('repair_location')->nullable()->after('workflow_status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('repair_location');
        });
    }
};
