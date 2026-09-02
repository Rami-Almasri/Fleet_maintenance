<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE MONEY SIDE OF A RECOVERY, ON THE TICKET THAT ALREADY HOLDS THE RECOVERY.
 *
 * Recovery is NOT a module in FleetView and must not become one (§7). A tow is a LEG of a maintenance
 * ticket: `recovery_unit_name` / `recovery_unit_phone` already live on `maintenances`, and
 * MaintenanceWorkflowService::dispatchRecovery() is the single action that starts one. What was missing
 * was only the money — the towing company bills us, and until now there was nowhere to record it.
 *
 * So these columns extend the leg that exists rather than creating a second Recovery entity. §7's
 * "extend it naturally" is the literal instruction, and the alternative (a recovery_jobs table) would
 * split one real-world event across two records that would then have to be kept in step forever.
 *
 * ── WHY THE COLUMNS ARE HERE AND NOT ON financial_events ────────────────────────────────────────────
 *
 * financial_events derives everything it can from the operational record, and stores only what the
 * operational record cannot answer. For a garage bill, the invoice number lives on maintenance_invoices,
 * so the event's own column stays null. A recovery had NO operational home for its cost at all — so this
 * migration gives it one, on the ticket, and the event reads it from there like every other source. The
 * financial layer stays a reader; the operation stays the source of truth (§21).
 *
 * ── recovery_vendor_id IS SEPARATE FROM vendor_id ──────────────────────────────────────────────────
 *
 * `maintenances.vendor_id` is the GARAGE that repairs the car. The recovery company that towed it there
 * is a different business that sends a different bill, and conflating them would put the tow on the
 * garage's account. dispatchRecovery() already accepts a vendor for the destination garage, which is
 * precisely why the towing company needs its own column rather than borrowing that one.
 *
 * Additive and nullable throughout — no existing row changes meaning, and a ticket with no tow simply
 * leaves them null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // What the tow cost, and who to pay for it.
            $table->decimal('recovery_cost', 12, 2)->nullable()->after('recovery_unit_phone');
            $table->string('recovery_currency', 8)->nullable()->after('recovery_cost');
            $table->unsignedBigInteger('recovery_vendor_id')->nullable()->after('recovery_currency');

            // Does this tow require a supplier invoice before it may be posted? Nullable rather than
            // boolean-default-true because "nobody has said yet" is a real and common state on a leg
            // that was dispatched in an emergency, and it reads differently from "no invoice needed".
            $table->boolean('recovery_invoice_required')->nullable()->after('recovery_vendor_id');

            // The towing company's own paperwork.
            $table->string('recovery_invoice_no', 128)->nullable()->after('recovery_invoice_required');
            $table->date('recovery_invoice_date')->nullable()->after('recovery_invoice_no');
            // Same disk/key convention as maintenance_invoices.receipt_photo_* — the existing storage
            // architecture, not a second file system (§29).
            $table->string('recovery_invoice_disk', 32)->nullable()->after('recovery_invoice_date');
            $table->string('recovery_invoice_key')->nullable()->after('recovery_invoice_disk');

            $table->index('recovery_vendor_id', 'maint_recovery_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex('maint_recovery_vendor_idx');
            $table->dropColumn([
                'recovery_cost',
                'recovery_currency',
                'recovery_vendor_id',
                'recovery_invoice_required',
                'recovery_invoice_no',
                'recovery_invoice_date',
                'recovery_invoice_disk',
                'recovery_invoice_key',
            ]);
        });
    }
};
