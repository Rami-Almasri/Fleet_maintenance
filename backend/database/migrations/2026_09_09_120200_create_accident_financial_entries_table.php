<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE MONEY ON AN ACCIDENT — an append-only ledger, never a set of columns.
 *
 * ── WHY NOT `estimated_cost` / `approved_amount` / `paid_amount` ON THE CASE ────────────────────
 *
 * Because all three change, and the OLD value is the interesting one. "The garage quoted 12,000, the
 * insurer approved 7,400, we were paid 6,900" is the entire story of an accident's finances, and a
 * schema that overwrites is a schema that can only ever tell you the last third of it. Every dispute
 * with an insurer is an argument about a number that used to be different.
 *
 * So each figure is a ROW, stamped with who recorded it and when, and a revision SUPERSEDES its
 * predecessor rather than replacing it (`superseded_at`, `superseded_by_entry_id`). The current
 * breakdown is the set of live rows; the history is everything, and it is never lost.
 *
 * ── TWO AXES, AND NEITHER IS OPTIONAL ──────────────────────────────────────────────────────────
 *
 *   `phase`  HOW CERTAIN the figure is — estimate → approved → actual → paid. An estimate and a
 *            payment must never be added together, and having them in one column with no phase is
 *            exactly how that happens.
 *   `party`  WHO BEARS IT — insurance | customer | company | other_party | deductible | unresolved.
 *
 * `unresolved` is a first-class party, not a gap. An accident with 9,000 of damage and 4,000 decided
 * is not an accident with 4,000 of damage, and a total that quietly omits the undecided remainder is
 * the most dangerous number this feature could produce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accident_financial_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accident_case_id')->constrained('accident_cases')->cascadeOnDelete();

            $table->string('phase', 24);   // estimate|revised_estimate|approved|actual|paid
            $table->string('party', 24);   // insurance|customer|company|other_party|deductible|unresolved
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('AED');
            $table->text('note')->nullable();

            // Where the figure came from, when it came from something in this system: the repair
            // ticket that produced the actual cost, the invoice that proves the payment, the
            // uploaded insurer decision. Loose links — a hand-typed estimate has none of them.
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->foreignId('vehicle_document_id')->nullable()->constrained('vehicle_documents')->nullOnDelete();
            $table->string('external_ref', 120)->nullable();

            // Supersession, not mutation. The row that replaced this one is named, so the trail reads
            // forwards ("revised from 12,000 to 9,400 by Marwa on the 14th") rather than as a gap.
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_entry_id')->nullable()
                ->references('id')->on('accident_financial_entries')->nullOnDelete();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->index(['accident_case_id', 'phase', 'party']);
            $table->index('superseded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accident_financial_entries');
    }
};
