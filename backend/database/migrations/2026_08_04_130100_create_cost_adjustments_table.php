<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cost_adjustments — the FOURTH source document, and the last hole in the audit trail.
 *
 * Every dirham on a ticket must trace to something. Three sources already exist:
 *
 *     supplier invoice  (part_invoices)        — what a part cost
 *     garage invoice    (maintenance_invoices) — what fitting it cost
 *     credit note       (part_returns)         — a part that went back
 *
 * Everything else — a labour refund the garage agreed verbally, a goodwill discount, a correction to a
 * mis-keyed figure, a write-off — used to be entered as an unexplained number, or not entered at all.
 * That is exactly the untraceable money this table removes: an adjustment IS a document. It cannot be
 * created without a reason code, a written explanation, and a named approver, and it can carry a photo of
 * whatever backs it (a WhatsApp screenshot, a signed note).
 *
 * Like every other money source it lands on the ONE ledger — it writes a maintenance_line_items row, so
 * the ticket total, the fault cost and every downstream report pick it up with no special-casing.
 *
 * `applies_to` is what keeps returns honest: a part return credits PARTS and must never touch labour, so
 * a labour refund has to be recorded deliberately, as an adjustment with applies_to = labour, by someone
 * willing to put their name on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            // Optional: an adjustment aimed at ONE fault. Left null it is a ticket-wide correction.
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // Which band of the ticket this moves — and therefore which line kind it writes.
            $table->string('applies_to', 10);      // 'parts' | 'labour' | 'other'
            // 'credit' takes money OFF the ticket, 'debit' adds it on. Stored separately from `amount`
            // so the amount is always a plain positive number the user typed.
            $table->string('direction', 6);        // 'credit' | 'debit'
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('AED');

            // WHY. Both are mandatory at the API layer: an adjustment with no explanation is precisely the
            // unauditable number this table exists to abolish.
            $table->string('reason_code', 24);
            $table->text('reason_note');

            // Optional backing evidence + a free-text reference to whatever exists outside the system.
            $table->string('reference', 120)->nullable();
            $table->string('photo_disk', 20)->nullable();
            $table->string('photo_key')->nullable();

            // The garage this concerns, when it concerns one (a labour refund is owed BY someone).
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            // The line this adjustment wrote onto the ticket — the money itself.
            $table->foreignId('line_item_id')->nullable()->constrained('maintenance_line_items')->nullOnDelete();

            // Money moved without a supplier's or garage's paper needs a name against it.
            $table->foreignId('approved_by')->constrained('users');
            $table->string('approved_by_name');
            $table->timestamp('approved_at');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['maintenance_id', 'applies_to']);
            $table->index('reason_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_adjustments');
    }
};
