<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TWO THINGS LEEN DECIDES WHEN SHE RECALLS A CAR — and neither of them is derivable.
 *
 *   service_location   WHERE the oil gets changed, and therefore WHO is told. In our own parking the
 *                      Inspector (Abu Maroof) does it; at a garage the Supervisors arrange it. The
 *                      driver is told either way — he is the one fetching the car. This is an
 *                      operational choice about a specific car on a specific day, so it is stored
 *                      rather than inferred from anything.
 *
 *   request_adopted    Whether the inspection request this recall points at was ALREADY THERE —
 *                      typically the system's own "routine check overdue" request sitting in
 *                      /inspection-review. When Leen says "yes, do the test too", the oil change is
 *                      added to THAT request instead of filing a second one for the same car.
 *
 *                      This flag is what protects it afterwards: the oil lifecycle closes the
 *                      requests it RAISED once the oil is done, and an adopted request must never be
 *                      closed that way — it belongs to the system's own test, which the oil change
 *                      has not performed. Without this column, finishing the oil would silently
 *                      delete a safety check nobody cancelled.
 *
 * `service_location` is a plain short string, not an enum: the set is validated in one place
 * (ContractOilDecision::LOCATIONS) and a future third option must not need a schema migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->string('service_location', 16)->nullable()->after('test_required');
            $table->boolean('request_adopted')->default(false)->after('service_location');
        });
    }

    public function down(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->dropColumn(['service_location', 'request_adopted']);
        });
    }
};
