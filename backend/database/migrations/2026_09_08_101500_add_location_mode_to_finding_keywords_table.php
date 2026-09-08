<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other half of the "does this type have a where?" switch — for the words that have no type row.
 *
 * add_location_mode_to_catalogs gave `fault_catalog` and `damage_catalog` the column, on the
 * assumption that every word the inspector can tap is one of those rows. It is not. The picker's
 * vocabulary is the KEYWORD LIBRARY (`finding_keywords`, curated on Control Desk → Fault keywords),
 * and 22 of its words match no catalog row at all — "Sensor failure", "Water pump failure",
 * "Refrigerant leak", "Broken spring", "Key / immobiliser fault" and the rest. Those words still get
 * a location policy at intake time (FaultLocationService falls through to their category), but there
 * was nowhere to STORE a different answer and so nowhere to show them on Control Desk → Where on the
 * car → Fault types. A curator could not see them, let alone re-grade them.
 *
 * Nullable, unlike the catalogs' `default('optional')`: null means "nobody has decided, use the
 * authored chain", which is exactly what makes the Fault types tab able to say whether a mode was
 * chosen by a person or implied by the category. See [[traceability-visibility-requirement]].
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finding_keywords') || Schema::hasColumn('finding_keywords', 'location_mode')) {
            return;
        }

        Schema::table('finding_keywords', function (Blueprint $table) {
            $table->string('location_mode', 12)->nullable()->after('risk');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('finding_keywords') && Schema::hasColumn('finding_keywords', 'location_mode')) {
            Schema::table('finding_keywords', function (Blueprint $table) {
                $table->dropColumn('location_mode');
            });
        }
    }
};
