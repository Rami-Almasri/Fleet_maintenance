<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each snapshot was computed over.
 *
 * Added after a rebuild of the `maintenance_signatures` projection moved the first-time-fix sample
 * from 31,782 to 33,040 inside one working session — no repair changed, the derived corpus was
 * simply regenerated with `occurred_at` backfilled.
 *
 * A KPI baseline that cannot say what it was measured over is not falsifiable: any later movement
 * can be attributed to whichever explanation is most convenient. This is the same reasoning as the
 * ontology's run provenance — a number is only defensible alongside the state of its inputs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_snapshots', function (Blueprint $table) {
            $table->json('source_state')->nullable()->after('metrics');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_snapshots', function (Blueprint $table) {
            $table->dropColumn('source_state');
        });
    }
};
