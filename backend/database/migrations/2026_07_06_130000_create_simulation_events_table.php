<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simulation journal — the undo ledger for the admin-only Demo/Simulation Panel.
 *
 * Every scenario the panel forces on a real vehicle (a "Service Due" oil condition, a
 * "Fault Discovery" complaint ticket) writes ONE row here first, carrying everything the
 * Reset needs to put the fleet back exactly as it was:
 *   - `snapshot` — the original column values that were overwritten (oil scenario), so the
 *     restore is byte-for-byte, not a "best guess reset".
 *   - `maintenance_id` — the ticket the scenario minted (fault scenario), so Reset deletes
 *     precisely that ticket (never a real one).
 *
 * Rows are deleted as they are rolled back, so an empty table == the fleet is back to clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_events', function (Blueprint $table) {
            $table->id();

            $table->string('scenario', 40);                  // oil_alert | fault_discovery
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            // The workflow ticket this scenario created (fault_discovery). Kept as a plain id (not a
            // constrained FK) so Reset can delete the ticket itself without a FK ordering headache.
            $table->unsignedBigInteger('maintenance_id')->nullable();

            $table->json('snapshot')->nullable();            // original values to restore on Reset
            $table->string('label')->nullable();             // human line for the panel's activity log
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('scenario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_events');
    }
};
