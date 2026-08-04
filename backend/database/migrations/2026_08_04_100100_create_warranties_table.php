<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * warranties — a PROMISE SOMEBODY MADE US, recorded so we can hold them to it.
 *
 * The fleet already knew warranty as a derived date column: vehicle_components.warranty_until,
 * computed as install date + the catalog's default months, invisible and unmanageable. That is not
 * a warranty. A warranty is an obligation with a counterparty, a window, an expiry that can arrive
 * two different ways, and a claim you either made in time or lost. None of that fits in one date.
 *
 * TWO KINDS, ONE TABLE, because everything except the anchor is identical:
 *
 *   kind=part   — the SUPPLIER promised the part itself. "This AC compressor was bought on 1 May
 *                 with 12 months / 20,000 km cover." It fails → the supplier replaces the part.
 *
 *   kind=repair — the GARAGE promised the fix. "We repaired 'AC not cooling' at Garage X on 1 May,
 *                 warranted 6 months / 20,000 km." The SAME FAULT comes back inside the window →
 *                 the garage redoes the job free, whatever part turns out to be at fault.
 *
 * They are not the same claim against the same person, which is why both exist. A repair warranty
 * can be live while the part warranty under it has expired, and vice versa.
 *
 * TWO EXPIRY LEGS, WHICHEVER COMES FIRST. This is the whole reason the table exists. A warranty is
 * bounded by time AND by distance, and in a rental fleet distance is usually the binding one — a
 * car doing 6,000 km a month burns a 20,000 km warranty in ten weeks while its 12-month leg still
 * looks healthy. Storing only months, as the catalog did, silently grants cover we were never
 * given. Either leg may be null (a time-only or distance-only promise); with both null the
 * warranty never expires, which is legitimate and rare.
 *
 * Evidence classes (per the Evidence Layer governance rule):
 *   FACT      — starts_on, start_odometer, months, km, provider, the anchor FKs, every claim row.
 *               All of it copied from a document or typed by the person who made the deal.
 *   DERIVED   — expires_on (starts_on + months) and expires_at_km (start_odometer + km) are stored
 *               because they are pure functions of facts on this row and we index/range-scan them.
 *               Whether a warranty is live TODAY is NOT stored: it depends on the vehicle's current
 *               odometer, which moves. It is computed on read, every read.
 *   JUDGEMENT — none. A warranty is a contract, not an opinion. The one judgement in the vicinity
 *               (was this claim worth making?) lives on the claim, as its outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();

            // 'part' | 'repair' — decides which anchor below is required. See the model's rules.
            $table->string('kind', 10)->index();

            // The car is the one thing both kinds always have, and the axis everyone browses by.
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // --- anchors: what exactly is covered ---------------------------------------------
            // kind=part: at least one of these two. A purchase is the money event; a component is
            // the physical thing. A part can be bought without our asset layer ever seeing it, and
            // a component can predate the purchase ledger, so neither is required alone.
            $table->foreignId('part_purchase_id')->nullable()->constrained('part_purchases')->nullOnDelete();
            $table->foreignId('vehicle_component_id')->nullable()->constrained('vehicle_components')->nullOnDelete();

            // kind=repair: the FAULT that was fixed. This is the anchor that makes a repair warranty
            // enforceable — a comeback is only a comeback if it is the same fault, and the fault row
            // is the only thing that says so. Nullable at the column level because a warranty may be
            // recorded before the finding is promoted to a task; the model requires one of
            // maintenance_task_id / maintenance_id.
            $table->foreignId('maintenance_task_id')->nullable()->constrained('maintenance_tasks')->nullOnDelete();
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();

            // What was covered, in words, frozen at creation. The anchors can be cleaned up, renamed
            // or soft-deleted years from now; the promise must still read as a sentence without them.
            $table->string('subject', 300);
            // The part type when we know it — lets "which parts do suppliers actually stand behind?"
            // be a group-by instead of a text match. Retire-not-delete, hence restrictOnDelete.
            $table->foreignId('component_catalog_id')->nullable()->constrained('component_catalog')->restrictOnDelete();

            // --- who owes us -------------------------------------------------------------------
            // The garage (repair) or the supplier (part). Free-text name kept alongside for the same
            // reason as everywhere else in this codebase: a vendor row can vanish, history cannot.
            $table->foreignId('provider_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('provider_name')->nullable();
            // The paper: invoice no, warranty card no, whatever the counterparty will ask us to quote.
            $table->string('reference_no', 120)->nullable();

            // --- the window ---------------------------------------------------------------------
            $table->date('starts_on');                                  // FACT
            $table->unsignedInteger('start_odometer')->nullable();      // FACT — km at install/repair
            $table->unsignedSmallInteger('duration_months')->nullable();// FACT — the promise, in months
            $table->unsignedInteger('duration_km')->nullable();         // FACT — the promise, in km

            // DERIVED, maintained by the model. Indexed: the expiring-soon board range-scans dates.
            $table->date('expires_on')->nullable()->index();
            $table->unsignedInteger('expires_at_km')->nullable();

            // --- state --------------------------------------------------------------------------
            // active — live or expired purely by the clock/odometer (we never write 'expired': that
            //          would be a cached opinion that goes stale the moment the car is driven).
            // void   — the promise was destroyed before it ran out: unauthorised repair, wrong fluid,
            //          the counterparty walked away. A deliberate act, so it IS stored, with a reason.
            $table->string('status', 10)->default('active')->index();
            $table->text('void_reason')->nullable();

            $table->text('notes')->nullable();

            // --- audit ---------------------------------------------------------------------------
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();

            $table->timestamps();
            // A warranty is evidence in a dispute; deleting one must never lose the record of it.
            $table->softDeletes();

            // "What is live on this car" — the vehicle page's question.
            $table->index(['vehicle_id', 'status', 'expires_on']);
            // "What expires soon" fleet-wide, kind by kind.
            $table->index(['kind', 'status', 'expires_on']);
            // "Is this fault already under a repair warranty?" — the comeback check's question.
            $table->index(['maintenance_task_id', 'status']);
        });

        /**
         * warranty_claims — every time we went back to the counterparty and said "this is yours".
         *
         * Separate from the warranty because the relationship is genuinely one-to-many: the same
         * compressor can fail twice inside one 12-month cover, and a claim that was REFUSED is the
         * most important row in the table — it is the evidence that a supplier does not honour what
         * he sells. Collapsing claims into columns on the warranty would keep only the last attempt
         * and quietly erase exactly that.
         */
        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            // Denormalised so claim history reads per car without a join through the warranty.
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // The failure that triggered the claim, and the ticket it was handled on when there is one.
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->text('failure_description')->nullable();

            $table->date('claimed_on');                                 // FACT
            $table->unsignedInteger('claim_odometer')->nullable();      // FACT — proves the km leg held

            // Was the warranty actually live when the failure happened? Evaluated at claim time
            // against the odometer of that moment and FROZEN, because "was it in date" must not
            // change its answer six months later when the car has done another 40,000 km.
            $table->boolean('was_in_window')->nullable();
            $table->string('window_evidence', 200)->nullable();  // e.g. "8 of 12 months, 14,200 of 20,000 km"

            // pending | accepted | rejected | partial — the counterparty's answer.
            $table->string('outcome', 12)->default('pending')->index();
            $table->text('outcome_reason')->nullable();        // their words when they refuse
            $table->date('resolved_on')->nullable();

            // What we actually got back. The point of the whole feature: money or work recovered.
            $table->decimal('recovered_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('AED');
            // replacement | repair | credit | refund | none
            $table->string('remedy', 12)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['warranty_id', 'outcome']);
            $table->index(['vehicle_id', 'claimed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('warranties');
    }
};
