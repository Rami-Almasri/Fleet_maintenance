<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ACCIDENT WORKFLOW BECOMES DATA.
 *
 * Until now the ladder was a PHP array and the gates were `if` statements bound to stage NAMES. That
 * works exactly once — the first time the office wants the police report after the damage assessment,
 * it is a developer ticket and a deploy. The process this fleet actually runs (report → decide fault
 * → charge the renter if it was theirs → insurer inspects and photographs → recovery if it cannot
 * move, test drive if it can → repair) is not the ladder that was coded, which is the whole point.
 *
 * ── THREE TABLES, AND WHY EACH ONE EXISTS ──────────────────────────────────────────────────────
 *
 * `accident_workflows`        a VERSION of the ladder. Immutable once published.
 * `accident_workflow_stages`  the rungs of one version, each carrying its own gate.
 * `accident_stage_completions` the generic "somebody confirmed this stage" record.
 *
 * ── WHY VERSIONS, AND NOT ONE EDITABLE LADDER ──────────────────────────────────────────────────
 *
 * Because a case in flight must not move when an admin reorders the list. Consider a case sitting at
 * "Insurance" when somebody deletes that stage: with a single mutable ladder, that case is now parked
 * on a rung that does not exist, and nothing in the system can tell you where it should go. Pinning
 * each case to the version it was born on makes that state unreachable rather than merely unlikely.
 *
 * The alternative — snapshotting the whole ladder as JSON onto every case — gives the same isolation
 * but makes "show me every case waiting on a police report" stop being a query. Rows, not blobs.
 *
 * Editing an active workflow opens a DRAFT; publishing mints the next version. Live cases stay where
 * they are unless somebody explicitly migrates them. That is a decision, never a side effect.
 *
 * ── WHY THE GATE LIVES ON THE STAGE ROW ────────────────────────────────────────────────────────
 *
 * `requirement_key` is the load-bearing column. The rule travels WITH the stage, so moving "Police
 * Report" to position 5 moves its gate to position 5. The alternative — keeping `if (stage ===
 * 'police')` in PHP — would let a reorder silently switch the guardrail off: no error, no log entry,
 * just a case walking past the police report. That is the failure this whole feature exists to
 * prevent, so it must be structurally impossible rather than merely documented.
 *
 * `requirement_key` is a KEY INTO A REGISTRY, never an expression. An admin chooses from a fixed list
 * the backend was compiled to understand; there is no rule language here and there must never be one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── the version ────────────────────────────────────────────────────────────────────────
        Schema::create('accident_workflows', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version');
            $table->string('name')->nullable();          // "After the insurer asked for photos first"
            // draft   — being edited, receives no cases
            // active  — exactly one at a time; every NEW case is pinned to it
            // archived— superseded, but still owns every case that was born on it
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('published_by_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('version');
            $table->index('status');
        });

        // ── the rungs ──────────────────────────────────────────────────────────────────────────
        Schema::create('accident_workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('accident_workflows')->cascadeOnDelete();

            // THE STORED FACT on a case is this key, so it is immutable once published — renaming a
            // stage must never orphan the cases sitting on it. `label` is presentation and may be
            // reworded freely. Same division as request_reasons: the code is the record, the label
            // is the wording. @see [[reason-code-contract]]
            $table->string('key', 64);
            $table->string('label');
            $table->string('label_ar')->nullable();
            $table->text('description')->nullable();     // the hint the board column carries
            $table->unsignedSmallInteger('position');

            // Disabled stages are SKIPPED when advancing but can still be LEFT — a case already
            // standing on one must never become unresolvable because somebody switched it off.
            $table->boolean('is_enabled')->default(true);
            // Exactly one per workflow. Where a case is born.
            $table->boolean('is_initial')->default(false);
            // At least one per workflow, and it must hold the highest position. Where a case ends.
            $table->boolean('is_terminal')->default(false);
            // Advisory: an optional stage may be skipped by hand without an override.
            $table->boolean('is_mandatory')->default(true);
            // Replaces the hard-coded RENTAL_BLOCKING_STAGES list. A car standing on a stage flagged
            // here is held out of the rental pool — so "does settlement ground the car?" becomes the
            // office's decision rather than a constant in a service.
            $table->boolean('blocks_rental')->default(false);

            // THE GATE. A key into the requirement registry — never an expression.
            $table->string('requirement_key', 48)->default('none');
            // Parameters for the requirement that needs one (document_uploaded needs to know WHICH
            // kind). Small and typed by the requirement class that reads it.
            $table->json('requirement_config')->nullable();

            // WHETHER THIS STAGE APPLIES AT ALL to a given case — the branch. A car that cannot be
            // driven goes to Recovery and skips the test drive; a car that can does the reverse.
            // Expressed as a condition key rather than a graph, because the real process branches on
            // FACTS about the case, not on arbitrary routing.
            $table->string('applies_when', 48)->default('always');

            $table->string('tone', 16)->nullable();      // board colour
            $table->timestamps();

            $table->unique(['workflow_id', 'key']);
            // No duplicate positions, ever — the ordering IS the workflow, so an ambiguous order is
            // an ambiguous process. The service resequences on save so this can only fire on a bug.
            $table->unique(['workflow_id', 'position']);
            $table->index(['workflow_id', 'is_enabled', 'position']);
        });

        // ── "somebody confirmed this stage" ────────────────────────────────────────────────────
        //
        // THE GENERIC ESCAPE HATCH, and the reason a brand-new stage needs no migration. A stage the
        // office invents tomorrow — "Management Approval", "Insurer Inspection", "Recovery Arranged" —
        // has no column of its own and needs none: its gate is `manual_confirmation`, and satisfying
        // it writes a row here. Without this, every new stage would be a schema change, which is
        // exactly the developer dependency this feature exists to remove.
        //
        // Append-only and attributed: who confirmed it, when, and what they said.
        Schema::create('accident_stage_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accident_case_id')->constrained('accident_cases')->cascadeOnDelete();
            $table->string('stage_key', 64);
            $table->timestamp('completed_at');
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('completed_by_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // One live confirmation per stage per case; re-confirming replaces it (the service
            // deletes then writes, inside a transaction) so a stage cannot read as doubly-done.
            $table->unique(['accident_case_id', 'stage_key']);
        });

        // ── the pin ────────────────────────────────────────────────────────────────────────────
        Schema::table('accident_cases', function (Blueprint $table) {
            // Which VERSION of the ladder this case runs. Nullable only so the backfill below can
            // fill it; every new case gets one at creation. Deliberately NOT cascade-delete — a
            // workflow version that owns cases must not be deletable, and the service refuses it.
            $table->foreignId('workflow_id')->nullable()->after('reference')
                ->constrained('accident_workflows')->nullOnDelete();
            $table->index('workflow_id');
        });
    }

    public function down(): void
    {
        Schema::table('accident_cases', function (Blueprint $table) {
            $table->dropForeign(['workflow_id']);
            $table->dropColumn('workflow_id');
        });
        Schema::dropIfExists('accident_stage_completions');
        Schema::dropIfExists('accident_workflow_stages');
        Schema::dropIfExists('accident_workflows');
    }
};
