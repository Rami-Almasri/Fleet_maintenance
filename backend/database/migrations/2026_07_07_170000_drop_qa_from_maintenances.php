<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the Quality-Assurance gate columns. The QA feature (a complex-repair gate with test rounds)
 * was retired — every repaired car now goes straight to the single-shot final re-inspection — so these
 * columns are dead. Guarded with hasColumn so it is a no-op on a schema where the add-migration never ran.
 */
return new class extends Migration
{
    private const COLS = ['requires_qa', 'qa_rounds', 'qa_started_at', 'qa_completed_at'];

    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            foreach (self::COLS as $col) {
                if (Schema::hasColumn('maintenances', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenances', 'requires_qa')) {
                $table->boolean('requires_qa')->default(false)->after('odometer_flags');
            }
            if (! Schema::hasColumn('maintenances', 'qa_rounds')) {
                $table->json('qa_rounds')->nullable()->after('requires_qa');
            }
            if (! Schema::hasColumn('maintenances', 'qa_started_at')) {
                $table->timestamp('qa_started_at')->nullable()->after('qa_rounds');
            }
            if (! Schema::hasColumn('maintenances', 'qa_completed_at')) {
                $table->timestamp('qa_completed_at')->nullable()->after('qa_started_at');
            }
        });
    }
};
