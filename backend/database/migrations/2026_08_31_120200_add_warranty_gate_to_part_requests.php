<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The receipt the warranty gate leaves on every purchase request.
 *
 * The gate itself lives in WarrantyProcurementGuard: before a part request is created for a car,
 * the system asks whether the manufacturer, dealer or supplier could be responsible for it. These
 * columns are what that question leaves behind, and they exist for one reason — SO THAT THE ANSWER
 * IS AUDITABLE AFTER THE MONEY IS GONE.
 *
 * Without them, a request created on a car with a live warranty is indistinguishable from a request
 * created on a car with none. Six months later, when somebody asks why we paid for a gearbox that
 * was under a 5-year powertrain warranty, the honest answers are three very different things:
 *
 *   warranty_verdict = not_covered   → we asked, and the dealer's terms genuinely excluded it.
 *   warranty_verdict = covered + an override → somebody knowingly chose to buy it anyway, and their
 *                                     name, reason and timestamp are on this row.
 *   warranty_verdict = NULL          → nobody asked. This is the state the feature exists to abolish,
 *                                     and it is left NULL rather than defaulted so that history
 *                                     written before the gate existed is visibly unjudged instead of
 *                                     silently claiming a clean bill of health.
 *
 * THE OVERRIDE IS NOT A LOOPHOLE, IT IS THE AUDIT. There will always be a Thursday afternoon where
 * the car has to move and the dealer will not answer the phone. Refusing outright would mean the
 * rule gets worked around outside the system, where nothing is recorded. So the override is allowed,
 * permission-gated (`warranty.override`), requires a typed reason, and writes a
 * `warranty_procurement_override` event to the vehicle timeline. An override that costs us a claim
 * is then a question with a name attached to it.
 *
 * Evidence classes: warranty_case_id / warranty_verdict are DERIVED (the engine's reading of the
 * warranty rows at that instant, frozen here because the warranty may later be edited or expire).
 * The override quartet is FACT — a person, a sentence, a moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_requests', function (Blueprint $table) {
            /**
             * The case this request was checked against — the coverage review that cleared it, or the
             * open case it was raised alongside. Nullable and nullOnDelete: a request must survive the
             * tidying-up of a case, because it is the money record and the case is only the story.
             */
            // (Positioned after `notes` rather than after the spare-key link on purpose: this
            // migration must not depend on the ordering of another feature's columns.)
            $table->foreignId('warranty_case_id')->nullable()->after('notes')
                ->constrained('warranty_claims')->nullOnDelete();

            // covered | not_covered | unknown — the engine's verdict AT THE MOMENT OF ASKING. Frozen,
            // never recomputed: the point is what was known when the decision to buy was taken.
            $table->string('warranty_verdict', 12)->nullable()->after('warranty_case_id');
            // WHY, as a code. @see App\Support\WarrantyCoverage
            $table->string('warranty_reason_code', 40)->nullable()->after('warranty_verdict');

            // ── The override, when somebody proceeded anyway ────────────────────────────────────
            $table->foreignId('warranty_override_by')->nullable()->after('warranty_reason_code')
                ->constrained('users')->nullOnDelete();
            $table->string('warranty_override_by_name')->nullable()->after('warranty_override_by');
            $table->dateTime('warranty_override_at')->nullable()->after('warranty_override_by_name');
            // Mandatory whenever an override happened — enforced in the guard, not here, because the
            // column is legitimately null on the overwhelming majority of rows.
            $table->text('warranty_override_reason')->nullable()->after('warranty_override_at');

            // "Show me everything we bought while a warranty might have covered it" — the report that
            // turns this feature from a workflow into a number somebody can act on.
            $table->index(['warranty_verdict', 'requested_at'], 'part_requests_warranty_verdict_idx');
        });
    }

    public function down(): void
    {
        Schema::table('part_requests', function (Blueprint $table) {
            $table->dropIndex('part_requests_warranty_verdict_idx');
            $table->dropConstrainedForeignId('warranty_case_id');
            $table->dropConstrainedForeignId('warranty_override_by');
            $table->dropColumn([
                'warranty_verdict', 'warranty_reason_code',
                'warranty_override_by_name', 'warranty_override_at', 'warranty_override_reason',
            ]);
        });
    }
};
