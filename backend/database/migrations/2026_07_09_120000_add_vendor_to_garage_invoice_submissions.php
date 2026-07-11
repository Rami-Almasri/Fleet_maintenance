<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-garage invoice links. A car can pass through several garages over one ticket's life (each fault is
 * routed + transferred between garages — see maintenance_task_assignments). Previously the portal issued a
 * SINGLE link covering the whole ticket's faults; that forces one garage to invoice for another's work.
 *
 * `vendor_id` scopes a link (and its submission) to ONE garage: the link presents only the faults that
 * garage worked on, and it can only bill those. NULL keeps the legacy whole-ticket link (used when a ticket
 * only ever sat at one garage, or has no exploded faults yet), so existing rows and flows are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('garage_invoice_submissions', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('maintenance_id')
                ->constrained('vendors')->nullOnDelete();

            // "Is there a live link for this garage on this ticket?" — the per-garage retirement lookup.
            $table->index(['maintenance_id', 'vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('garage_invoice_submissions', function (Blueprint $table) {
            $table->dropIndex(['maintenance_id', 'vendor_id', 'status']);
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
