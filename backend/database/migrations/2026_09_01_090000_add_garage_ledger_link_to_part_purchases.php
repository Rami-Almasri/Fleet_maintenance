<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE FORWARD DOOR FROM A GARAGE'S BILL INTO THE PARTS LEDGER.
 *
 * A part fitted by a garage was, until now, a money row and nothing else: maintenance_line_items
 * (kind=part) held the price, and nothing downstream ever learned that a physical part had changed
 * hands. It never became a purchase, never reached the storehouse, never became a component, never
 * carried a warranty, and never appeared in the part's own history. A supplier-bought battery and a
 * garage-fitted battery were the same object described in two unconnected universes.
 *
 * `part_purchases` already had the vocabulary for this — purchase_source='garage' has existed since
 * the table was created, and CostSourceResolver already knows that a garage-sourced purchase's
 * document is the garage's bill. Only the write was missing. These two columns/indexes are what let
 * it be written SAFELY, and neither invents a concept:
 *
 * ── maintenance_invoice_id ───────────────────────────────────────────────────────────────────────
 * A purchase reached its bill only through maintenance_line_item_id. That link does not survive an
 * edit: MaintenanceInvoiceService::syncLineItems DELETES and RECREATES every work line on each save,
 * so line ids are not stable and a re-save would orphan the purchase and then duplicate it. Holding
 * the invoice directly gives the ledger a key that outlives a line rewrite, which is what makes
 * GarageLineItemLedgerService::syncInvoice able to reconcile rather than re-create.
 *
 * Deliberately NOT a foreign key, matching maintenance_line_items.part_source_id: the historical
 * rows this backfills are ticket-level lines with no invoice at all, and nullable-with-no-FK is how
 * this schema has consistently said "this answer may legitimately not exist".
 *
 * ── unique(maintenance_line_item_id) ─────────────────────────────────────────────────────────────
 * The idempotency guarantee, enforced by the database rather than by remembering to check. Receiving
 * or backfilling the same line twice cannot produce two purchases for one physical part. MySQL
 * permits many NULLs in a unique index, so every purchase that has no line (bought, not yet fitted)
 * is unaffected — which is the majority of the table.
 *
 * Non-destructive: two additive columns/indexes, no data rewritten, no column dropped or retyped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('maintenance_invoice_id')->nullable()->after('maintenance_task_id');
            $table->index('maintenance_invoice_id', 'pp_maintenance_invoice_idx');
            $table->unique('maintenance_line_item_id', 'pp_line_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('part_purchases', function (Blueprint $table) {
            $table->dropUnique('pp_line_item_unique');
            $table->dropIndex('pp_maintenance_invoice_idx');
            $table->dropColumn('maintenance_invoice_id');
        });
    }
};
