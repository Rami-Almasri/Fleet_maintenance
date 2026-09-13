<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ACCIDENT CASE — the business context a crash opens, and the parent every repair, document,
 * decision and dirham that follows hangs off.
 *
 * ── WHY THIS IS NOT A MAINTENANCE TICKET ───────────────────────────────────────────────────────
 *
 * A maintenance ticket answers "what is wrong with this car and who is fixing it". An accident asks
 * four more questions the ticket has no place to hold, and every one of them outlives the repair:
 * who had the car, whose fault it was, what the police wrote down, and who pays. Those questions are
 * routinely still open weeks after the panel is straight. Folding them into `maintenances` would
 * mean either closing the ticket with the money unresolved, or holding a repair open for an insurer.
 *
 * So the case is the PARENT and the repair is a child: `maintenances.accident_case_id` points here,
 * one accident may produce several tickets, and each ticket runs the ordinary workflow with nothing
 * about it special-cased.
 *
 * ── THE SNAPSHOT COLUMNS ARE THE POINT OF THE TABLE ────────────────────────────────────────────
 *
 * `contract_id` / `customer_id` are LIVE links. They are also, on their own, a lie waiting to
 * happen: a rental closes, a customer is merged, a car is re-let the same afternoon — and a case
 * that reads its context by re-querying "who has this car" would then answer with whoever has it
 * TODAY. Six months later, when the insurer asks who was driving, that is the difference between an
 * answer and a guess.
 *
 * Hence the twins beside every link: `contract_ref` / `customer_ref` carry NO foreign key (nothing
 * can cascade them to null), and the human-readable facts — the contract number, the customer's name
 * and phone, the rental window, the contract's state at that moment — are COPIED onto the row at the
 * instant the accident is reported. The same reasoning as `vehicle_log_events.maintenance_ref` and
 * `warranty_claims.window_evidence`: what was true when it happened must stay readable after the
 * world moves on. `context_snapshot` keeps the whole detection payload as JSON for anything the
 * columns did not anticipate.
 *
 * NOTHING HERE CLOSES A RENTAL. There is deliberately no write path from this table to `contracts`:
 * the car being off the road and the customer still being on hire are two separate facts, and the
 * business needs both to be true at once. See AccidentCaseService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accident_cases', function (Blueprint $table) {
            $table->id();

            // ── identity ───────────────────────────────────────────────────────────────────────
            // Human reference (ACC-2026-0001). Unique, generated once, quoted to insurers and police.
            $table->string('reference', 32)->unique();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // ── the report ─────────────────────────────────────────────────────────────────────
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reported_by_name')->nullable();      // survives the user row
            $table->timestamp('reported_at')->nullable();        // when it was TOLD to us
            $table->timestamp('occurred_at')->nullable();        // when it actually happened
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('odometer')->nullable();     // the reading at the time, if known

            // ── who had the car (frozen — see the docblock) ────────────────────────────────────
            $table->string('responsible_party_type', 32)->default('unknown');
            $table->boolean('context_detected')->default(false); // true = the system found the contract
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->unsignedBigInteger('contract_ref')->nullable();   // FK-free twin
            $table->string('contract_no_snapshot', 64)->nullable();
            $table->string('contract_state_snapshot', 32)->nullable();
            $table->date('contract_out_date_snapshot')->nullable();
            $table->date('contract_in_date_snapshot')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('customer_ref')->nullable();   // FK-free twin
            $table->string('customer_name_snapshot')->nullable();
            $table->string('customer_phone_snapshot', 64)->nullable();
            // The person at the wheel, who is often NOT the account holder (an additional driver, an
            // employee moving a car, a valet). Free text on purpose: most of the time all we have is
            // a name and a number written down at the roadside.
            $table->string('driver_name')->nullable();
            $table->string('driver_phone', 64)->nullable();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('responsible_party_note')->nullable();
            $table->json('context_snapshot')->nullable();

            // ── what happened ──────────────────────────────────────────────────────────────────
            $table->string('accident_type', 32)->nullable();
            $table->boolean('other_party_involved')->default(false);
            $table->string('other_party_name')->nullable();
            $table->string('other_party_phone', 64)->nullable();
            $table->string('other_party_plate', 64)->nullable();
            $table->string('other_party_insurer')->nullable();
            $table->string('other_party_policy_no', 64)->nullable();
            $table->text('other_party_note')->nullable();
            // Three separate facts about the car's condition, because they drive three different
            // decisions: whether somebody can drive it away, whether a recovery truck is needed, and
            // whether it is safe for anybody to be near it at all. Nullable = not yet assessed, which
            // is a different answer from "no".
            $table->boolean('drivable')->nullable();
            $table->boolean('towing_required')->nullable();
            $table->text('safety_concerns')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assessed_by_name')->nullable();

            // ── the police report ──────────────────────────────────────────────────────────────
            // A STATUS, not an attachment. "Is the police report missing?" has to be answerable from a
            // query — a file sitting in a document table cannot answer it, because the absence of a
            // row is indistinguishable from nobody having looked.
            $table->string('police_status', 16)->default('missing'); // missing|recorded|verified|bypassed
            $table->string('police_report_no', 64)->nullable();
            $table->date('police_report_date')->nullable();
            $table->string('police_authority')->nullable();
            $table->text('police_note')->nullable();
            $table->timestamp('police_recorded_at')->nullable();
            $table->foreignId('police_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('police_verified_at')->nullable();
            $table->foreignId('police_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('police_verified_by_name')->nullable();
            // The exception, recorded as one. A bypass is allowed (a car scraped in our own yard has
            // no police report and never will) but it is never silent: who, when, and why.
            $table->text('police_bypass_reason')->nullable();
            $table->timestamp('police_bypassed_at')->nullable();
            $table->foreignId('police_bypassed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('police_bypassed_by_name')->nullable();

            // ── liability ──────────────────────────────────────────────────────────────────────
            // Starts `pending` and stays there until a human says otherwise. The customer being at the
            // wheel is NOT an answer to this question and the default must never imply it is.
            $table->string('liability_status', 24)->default('pending');
            $table->unsignedTinyInteger('liability_share_pct')->nullable(); // share borne by the named party
            $table->string('liability_source', 24)->nullable();             // police_report|insurance|internal|legal|other
            $table->text('liability_note')->nullable();
            $table->timestamp('liability_decided_at')->nullable();
            $table->foreignId('liability_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('liability_decided_by_name')->nullable();

            // ── insurance ──────────────────────────────────────────────────────────────────────
            $table->foreignId('insurer_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('insurer_name')->nullable();
            $table->string('policy_no', 64)->nullable();
            $table->string('claim_no', 64)->nullable();
            $table->string('claim_status', 24)->default('not_submitted');
            $table->timestamp('claim_submitted_at')->nullable();
            $table->date('claim_response_due_on')->nullable();
            $table->string('insurance_contact_name')->nullable();
            $table->string('insurance_contact_phone', 64)->nullable();
            $table->string('insurance_contact_email')->nullable();
            $table->text('insurance_note')->nullable();
            $table->timestamp('insurance_updated_at')->nullable();

            // ── where the case is ──────────────────────────────────────────────────────────────
            $table->string('stage', 32)->default('reported');
            $table->string('currency', 3)->default('AED');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_by_name')->nullable();
            $table->text('closure_note')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // "Which cars are under an unresolved accident?" is asked on every rental-eligibility
            // check, so it must be an index hit, not a scan.
            $table->index(['vehicle_id', 'stage']);
            $table->index('stage');
            $table->index('police_status');
            $table->index('liability_status');
            $table->index('claim_status');
            $table->index('occurred_at');
            $table->index('contract_ref');
            $table->index('customer_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accident_cases');
    }
};
