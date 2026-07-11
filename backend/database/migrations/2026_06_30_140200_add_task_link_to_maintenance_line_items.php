<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute each Parts/Labor line to the specific FAULT it repairs.
 *
 * `maintenance_line_items` already breaks a ticket's cost into part/labor lines and rolls them up to
 * `maintenances.cost` (recalcLineItemTotals). This adds the missing dimension: which TASK (fault) the
 * line belongs to. With it the financials roll up cleanly at BOTH levels even when 3 faults sit in 3
 * different garages —
 *     line_total  →  maintenance_tasks.parts_cost / labor_cost  (per fault)
 *                 →  maintenances.cost                          (per ticket, the billing container)
 *
 * Nullable on purpose: a legacy lump-sum line (pre-task, or a general ticket charge that isn't tied to
 * one fault) simply has no task — it still counts toward the ticket total, just not toward any one fault.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_line_items', function (Blueprint $table) {
            $table->foreignId('maintenance_task_id')
                ->nullable()
                ->after('maintenance_id')
                ->constrained('maintenance_tasks')
                ->nullOnDelete();   // dropping a fault must not delete its cost history — keep the line on the ticket
            $table->index(['maintenance_task_id', 'kind']); // per-fault parts/labor totals
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_line_items', function (Blueprint $table) {
            $table->dropIndex(['maintenance_task_id', 'kind']);
            $table->dropConstrainedForeignId('maintenance_task_id');
        });
    }
};
