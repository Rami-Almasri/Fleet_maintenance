<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Handover Workflow — align the custody-condition columns with the contract the API
 * actually validates.
 *
 * `MaintenanceWorkflowController::pause()` / `resume()` validate exterior_condition and
 * interior_condition as `max:255`, but the table created them as varchar(50). Anything between 51
 * and 255 characters therefore passed validation and then threw SQLSTATE[22001] on INSERT — and
 * because pauseForRental()/resumeMaintenance() wrap the handover write in a best-effort
 * try/catch + report() (so a handover hiccup can never block the pause itself), the transition
 * committed while the custody record was silently discarded and last_pause_handover_id stayed null.
 *
 * That row is the evidence of what condition the car was in when it was handed to a customer and
 * when it came back, so losing it silently is a liability gap rather than a cosmetic one. Widening
 * to 255 honours the validated contract without narrowing what operators can already type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_handovers', function (Blueprint $table) {
            $table->string('exterior_condition', 255)->change();
            $table->string('interior_condition', 255)->change();
        });
    }

    public function down(): void
    {
        // Narrowing back would truncate any row written since this ran; trim explicitly so the
        // rollback is lossy-but-legal rather than a hard failure.
        \DB::table('maintenance_handovers')->update([
            'exterior_condition' => \DB::raw('LEFT(exterior_condition, 50)'),
            'interior_condition' => \DB::raw('LEFT(interior_condition, 50)'),
        ]);

        Schema::table('maintenance_handovers', function (Blueprint $table) {
            $table->string('exterior_condition', 50)->change();
            $table->string('interior_condition', 50)->change();
        });
    }
};
