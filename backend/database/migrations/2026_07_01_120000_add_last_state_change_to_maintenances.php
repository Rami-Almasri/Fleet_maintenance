<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet Maintenance Workflow — "Time in Stage" anchor.
 *
 * The board used to show each card's CREATION date, which is misleading once a ticket has moved
 * through several stages (a card created 3 days ago may have entered its current lane 5 minutes ago).
 * `last_state_change_at` marks the moment a ticket ENTERED its current `workflow_status`, so every
 * surface can show "time in stage" = now − last_state_change_at (and colour it red once a stage
 * overstays its SLA, e.g. the supervisor sitting on a Pending-Dispatch ticket).
 *
 * The column is stamped centrally by the Maintenance model whenever `workflow_status` actually
 * changes (see Maintenance::booted()), so every current + future transition keeps it honest without
 * the workflow service having to remember to set it at each of its ~11 transition sites.
 *
 * Nullable + additive. Existing workflow rows are back-filled to their `updated_at` — the closest
 * available proxy for "when this row last changed" — so cards don't read a blank/zero on day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->timestamp('last_state_change_at')->nullable()->after('workflow_status');
        });

        // Back-fill: seed the anchor for rows already in the pipeline with their last-modified time,
        // the best proxy we have for when they entered their current stage. Only workflow rows carry a
        // workflow_status; sheet/contract rows are left null (they never render a "time in stage").
        DB::table('maintenances')
            ->whereNotNull('workflow_status')
            ->update(['last_state_change_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('last_state_change_at');
        });
    }
};
