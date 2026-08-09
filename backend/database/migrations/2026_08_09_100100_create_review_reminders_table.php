<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Remind me at a time" for the Inspection Review Queue — the one place in this app that stores a FUTURE
 * moment and fires at it.
 *
 * Every other reminder here is derive-on-scan: a command re-reads the world each morning and decides who
 * is late (checkpoints:scan, service:sync-reminders). That pattern cannot express "come back to me in two
 * hours", because the thing being remembered is a person's decision to wait, not a fact about the car.
 * So this row stores the moment itself, and `review-reminders:dispatch` fires whatever is due.
 *
 * Two kinds, one table:
 *   - pending_review   — the request is still in the queue and the reviewer wants to look again later
 *                        (waiting for the car to come back, for the driver to answer, for a busy hour to
 *                        pass). It cancels ITSELF the moment someone approves or rejects the request:
 *                        a reminder about a decision already taken is noise.
 *   - rejected_revisit — the request was rejected AND the reviewer asked to be reminded to revisit it.
 *                        This one deliberately survives the rejection, because the rejection is what
 *                        created it.
 *
 * PERSONAL, not broadcast: `user_id` is the one human who asked to be reminded. Two reviewers can each
 * set their own reminder on the same request without seeing or cancelling each other's.
 *
 * Loose indexes, no hard FK to `maintenances` — same discipline as maintenance_checkpoint_reminders; the
 * dispatcher resolves the ticket at fire time and closes the reminder if the ticket has since vanished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('maintenance_id')->index();
            // Denormalised so a car's reminder history reads without joining through the ticket.
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            // WHO asked to be reminded — this reminder belongs to them and nobody else.
            $table->unsignedBigInteger('user_id')->index();

            // pending_review | rejected_revisit (see the class doc above).
            $table->string('kind', 24)->default('pending_review');

            // THE MOMENT. Stored, not derived — that is the whole point of this table.
            $table->timestamp('remind_at');
            // What the reviewer wants to be reminded about, in their own words. Optional.
            $table->text('note')->nullable();

            // pending → sent, or → cancelled (by hand, or automatically when the request is decided).
            $table->string('status', 16)->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // Why it stopped mattering: 'reviewed' (someone decided the request), 'by_user', 'ticket_gone'.
            $table->string('cancelled_reason', 32)->nullable();

            $table->timestamps();

            // The dispatcher's only query: "everything still pending whose moment has passed, oldest first".
            $table->index(['status', 'remind_at'], 'rr_due_idx');
            // The card read: "does the person looking at this request have a reminder on it?"
            $table->index(['maintenance_id', 'user_id', 'status'], 'rr_ticket_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reminders');
    }
};
