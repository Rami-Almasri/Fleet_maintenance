<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SALES GATE on a recall.
 *
 * Recalling a car is not something the fleet does TO a customer: somebody in Sales has to reach
 * them and agree the return first. Until that agreement exists there is no collection to make, so
 * a recall now waits here — and the driver pool hears nothing at all.
 *
 * What each column is for:
 *   sales_confirmed_at/_by/_by_name  the confirmation itself, stamped with a real authenticated
 *                                    actor. This single timestamp IS the gate: null = waiting for
 *                                    Sales, set = the collection may be raised. Written once.
 *   sales_note                       what Sales actually said (e.g. "customer brings it Thursday").
 *   collection_task_id              the ONE LogisticsTask this recall produced. A link, not a copy:
 *                                    every driver-side fact (claimed, picked up, delivered) stays
 *                                    owned by the logistics lane and is read through here. It is
 *                                    also what makes "Sales OK" idempotent — a decision that already
 *                                    points at an open move never raises a second one.
 *   test_required                    the dispatcher's OPERATIONAL instruction for after collection.
 *                                    Nullable = nobody has said yet. Note there is deliberately NO
 *                                    `oil_change_required` column: the oil change is not a choice
 *                                    anyone gets to record, it is why this recall exists at all, so
 *                                    it is derived (always true) rather than stored where a later
 *                                    write could turn it off. See ContractOilDecision::requiredActions().
 *
 * `dateTime` (not `timestamp`) on purpose — MariaDB silently attaches ON UPDATE CURRENT_TIMESTAMP to
 * the latter, which would let an unrelated save rewrite when Sales confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->dateTime('sales_confirmed_at')->nullable()->after('note');
            $table->foreignId('sales_confirmed_by')->nullable()->after('sales_confirmed_at')
                ->constrained('users')->nullOnDelete();
            $table->string('sales_confirmed_by_name', 120)->nullable()->after('sales_confirmed_by');
            $table->string('sales_note', 1000)->nullable()->after('sales_confirmed_by_name');

            // Loose link, mirroring how logistics_tasks itself references vehicles/users: a purged
            // movement must never cascade into the decision that is the audit record of the recall.
            $table->unsignedBigInteger('collection_task_id')->nullable()->after('sales_note');
            $table->index('collection_task_id');

            $table->boolean('test_required')->nullable()->after('collection_task_id');
        });

        $this->adoptRecallsAlreadyInFlight();
    }

    /**
     * Backfill: a recall that ALREADY has a driver collection out was dispatched under the previous
     * rule, where deciding "recall" raised the collection immediately. Under the new rule the gate
     * is what releases the driver — so without this, those cars would come back tomorrow morning
     * reading "Waiting for Sales OK" while a driver is already in the pool holding the job. That
     * contradiction is worse than either state on its own.
     *
     * The confirmation is stamped with NO user and an explicit note, because nobody actually
     * confirmed anything: this is the system adopting a decision the old rule made implicitly, and
     * the audit trail must say exactly that rather than putting a name to it.
     */
    private function adoptRecallsAlreadyInFlight(): void
    {
        $open = DB::table('contract_oil_decisions')
            ->where('decision', 'recall')
            ->whereNull('settled_at')
            ->whereNull('sales_confirmed_at')
            ->get(['id', 'vehicle_id']);

        foreach ($open as $row) {
            $task = DB::table('logistics_tasks')
                ->whereNull('completed_at')
                ->where('vehicle_id', $row->vehicle_id)
                ->where('notes', 'like', 'Oil recall%')
                ->orderByDesc('id')
                ->first(['id']);

            if (! $task) {
                continue;   // never dispatched → it genuinely belongs at the gate
            }

            DB::table('contract_oil_decisions')->where('id', $row->id)->update([
                'sales_confirmed_at'      => now(),
                'sales_confirmed_by'      => null,
                'sales_confirmed_by_name' => null,
                'sales_note'              => 'Adopted on upgrade — the collection was already dispatched'
                                           . ' under the previous rule, before the Sales gate existed.',
                'collection_task_id'      => $task->id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('contract_oil_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_confirmed_by');
            $table->dropIndex(['collection_task_id']);
            $table->dropColumn([
                'sales_confirmed_at',
                'sales_confirmed_by_name',
                'sales_note',
                'collection_task_id',
                'test_required',
            ]);
        });
    }
};
