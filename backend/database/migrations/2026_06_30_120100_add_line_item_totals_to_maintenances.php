<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parts/Labor roll-up cached on the ticket. `maintenances.cost` stays the canonical GRAND TOTAL
 * (so nothing downstream changes); these split it so the board / reports can show the breakdown
 * without re-summing the line items every read.
 *
 *   cost          = parts_total + labor_total   (once cost_is_itemized)
 *   parts_total   = Σ line_total where kind = 'part'
 *   labor_total   = Σ line_total where kind = 'labor'
 *
 * `cost_is_itemized` distinguishes a cost that was built from structured line items (true) from a
 * legacy lump-sum typed straight into `cost` (false) — so we never silently overwrite a manual
 * figure, and a report can tell itemised spend from estimated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->decimal('parts_total', 12, 2)->nullable()->after('cost');
            $table->decimal('labor_total', 12, 2)->nullable()->after('parts_total');
            $table->boolean('cost_is_itemized')->default(false)->after('labor_total');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn(['parts_total', 'labor_total', 'cost_is_itemized']);
        });
    }
};
