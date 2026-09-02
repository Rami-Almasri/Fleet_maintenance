<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE FINANCIAL OBLIGATION AN OPERATION CREATED.
 *
 * A FinancialEvent means one thing: "this operational event generated a cost that may need to reach
 * Odoo." It is NOT a second copy of the operation, and it is NOT a finance module's private record of
 * work that maintenance already recorded. §21 is the rule this table is shaped by — the event REFERENCES
 * the operational data and derives its money from it.
 *
 * ── WHAT IS A REFERENCE AND WHAT IS STORED ──────────────────────────────────────────────────────────
 *
 * REFERENCED (never re-keyed): the source document (source_type/source_id → a MaintenanceInvoice, a
 * Maintenance ticket, a VehicleRegistration), the vehicle, the ticket, the supplier. The parts, their
 * quantities and their prices stay in maintenance_line_items where they already live; financial_event_
 * lines point AT them rather than copying them.
 *
 * STORED, and why each one has to be:
 *
 *   amount            Derived from the source while the event is still unsent, and FROZEN the moment it
 *                     goes to Odoo. It has to be a column, not a live sum: once a document exists in the
 *                     accounting system, what we posted is a historical fact, and a later edit to a
 *                     line item must not silently rewrite what Odoo was told. Before SENDING it is
 *                     refreshed from the source on every validation; after it, it never moves again.
 *
 *   invoice_number    OVERRIDES, not duplicates. Where the source already carries the supplier's paper
 *   invoice_date      (a MaintenanceInvoice has invoice_no and a receipt photo), the event reads it from
 *   attachment_*      there and these stay NULL — §31, no re-entry of information the system has. They
 *                     exist for the sources that carry no such field of their own: a recovery leg on a
 *                     ticket, a taxi claim, a registration renewal. FinancialEvent::resolvedInvoiceNumber()
 *                     and friends are the single readers, and they prefer the source every time.
 *
 *   odoo_* (resolved) A SNAPSHOT of the mappings as they stood when the document was posted. The live
 *                     mapping lives in odoo_mappings and may legitimately be re-pointed later; what this
 *                     event actually sent must remain answerable. Written at send time, never before.
 *
 * ── IDEMPOTENCY ─────────────────────────────────────────────────────────────────────────────────────
 *
 * `idempotency_key` is unique and is what we write into Odoo's own `ref` field (prefix from
 * config('odoo.external_ref_prefix')). Before creating any document the pusher SEARCHES Odoo for that
 * ref. That single mechanism is what makes §23 hold: a bill created in Odoo whose HTTP response was
 * lost is found by the retry and LINKED, never duplicated. The key is generated once at event creation
 * and is immutable thereafter — regenerating it would defeat the entire guarantee.
 *
 * ── ONE SOURCE, ONE EVENT ───────────────────────────────────────────────────────────────────────────
 *
 * unique(source_type, source_id) is deliberate. A garage invoice is one obligation; re-running the
 * builder must find and update the existing event rather than raise a second one that would post the
 * same cost twice. A ticket with two garage invoices is two SOURCES, so two events — which is correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_events', function (Blueprint $table) {
            $table->id();

            // The immutable external identity Odoo is asked about before anything is created.
            $table->string('idempotency_key', 64)->unique();

            // ── The operation that caused this cost ──────────────────────────────────────────────────
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');

            // Denormalised handles so the sync dashboard and the vehicle drawer can filter without
            // resolving the morph. Nullable because not every expense type is vehicle-bound (TAXI).
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('maintenance_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();

            // ── What kind of cost (App\Support\ExpenseType) ──────────────────────────────────────────
            $table->string('expense_type', 32);

            // ── Money ────────────────────────────────────────────────────────────────────────────────
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 8)->default('AED');
            $table->string('description')->nullable();

            // ── Lifecycle (App\Support\FinancialSyncStatus) ──────────────────────────────────────────
            $table->string('status', 24)->default('DRAFT');
            // The blocking reasons as code+params+text triples — see App\Support\FinancialBlockReason.
            $table->json('block_reasons')->nullable();
            $table->timestamp('validated_at')->nullable();

            // ── Supplier paperwork OVERRIDES (see the docblock — usually null) ───────────────────────
            $table->string('invoice_number', 128)->nullable();
            $table->date('invoice_date')->nullable();
            // Same disk/key convention as maintenance_invoices.receipt_photo_* — the existing storage
            // architecture, not a second file system (§29).
            $table->string('attachment_disk', 32)->nullable();
            $table->string('attachment_key')->nullable();

            // ── The mapping snapshot, written at send time ───────────────────────────────────────────
            $table->unsignedBigInteger('odoo_account_id')->nullable();
            $table->unsignedBigInteger('odoo_analytic_account_id')->nullable();
            $table->unsignedBigInteger('odoo_partner_id')->nullable();
            $table->unsignedBigInteger('odoo_journal_id')->nullable();
            $table->string('odoo_document_type', 32)->nullable();

            // ── What Odoo created ────────────────────────────────────────────────────────────────────
            $table->unsignedBigInteger('odoo_document_id')->nullable();
            // The Odoo model the document lives in ('account.move' | 'hr.expense') — needed to build a
            // link and to re-find the record, and NOT inferable from document_type once that becomes
            // configurable.
            $table->string('odoo_document_model', 64)->nullable();
            // Odoo's own human reference for the document (e.g. 'BILL/2026/0042').
            $table->string('odoo_document_reference', 128)->nullable();
            $table->timestamp('synced_at')->nullable();

            // ── Failure ──────────────────────────────────────────────────────────────────────────────
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();

            // ── People ───────────────────────────────────────────────────────────────────────────────
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            // One operational source raises at most one obligation.
            $table->unique(['source_type', 'source_id'], 'fe_source_unique');
            // The sync dashboard's counts and its blocked/failed drill-downs.
            $table->index(['status', 'expense_type'], 'fe_status_type_idx');
            $table->index('vehicle_id', 'fe_vehicle_idx');
            $table->index('maintenance_id', 'fe_maintenance_idx');
            $table->index('vendor_id', 'fe_vendor_idx');
            // Finding an event by what Odoo knows it as — the reconcile path.
            $table->index(['odoo_document_model', 'odoo_document_id'], 'fe_odoo_doc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_events');
    }
};
