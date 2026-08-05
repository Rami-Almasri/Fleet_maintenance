<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The human job created when a rental is recalled for an oil change: phone the customer and arrange
 * to get the car back.
 *
 * DELIBERATELY NOT A LOGISTICS DISPATCH. Nobody is being sent to collect anything — the fleet has no
 * operational module for that yet, and pretending otherwise would put half-built transport records in
 * front of drivers. This is one thing only: a follow-up sitting in the Controllers' queue until the
 * customer has been spoken to and a return is agreed. When a real logistics lane exists, it hangs off
 * this task; the oil decision engine above it does not change.
 *
 * The task is a WORK STATE over an existing decision, not a second copy of it. `ContractOilDecision`
 * remains the audit record of what was chosen and against which figures; this table adds who is
 * chasing it, how far they have got, and the snapshot of the numbers they will read out on the call.
 * The snapshot is duplicated here on purpose: the projection moves with every new reading, and a
 * controller halfway through a phone call must see the figures the recall was ordered on, not the
 * figures as they stand thirty seconds later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oil_recall_tasks', function (Blueprint $table) {
            $table->id();

            // One task per recall decision — the unique index IS the idempotency guard.
            $table->foreignId('contract_oil_decision_id')->unique()
                ->constrained('contract_oil_decisions')->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            // open → contacted → done, or cancelled when the recall itself is revised.
            $table->string('status', 16)->default('open');

            // WHY, as a code. The sentence is built at the edge so it can be read in English or
            // Arabic and so the engine never hard-codes prose (the reason-code contract).
            $table->string('reason_code', 64)->default('oil_tolerance_exceeded_before_return');

            // The figures the call is made on, frozen at the moment of the decision.
            $table->unsignedInteger('customer_reading')->nullable();
            $table->date('customer_reading_on')->nullable();
            $table->unsignedInteger('oil_limit')->nullable();
            $table->unsignedInteger('allowed_max')->nullable();
            $table->unsignedInteger('expected_return_odometer')->nullable();
            $table->unsignedSmallInteger('remaining_days')->nullable();

            // WHO ordered it, and WHEN the decision was taken (not when this row was written — they
            // are the same today, but the task is the decision's servant and must quote its clock).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamp('decided_at')->nullable();

            // WHO it was handed to: the configured oil follow-up controllers (Leen & Marwa), snapshot
            // so the record still says who was asked after someone edits the allow-list. Any of them
            // may pick it up — `claimed_by` is who actually did.
            $table->json('assigned_user_ids')->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();

            $table->text('note')->nullable();          // why it was recalled, in the decider's words
            $table->text('outcome_note')->nullable();  // what the customer said

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // "the open recalls" — the only query the queue actually runs.
            $table->index(['status', 'id']);
            $table->index(['vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oil_recall_tasks');
    }
};
