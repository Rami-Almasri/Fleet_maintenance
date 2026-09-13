<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wiring the accident case into the systems that already exist, rather than building copies of them.
 *
 *   maintenances.accident_case_id       the repair is a CHILD of the case. One accident can raise
 *                                       several tickets; each runs the ordinary workflow untouched.
 *   vehicle_documents.accident_case_id  the police report, the photos, the insurer's decision — filed
 *                                       in the document store the Mulkiya and the warranty dossier
 *                                       already use, not a second one. (Warranty paperwork proved
 *                                       this table handles a DOSSIER as happily as a SLOT; accident
 *                                       kinds behave the same way — nothing supersedes anything.)
 *   vehicle_log_events.accident_case_id the timeline. The car's history is one append-only trail and
 *                                       an accident belongs ON it, beside the repair it caused —
 *                                       not in a parallel table nobody merges. The column exists so
 *                                       the CASE's own timeline is one indexed read of the same rows.
 *
 * Plus one reason code: a repair raised from an accident is dispatched with `accident_damage` rather
 * than the generic `visible_damage`, so accident-driven workshop spend is countable at the source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->foreignId('accident_case_id')->nullable()->after('id')
                ->constrained('accident_cases')->nullOnDelete();
            $table->index('accident_case_id');
        });

        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->foreignId('accident_case_id')->nullable()->after('warranty_claim_id')
                ->constrained('accident_cases')->nullOnDelete();
            $table->index('accident_case_id');
        });

        Schema::table('vehicle_log_events', function (Blueprint $table) {
            $table->foreignId('accident_case_id')->nullable()->after('maintenance_task_id')
                ->constrained('accident_cases')->nullOnDelete();
            // The FK-free twin, for exactly the reason maintenance_ref carries one: a deleted case
            // must leave its events readable, and `accident_case_id` is nulled by the cascade.
            $table->unsignedBigInteger('accident_ref')->nullable()->after('accident_case_id');
            $table->index('accident_case_id');
        });

        // Idempotent: the office may have added it by hand from the reason editor already.
        if (! DB::table('request_reasons')->where('door', 'dispatch')->where('code', 'accident_damage')->exists()) {
            DB::table('request_reasons')->insert([
                'door'       => 'dispatch',
                'code'       => 'accident_damage',
                'label'      => 'Accident damage repair',
                'label_ar'   => 'إصلاح أضرار حادث',
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('vehicle_log_events', function (Blueprint $table) {
            $table->dropForeign(['accident_case_id']);
            $table->dropColumn(['accident_case_id', 'accident_ref']);
        });
        Schema::table('vehicle_documents', function (Blueprint $table) {
            $table->dropForeign(['accident_case_id']);
            $table->dropColumn('accident_case_id');
        });
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropForeign(['accident_case_id']);
            $table->dropColumn('accident_case_id');
        });

        DB::table('request_reasons')->where('door', 'dispatch')->where('code', 'accident_damage')->delete();
    }
};
