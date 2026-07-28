<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for maintenance_reason_id — so a system-derived category is always
 * distinguishable from an original one, and the Repair-Intelligence backfill can
 * never silently overwrite historical truth.
 *
 *   reason_source     'sheet'    — came from the sheet import (its own MAIN column)
 *                     'backfill' — system-matched later by repair-intel:backfill-reasons
 *                     'manual'   — a human set it
 *   reason_matched_at when the category was (re)derived by a system matcher
 *
 * This is a provenance flag on a DERIVED field, not a new source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->string('reason_source', 16)->nullable()->after('maintenance_reason_id');
            $table->timestamp('reason_matched_at')->nullable()->after('reason_source');
        });

        // Existing categorized rows were set during the sheet import → label them 'sheet'
        // so backfilled rows (stamped later) stay cleanly separable. Blanks stay NULL.
        DB::table('maintenances')
            ->whereNotNull('maintenance_reason_id')
            ->update(['reason_source' => 'sheet']);
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['reason_source', 'reason_matched_at']);
        });
    }
};
