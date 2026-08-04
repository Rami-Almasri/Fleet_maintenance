<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S4 — stop a contract from taking repair history with it when it goes.
 *
 * ── THE PROMISE THAT WASN'T KEPT ────────────────────────────────────────────────────────────────
 * OfficeManagerSync::clearSheetData() states, in as many words, that hand-entered workshop events
 * (origin = 'manual') "must survive an API re-import", and it honours that in its own DELETE by
 * scoping to SHEET_ORIGINS. The very next line then calls:
 *
 *     Contract::withTrashed()->where('origin', 'sheet')->forceDelete();
 *
 * forceDelete() issues a real DELETE, so MySQL cascades through maintenances.contract_id — and a
 * cascade respects NEITHER SoftDeletes NOR origin. Every manual ticket linked to a sheet contract
 * would be hard-deleted, taking its tasks, line items, invoices, signatures, cost adjustments and
 * garage invoice submissions with it, because those are all CASCADE too. The guarantee was broken
 * by the schema, not by the code — which is why reading clearSheetData() alone never reveals it.
 *
 * ── WHY SET NULL IS THE RIGHT ANSWER, NOT A WORKAROUND ──────────────────────────────────────────
 * A ticket's link to a contract is CONTEXTUAL — it records which rental the car was on when the
 * fault appeared. The repair itself is not owned by that rental and has to outlive it: the car was
 * genuinely in the shop, the garage was genuinely paid, and the fleet's comeback rate is computed
 * from exactly these rows. This also matches "Rental is King" and maintenance staying open across a
 * rental. Losing the contract should cost the ticket its context, never its existence.
 *
 * Every OTHER foreign key pointing into `maintenances` is already SET NULL. This one was the lone
 * outlier, and it was the only one that could destroy data.
 *
 * ── BLAST RADIUS AT THE TIME OF WRITING: ZERO ───────────────────────────────────────────────────
 *     sheet-origin contracts remaining ............ 0   (the sheet -> API migration is complete)
 *     maintenances carrying any contract_id ....... 5
 *     manual tickets linked to a sheet contract ... 0
 *
 * So this disarms a trap rather than repairing damage. It is worth doing anyway precisely BECAUSE
 * nothing is exposed today: the cost is one migration now, versus silent, unnoticed loss of repair
 * and cost history the first time sheet contracts are imported again and clearSheetData() re-runs.
 *
 * The column is already nullable, so no data change is required — only the referential action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // Laravel keeps the underlying index when the constraint is dropped, so the FK can be
            // re-declared immediately without a separate index rebuild.
            $table->dropForeign('maintenances_contract_id_foreign');
            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Restores the destructive behaviour. Reversible for correctness, not because reverting is
        // ever the right call — rolling this back re-arms the data-loss path described above.
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropForeign('maintenances_contract_id_foreign');
            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->cascadeOnDelete();
        });
    }
};
