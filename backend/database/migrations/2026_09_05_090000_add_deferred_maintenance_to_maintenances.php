<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE THIRD ANSWER AT THE DECIDE STEP: "there IS a fault, and we are not sending the car today."
 *
 * Until now submitReport() had exactly two outcomes — open a ticket, or clear the diagnostic — and a
 * hard rule ("the mirror rule") that a clearance may not carry findings. That rule is right: a cleared
 * diagnostic is terminal and never promotes its findings into fault tasks, so filing real faults as
 * "no maintenance needed" recorded them and buried them in the same click.
 *
 * But it left the fleet's most common real situation with nowhere to go: the inspector found something,
 * it is genuinely not urgent, and the car should keep earning until the right moment. The workaround was
 * to untick the findings — i.e. to delete the evidence — which is exactly the outcome the mirror rule
 * exists to prevent.
 *
 * `maintenance_deferred` is that third outcome. The report is kept whole, the findings are promoted into
 * first-class MaintenanceTasks through the SAME pipeline a normal ticket uses, and the ticket then parks:
 * no garage, no dispatch, no driver alert. It is deliberately NOT in Maintenance::WF_TICKET_STATES, so it
 * never drives operational_status and the car stays rentable — the same mechanism that lets an on-site
 * job keep a car available.
 *
 * ── WHY A TRIGGER *KIND*, NOT JUST A DATE ───────────────────────────────────────────────────────────
 *
 * "Follow this up later" means four different things in this operation, and flattening them into a date
 * would mean guessing at three of them:
 *
 *   date         a real calendar moment the supervisor chose         → deferral_due_date
 *   after_rental the second the customer brings the car back         → derived from contracts
 *   next_service whenever the car is next in for its scheduled work   → derived from service reminders
 *   mileage      at an odometer reading, whenever that arrives        → deferral_due_odometer
 *
 * Only two of the four store a value; the other two are DERIVED ON SCAN from state the platform already
 * holds. That is deliberate and matches the house rule ([[review-reminder-engine]]): a stored future
 * timestamp is for a person's decision to WAIT, and only `date` is one. "When the rental ends" is a fact
 * about a car — computing it each scan means it stays true when the rental is extended, and cannot rot.
 *
 * Additive and nullable throughout: every existing row reads as "never deferred".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // WHEN the deferral decision was made, and by whom. dateTime() — never timestamp() — so
            // MariaDB does not silently attach an ON UPDATE CURRENT_TIMESTAMP to it and rewrite the
            // decision's moment every time anything else on the ticket is saved.
            $table->dateTime('deferred_at')->nullable();
            $table->unsignedBigInteger('deferred_by')->nullable();

            // WHY it was deferred, in the decider's own words. Mandatory at the API, nullable here
            // because rows written before this existed have nothing to say.
            $table->text('deferred_reason')->nullable();

            // WHAT BRINGS IT BACK — one of Maintenance::DEFERRAL_TRIGGERS.
            $table->string('deferral_trigger', 24)->nullable();
            // …and the value that trigger needs, if it needs one. `date` fills the first, `mileage`
            // the second, `after_rental` / `next_service` neither.
            $table->date('deferral_due_date')->nullable();
            $table->unsignedInteger('deferral_due_odometer')->nullable();

            // The follow-up actually happening: the supervisor pressed "Send to maintenance" and the
            // ticket left this state for the ordinary dispatch pipeline. Kept (rather than cleared) so
            // "how long did we sit on this fault?" stays answerable after the repair is done.
            $table->dateTime('deferral_activated_at')->nullable();
            $table->unsignedBigInteger('deferral_activated_by')->nullable();

            // The scanner asks one question on every pass — "which parked tickets are due?" — and it
            // asks it of the trigger first. Indexed together so that stays one cheap range scan.
            $table->index(['deferral_trigger', 'deferral_due_date'], 'maint_deferral_trigger_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropIndex('maint_deferral_trigger_idx');
            $table->dropColumn([
                'deferred_at',
                'deferred_by',
                'deferred_reason',
                'deferral_trigger',
                'deferral_due_date',
                'deferral_due_odometer',
                'deferral_activated_at',
                'deferral_activated_by',
            ]);
        });
    }
};
