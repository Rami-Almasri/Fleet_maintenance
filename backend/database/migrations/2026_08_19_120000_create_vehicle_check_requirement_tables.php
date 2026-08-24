<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE CHECK REQUIREMENT — an obligation the platform raised and a human must explicitly answer.
 *
 * WHY THIS IS ITS OWN ENTITY, given three neighbours that each look close:
 *
 *   recommendations        an immutable HISTORICAL OBSERVATION — what the platform said, frozen, so
 *                          "why did it tell me that?" is answerable in eighteen months. The model
 *                          throws on update by construction. It has no open/closed state and must
 *                          never acquire one. Where a Decision Card raises a check, the two are
 *                          LINKED (recommendation_id below), not merged.
 *   suggested checks       computed on read, never stored, deliberately — a stored suggestion goes
 *                          stale the moment the car is repaired (VehicleSuggestedChecksService).
 *   maintenance_tasks      a FAULT. A check is not a fault; that confusion is the bug this fixes.
 *
 * What none of them models is "somebody owes us an answer about this, and until they give one we
 * cannot tell 'checked, fine' apart from 'nobody looked'". That gap is the entire point:
 *
 *   Battery inspection required   →   checked → OK           ⇒ resolved, NO fault created
 *                                 →   checked → Replace      ⇒ decision → an ordinary MaintenanceTask
 *                                 →   (never answered)       ⇒ still pending, and visibly so
 *
 * TWO TABLES, ONE JOB EACH — the same split the recommendation lifecycle uses, for the same reason:
 *   vehicle_check_requirements  the obligation. Everything about WHY is frozen at creation; only the
 *                               resolution columns move.
 *   vehicle_check_events        the append-only trail. Model-enforced (throws on update AND delete),
 *                               so the audit history cannot be edited into agreeing with itself.
 *
 * IDEMPOTENCY IS A DATABASE CONSTRAINT, NOT A CONVENTION. The diagnostic monitor runs daily and will
 * re-derive the same conditions every morning until the car is serviced. `cycle_hash` comes from the
 * gate's own `cycle_key` — a value that changes only when the underlying cycle actually rolls forward
 * (the oil is changed, the reminder's next date advances, the battery is replaced). The UNIQUE index
 * below is what makes a hundred reruns produce one requirement, enforced in the schema so no future
 * caller can lose the race or forget the check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_check_requirements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            // ---- identity ----
            // check_type keys config/vehicle_checks.php `types`; check_key is the ORIGINATING rule's
            // key ('oil_change', 'battery', 'reminder:42', 'downtime:brakes'), kept distinct so an
            // expanded agenda item still names the rule it came from.
            $table->string('check_type', 40)->index();
            $table->string('check_key', 60);

            // ---- provenance: who raised it, on which evidence, under which catalog ----
            $table->string('source', 32)->default('diagnostic_monitor'); // diagnostic_monitor | capability | manual | backfill
            $table->string('rule_key', 60)->nullable();                  // the gate rule bucket (oil/reminder/battery/downtime/inactivity)
            $table->string('catalog_version', 20)->default('v1');
            // When an Intelligence Decision Card raised this, the immutable recommendation it came
            // from. Nullable and nullOnDelete: the obligation outlives its advice, and most checks
            // come from the rule engine, which produces no recommendation row at all.
            $table->foreignId('recommendation_id')->nullable()->constrained('recommendations')->nullOnDelete();

            // ---- why, in codes rather than English ([[reason-code-contract]]) ----
            $table->string('reason_code', 60);
            $table->json('reason_params')->nullable();
            // The gate's legacy English `detail`, carried explicitly as *_en and never as the only
            // copy of the information — same contract VehicleSuggestedChecksService uses.
            $table->text('detail_en')->nullable();
            $table->string('severity', 16)->default('routine');
            // Everything the rule knew at detection: odometer, interval, overdue km, battery age,
            // idle days. Frozen — it must not drift when the car is serviced later.
            $table->json('evidence')->nullable();

            // ---- idempotency ----
            $table->text('cycle_key');                 // human-readable, e.g. 'battery_age|2023-10-01'
            $table->char('cycle_hash', 40);            // sha1(cycle_key) — the indexable twin
            // A 'monitor' answer is not a permanent all-clear: the same cycle may legitimately be
            // asked again later (config `reraise_after_days`). Sequence keeps that a NEW obligation
            // with its own answer, without weakening the constraint against accidental duplicates.
            $table->unsignedSmallInteger('cycle_seq')->default(1);

            // ---- lifecycle ----
            // pending → attached → inspected → action_pending → resolved | cancelled | superseded | expired
            $table->string('status', 20)->default('pending')->index();

            // The inspection this requirement was attached to and answered on.
            $table->foreignId('maintenance_id')->nullable()->constrained('maintenances')->nullOnDelete();
            $table->dateTime('attached_at')->nullable();

            // ---- the inspector's answer ----
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('inspected_at')->nullable();
            $table->string('result_code', 40)->nullable();

            // ---- the decision that followed an action-bearing result ----
            $table->string('decision_code', 40)->nullable();
            // The findings-catalog keyword this check's work became. Usually copied from the catalog;
            // for an open-ended scheduled check the catalog has none and the inspector names it, and
            // then THIS is the only record of which fault the obligation turned into. Resolved once,
            // at answer time, rather than re-derived later from a catalog that may have moved on.
            $table->string('finding_keyword', 120)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();

            // ---- the action it produced, if any ----
            // Polymorphic on purpose: today an approved battery replacement becomes a MaintenanceTask,
            // tomorrow a coolant check might become a part request or a schedule entry. The lifecycle
            // must not care which, exactly as the recommendation lifecycle does not care what an
            // outcome is.
            $table->string('action_type', 60)->nullable();
            $table->unsignedBigInteger('action_id')->nullable();
            $table->dateTime('action_created_at')->nullable();
            $table->dateTime('action_completed_at')->nullable();

            // ---- the end ----
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution_code', 40)->nullable(); // confirmed_ok | monitoring | repaired | deferred | declined | superseded | expired
            // The earliest this check may be raised again despite an unchanged cycle — how a
            // 'monitor' answer stops being an all-clear after 30 days without inventing a reminder.
            $table->dateTime('reraise_after')->nullable();

            $table->timestamps();

            // THE IDEMPOTENCY GUARANTEE. One obligation per car, per check, per cycle, per sequence —
            // no matter how many times the monitor runs.
            $table->unique(['vehicle_id', 'check_key', 'cycle_hash', 'cycle_seq'], 'vcr_cycle_unique');

            // "everything still owed on this car" — the query the Decide step runs on every open ticket.
            $table->index(['vehicle_id', 'status'], 'vcr_vehicle_status');
            $table->index(['maintenance_id', 'status'], 'vcr_ticket_status');
            // "how often did we raise a battery check, and what came of it" — the analytics read.
            $table->index(['check_type', 'created_at'], 'vcr_type_created');
            $table->index(['action_type', 'action_id'], 'vcr_action');
        });

        Schema::create('vehicle_check_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_check_requirement_id')->constrained('vehicle_check_requirements', 'id', 'vce_requirement_fk')->cascadeOnDelete();

            // raised | attached | viewed | inspected | decided | action_created | action_completed
            // | resolved | cancelled | superseded | expired
            $table->string('event', 24)->index();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // Codes, not sentences — the same contract the requirement itself follows, so the timeline
            // reads in Arabic without a translation layer guessing at English prose.
            $table->string('reason_code', 60)->nullable();
            $table->json('payload')->nullable();

            // dateTime, NOT timestamp: on MariaDB a `timestamp()` column silently acquires
            // ON UPDATE CURRENT_TIMESTAMP, which would rewrite the moment an event happened the next
            // time anything touched the row. See [[mariadb-timestamp-autoupdate-trap]].
            $table->dateTime('occurred_at')->index();
            $table->timestamps();

            $table->index(['vehicle_check_requirement_id', 'event'], 'vce_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_check_events');
        Schema::dropIfExists('vehicle_check_requirements');
    }
};
