<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accountability audit trail of findings on a maintenance ticket. One JSON array; each entry is a
 * single finding stamped with its SOURCE — `inspector` (what Abu Maroof diagnosed on the test drive)
 * or `garage` (what the workshop discovered during the repair) — plus who added it and when. The
 * array travels with the ticket through every stage (Dispatch → Ready), so the original findings are
 * always visible alongside any added later, clearly separated by source.
 *
 *   [{ "text": "Brake noise", "source": "inspector", "severity": "high", "by": "Abu Maroof", "at": "..." }, ...]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->json('findings')->nullable()->after('test_drive_report');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('findings');
        });
    }
};
