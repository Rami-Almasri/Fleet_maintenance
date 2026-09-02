<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * warranty_claims grows from a RESULT into a CASE — the file that is opened the moment somebody
 * suspects the manufacturer owes us, and closed when that question has an answer either way.
 *
 * WHY NOT A NEW `warranty_cases` TABLE. The row that already exists here is the end of the story:
 * "we went to them, this is what they said, this is what we got back". The states this migration
 * adds are the part of that story BEFORE the answer — is it covered, did they authorise it, has it
 * gone to the dealer, has the claim been submitted. Splitting those across two tables would put a
 * one-to-one relationship on a foreign key and force every read (the board, the vehicle page, the
 * recovery total) to join for facts that belong to one continuous event. It would also give us two
 * places to answer "is this still open", which is precisely how a chase stops being chased.
 *
 * So one row, two axes, and they are NOT the same axis:
 *
 *   `stage`   WHERE WE ARE. Ours. Advanced by our people as the case moves. It is the answer to
 *             "what is somebody supposed to do next?", and it is what the board columns are.
 *
 *   `outcome` WHAT THEY SAID. Theirs. pending → accepted | rejected | partial. Already here, already
 *             read by the recovered-money scope, and deliberately untouched by this migration.
 *
 * A case at stage=claim_submitted has outcome=pending; a case at stage=closed has whatever they
 * finally said. Collapsing the two would mean a case awaiting a dealer's phone call and a case the
 * dealer refused were indistinguishable until you read the reason text.
 *
 * ── THE FIRST STAGE IS THE POINT OF THE WHOLE FEATURE ──────────────────────────────────────────
 *
 * `coverage_review` is a case opened when nobody yet knows whether the work is covered. It exists so
 * that "we don't know" is a WORK ITEM ASSIGNED TO SOMEBODY rather than a shrug that resolves itself
 * into a purchase order. Its verdict fields are the human's answer:
 *
 *   coverage_verdict      covered | not_covered | unknown  — what was decided
 *   coverage_reason_code  WHY, as a code ([[reason-code-contract]]) — never an English sentence
 *   decided_by / _name / decided_at   who said so and when, because this decision spends or saves money
 *
 * A verdict of not_covered is not a failure and is not deleted: it is the record that releases
 * procurement, and the evidence that the question WAS asked before the money was spent.
 *
 * ── ANCHORS ────────────────────────────────────────────────────────────────────────────────────
 * The existing table anchored a claim to the warranty, the car and the ticket. A case also needs to
 * name the FAULT, the fitted COMPONENT, the PART TYPE and the purchase request it is standing in
 * front of — otherwise "which of the four things wrong with this car is the dealer looking at?" has
 * no answer, and the procurement guard has nothing to match a new request against.
 *
 * Evidence classes: FACT — every anchor, every stamp, every reference number, the provider's answer.
 * JUDGEMENT — coverage_verdict + coverage_reason_code (a human's decision, attributed and dated).
 * DERIVED — none; `stage` is written, not computed, because it records a decision to move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            // ── Where we are ───────────────────────────────────────────────────────────────────
            // @see App\Models\WarrantyClaim::STAGES for the ladder and what each rung means.
            $table->string('stage', 24)->default('identified')->after('maintenance_id');

            // WHY this file was opened at all: a maintenance fault, a purchase request that was
            // stopped, a spare-key need, the pre-expiry inspection, or somebody typing it in.
            $table->string('origin', 24)->default('manual')->after('stage');

            // ── The coverage decision ──────────────────────────────────────────────────────────
            $table->string('coverage_verdict', 12)->nullable()->after('origin');
            $table->string('coverage_reason_code', 40)->nullable()->after('coverage_verdict');
            $table->foreignId('decided_by')->nullable()->after('coverage_reason_code')
                ->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable()->after('decided_by');
            $table->dateTime('decided_at')->nullable()->after('decided_by_name');

            // ── What the case is about ─────────────────────────────────────────────────────────
            // Frozen wording, for the same reason Warranty::subject is frozen: the anchors below can
            // be renamed or retired years from now and the case must still read as a sentence.
            $table->string('subject', 300)->nullable()->after('failure_description');
            // What the workshop found, as opposed to what the driver reported. A dealer will ask.
            $table->text('diagnosis')->nullable()->after('subject');

            $table->foreignId('maintenance_task_id')->nullable()->after('maintenance_id')
                ->constrained('maintenance_tasks')->nullOnDelete();
            $table->foreignId('vehicle_component_id')->nullable()->after('maintenance_task_id')
                ->constrained('vehicle_components')->nullOnDelete();
            // The part TYPE, so "which parts do dealers actually honour?" is a group-by. Retire, never
            // delete — hence restrictOnDelete, matching warranties.component_catalog_id.
            $table->foreignId('component_catalog_id')->nullable()->after('vehicle_component_id')
                ->constrained('component_catalog')->restrictOnDelete();

            // ── Authorisation and the dealer leg ───────────────────────────────────────────────
            // The dealer's go-ahead. A repair started without it is a repair they can refuse to pay
            // for, which is why the reference is a column and not a note.
            $table->string('authorization_ref', 120)->nullable()->after('window_evidence');
            $table->dateTime('authorization_requested_at')->nullable()->after('authorization_ref');
            $table->dateTime('authorized_at')->nullable()->after('authorization_requested_at');
            $table->dateTime('sent_to_provider_at')->nullable()->after('authorized_at');
            /**
             * When we expect to have heard back. The ONLY thing that makes "awaiting dealer" chaseable
             * rather than a column things quietly rot in — the overdue detector reads this date.
             * Nullable: a case nobody promised a date on is not overdue, it is undated, and inventing
             * an SLA the dealer never agreed to would manufacture alerts out of nothing.
             */
            $table->date('provider_response_due_on')->nullable()->after('sent_to_provider_at');

            // ── The claim paperwork ────────────────────────────────────────────────────────────
            // Their reference for the claim, as opposed to warranties.reference_no (the contract).
            $table->string('claim_reference', 120)->nullable()->after('provider_response_due_on');
            $table->dateTime('submitted_at')->nullable()->after('claim_reference');

            // ── The end ────────────────────────────────────────────────────────────────────────
            $table->dateTime('closed_at')->nullable()->after('resolved_on');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->string('closed_by_name')->nullable()->after('closed_by');

            /**
             * What we did NOT have to spend because the counterparty took it on.
             *
             * Deliberately separate from `recovered_amount`, which is money that came BACK to us
             * (a credit, a refund). A dealer replacing a gearbox for free recovers nothing and avoids
             * a great deal; adding the two together would double-count on any case where both happen,
             * and reporting them as one number would make the feature's value unauditable.
             */
            $table->decimal('avoided_amount', 12, 2)->nullable()->after('recovered_amount');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
        });

        Schema::table('warranty_claims', function (Blueprint $table) {
            // The board: "what is open, oldest first" per column.
            $table->index(['stage', 'claimed_on'], 'warranty_claims_stage_idx');
            // The vehicle page + the procurement guard's "is there already a case for this car?".
            $table->index(['vehicle_id', 'stage'], 'warranty_claims_vehicle_stage_idx');
            // The guard's real question: "is there already an open case for THIS part type on this car?"
            $table->index(['vehicle_id', 'component_catalog_id', 'stage'], 'warranty_claims_subject_idx');
            // The chase: "who has not come back to us, and how late are they?"
            $table->index(['stage', 'provider_response_due_on'], 'warranty_claims_chase_idx');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->dropIndex('warranty_claims_stage_idx');
            $table->dropIndex('warranty_claims_vehicle_stage_idx');
            $table->dropIndex('warranty_claims_subject_idx');
            $table->dropIndex('warranty_claims_chase_idx');

            $table->dropConstrainedForeignId('decided_by');
            $table->dropConstrainedForeignId('maintenance_task_id');
            $table->dropConstrainedForeignId('vehicle_component_id');
            $table->dropConstrainedForeignId('component_catalog_id');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('updated_by');

            $table->dropColumn([
                'stage', 'origin', 'coverage_verdict', 'coverage_reason_code',
                'decided_by_name', 'decided_at', 'subject', 'diagnosis',
                'authorization_ref', 'authorization_requested_at', 'authorized_at',
                'sent_to_provider_at', 'provider_response_due_on',
                'claim_reference', 'submitted_at', 'closed_at', 'closed_by_name',
                'avoided_amount', 'updated_by_name',
            ]);
        });
    }
};
