<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair Capture → Component Ledger: the provenance a component row needs once a part can reach a
 * car WITHOUT a purchase behind it.
 *
 * Until now `vehicle_components` was only ever written from a PartPurchase, so "where did this ROW
 * come from" and "where did this PART come from" happened to be the same question. They are not, and
 * once a `replace / alternator` capture action can create a component the difference is load-bearing:
 *
 *   evidence_channel — HOW WE LEARNED the part is fitted. Decides how much the row is trusted and
 *                      whether it may carry a cost. `purchase` has a purchase row, a supplier and a
 *                      price; `repair_capture` is a technician's report with no money attached.
 *
 *   acquisition      — WHERE THE PART CAME FROM and who paid. Decides warranty and ownership. A
 *                      warranty replacement, a part the garage supplied and a part the customer
 *                      brought are three different commercial facts.
 *
 * THEY ARE ORTHOGONAL, WHICH IS WHY THERE ARE TWO COLUMNS. A warranty replacement recorded through a
 * zero-AED purchase row and one recorded through a capture action are the SAME acquisition and
 * DIFFERENT evidence. Collapsing them into one enum makes that unsayable — and the garage-routing
 * work that wants to ask "is this part still under warranty from the shop that fitted it?" would end
 * up reading the trust axis to answer a commercial question.
 *
 * `source` IS DELIBERATELY NOT TOUCHED. It already means something else — how the ROW came to exist
 * (workflow | manual | legacy_backfill) — and is read by VehicleComponent::isLegacyBackfill(),
 * components:verify and ComponentsShadowAudit. Repurposing it would silently change what those three
 * measure. A capture-created component is still `source = workflow`: it arrived through the
 * maintenance workflow, just through a different door.
 *
 * `source_maintenance_task_id` is what makes de-duplication possible. Capture and purchase-install
 * are not ordered — the same physical replacement can arrive from either side, in either order — and
 * the question both paths must ask first is "has this task already produced a component in this
 * slot?". That question needs the task on the component row; today it exists only on the event.
 *
 * NOT stored: the maintenance_task_actions row id. A re-capture DELETES and rewrites its action rows
 * (RepairCaptureService::capture), so an action id on a long-lived component would dangle within
 * minutes. The task id is stable, and combined with component_catalog_id it identifies the same
 * thing.
 *
 * BACKFILL IS EXACT, NOT INFERRED. Every existing row was written by installFromPurchase, so every
 * existing row is `purchase` / `purchased` by construction, and its task is readable straight off the
 * purchase it came from. This restates known facts; it does not guess at history. New rows get no
 * column default on purpose — the caller must state both, so a future write path cannot inherit
 * "purchased" by forgetting to.
 *
 * Portable across MariaDB (local) and MySQL 8 (prod): nullable strings and indexes, no CHECK
 * constraints, no enum type, and the one FK is added the same way the table's existing ones are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            // purchase | repair_capture | garage_portal | import | manual
            $table->string('evidence_channel', 24)->nullable()->after('source');

            // purchased | warranty_replacement | garage_supplied | customer_supplied | unknown
            $table->string('acquisition', 24)->nullable()->after('evidence_channel');

            // The ticket line this component was installed for. nullOnDelete, matching every other
            // source_* FK on this table: the component outlives its paperwork.
            $table->foreignId('source_maintenance_task_id')->nullable()->after('source_line_item_id')
                ->constrained('maintenance_tasks')->nullOnDelete();

            // The dedupe lookup, run on every capture and every purchase install.
            $table->index(['source_maintenance_task_id', 'component_catalog_id'], 'vc_task_catalog_idx');

            // "How much of the ledger is technician-reported?" — the coverage question, asked by
            // components:verify and by any cost metric that has to exclude uncosted rows.
            $table->index('evidence_channel', 'vc_evidence_channel_idx');
        });

        // ── Backfill: three restatements of what is already true, not three inferences ──────────
        DB::table('vehicle_components')->whereNull('evidence_channel')->update(['evidence_channel' => 'purchase']);
        DB::table('vehicle_components')->whereNull('acquisition')->update(['acquisition' => 'purchased']);

        // The task is on the purchase; copy it across rather than leaving the dedupe key blind to
        // every component that already exists.
        DB::table('vehicle_components AS vc')
            ->join('part_purchases AS pp', 'pp.id', '=', 'vc.source_part_purchase_id')
            ->whereNull('vc.source_maintenance_task_id')
            ->whereNotNull('pp.maintenance_task_id')
            ->update(['vc.source_maintenance_task_id' => DB::raw('pp.maintenance_task_id')]);
    }

    public function down(): void
    {
        Schema::table('vehicle_components', function (Blueprint $table) {
            $table->dropIndex('vc_task_catalog_idx');
            $table->dropIndex('vc_evidence_channel_idx');
            $table->dropConstrainedForeignId('source_maintenance_task_id');
            $table->dropColumn(['evidence_channel', 'acquisition']);
        });
    }
};
