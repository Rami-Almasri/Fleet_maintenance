<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Awaiting-Invoice SLA clock. When a repair is signed off but the paper invoice isn't ready, the ticket
 * enters the new `awaiting_invoice` workflow state (car already back in service) and `awaiting_invoice_since`
 * stamps when — the anchor the daily 3-day overdue scan and the /invoices/pending-submission tracker read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->timestamp('awaiting_invoice_since')->nullable()->after('invoice_requested_by');
            // The tracker + overdue scan filter on the workflow state; keep that lookup cheap.
            $table->index(['workflow_status', 'awaiting_invoice_since']);
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex(['workflow_status', 'awaiting_invoice_since']);
            $table->dropColumn('awaiting_invoice_since');
        });
    }
};
