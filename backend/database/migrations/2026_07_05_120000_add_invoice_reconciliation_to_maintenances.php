<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garage Invoice Validation Form — the accounting bridge on top of the structured Parts + Labor
 * breakdown (see maintenance_line_items). When the team keys a garage's paper invoice into the
 * itemised form, we now also capture:
 *
 *  - `receipt_total`         — the grand total PRINTED on the garage receipt, entered by hand. The
 *                              itemised lines (parts_total + labor_total) are checked AGAINST this.
 *  - `variance_explanation`  — mandatory when the itemised sum does NOT match the receipt total; the
 *                              server blocks the save without it, so an unexplained mismatch can never
 *                              be posted (see MaintenanceWorkflowService::syncLineItems).
 *  - `reconciliation_status` — the accounting-bridge flag. Set to 'pending' the moment the itemised
 *                              invoice is recorded, so the downstream finance/reconciliation engine
 *                              can pick the ticket up ("Financial-Pending-Reconciliation"); it flips
 *                              to 'reconciled' once finance processes it. NULL = never itemised.
 *  - `reconciliation_flagged_at` — when it entered that pending state (the finance queue clock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->decimal('receipt_total', 12, 2)->nullable()->after('cost_is_itemized');
            $table->text('variance_explanation')->nullable()->after('receipt_total');
            $table->string('reconciliation_status', 24)->nullable()->after('variance_explanation');
            $table->timestamp('reconciliation_flagged_at')->nullable()->after('reconciliation_status');

            // The finance engine sweeps for tickets waiting to be reconciled — keep that lookup cheap.
            $table->index('reconciliation_status');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex(['reconciliation_status']);
            $table->dropColumn([
                'receipt_total',
                'variance_explanation',
                'reconciliation_status',
                'reconciliation_flagged_at',
            ]);
        });
    }
};
