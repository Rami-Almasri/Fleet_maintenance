<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental Eligibility of a Maintenance ticket — the inspector's one-time call at the Decide step:
     * may this vehicle be pulled back into rental service BEFORE the maintenance is finished?
     *
     *   false (default) = MANDATORY maintenance. The car is grounded until the workshop completes the
     *                     ticket; no rental (not even a pull-from-maintenance) may release it.
     *   true            = DEFERRABLE. A customer may still take the car; the rental flow pauses the
     *                     ticket (preserving its exact stage) and it resumes when the car returns.
     *
     * The flag lives on the ticket and is respected by every later rental decision (see
     * ContractEligibilityService::maintenanceCheck()). Default false = protect maintenance unless the
     * inspector explicitly opts in.
     */
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->boolean('deferrable_for_rental')->default(false)->after('repair_location');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('deferrable_for_rental');
        });
    }
};
