<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONTINUOUS LEARNING — every human correction to the ontology, kept as a training signal.
 *
 * The engine gets things wrong. A workshop supervisor deletes a synonym that means something else
 * here, renames a component to the wording the parts desk actually uses, or rejects a match the
 * system proposed. In V1 that correction changed one row and then vanished.
 *
 * Here every one of those actions is recorded, and the accumulated corrections are fed back into
 * the NEXT enrichment prompt as explicit guidance ("this workshop has previously rejected X for
 * this fault because Y — do not generate it again"). That is continuous learning without fine-
 * tuning: the model does not change, but the instructions it works under get better every week,
 * and the improvement is auditable and reversible in a way a fine-tuned checkpoint never is.
 *
 * `applied_at` is what makes it a queue rather than a log — [[OntologyFeedbackService]] folds
 * unapplied feedback into the prompt and stamps it, so the same correction is not repeated at the
 * model forever.
 *
 * Two classes of signal live here, and they are worth telling apart:
 *  - CURATION (accept / edit / reject / merge / create / delete) — a human changing the ontology.
 *  - MATCH OUTCOMES (confirm_match / reject_match) — a human telling us the *matcher* was right or
 *    wrong on a specific piece of text. These are the rarest and most valuable rows in the table:
 *    they are labelled retrieval data, which is exactly what a future semantic-search model needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ontology_feedback', function (Blueprint $table) {
            $table->id();

            // accept | edit | reject | merge | create | delete | confirm_match | reject_match
            $table->string('action', 20)->index();

            // What was acted on — a KeywordTerm, OntologyNode, OntologyEdge, or FindingKeyword.
            // Nullable ids: a `reject_match` is about a piece of text, not always a persisted row.
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // The concept the signal belongs to, always resolvable — this is what the enrichment
            // prompt filters on when it asks "what has this workshop already corrected here?".
            $table->foreignId('finding_keyword_id')->nullable()->constrained()->cascadeOnDelete();

            // State before / after, so a correction can be read back as a diff (and undone).
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            // For match outcomes: the text the user was searching with. This is the labelled
            // retrieval pair — query text plus the human's verdict on the answer.
            $table->text('query_text')->nullable();
            $table->unsignedTinyInteger('match_score')->nullable();

            // Why. Free text, optional, but it is the part that actually teaches — "this means
            // something else in our workshop" is worth more than a silent delete.
            $table->string('reason', 500)->nullable();

            // Where in the app the signal came from (keyword_drawer, match_tester, inspection_picker…)
            // so a bad UX pattern producing junk signals can be spotted and excluded.
            $table->string('context', 40)->nullable()->index();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Stamped once this signal has been folded into the enrichment guidance.
            $table->timestamp('applied_at')->nullable()->index();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'ontology_feedback_subject_idx');
            $table->index(['finding_keyword_id', 'action'], 'ontology_feedback_concept_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ontology_feedback');
    }
};
