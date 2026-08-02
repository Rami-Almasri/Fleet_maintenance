<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anchor a match verdict to the inspection that produced it.
 *
 * `ontology_feedback` already stores the query text, the score and a `context` string. Until now the
 * only surface writing those rows was the admin match-tester, where there is nothing to anchor to —
 * someone typing sentences to probe the library.
 *
 * The inspector's test-drive report is a different kind of row entirely, and the difference is worth
 * storing rather than inferring. "This text, on THIS car, at THIS mileage, from the person who had
 * just driven it" is a labelled retrieval pair with provenance; "an admin typed a sentence" is a
 * curation experiment. Both are useful and they must never be averaged together — a corpus that
 * cannot tell them apart cannot be used to measure the matcher, because the admin rows are
 * adversarial by design (you type the hard cases deliberately) and would drag apparent accuracy down.
 *
 * Both columns are nullable and nullOnDelete: the verdict is evidence about the VOCABULARY, and it
 * stays true and useful after the ticket is closed or the car leaves the fleet. Losing the anchor
 * costs context; losing the row would cost ground truth.
 *
 * See [[OntologyFeedbackService]] and the `test_findings` context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ontology_feedback', function (Blueprint $table) {
            // The ticket whose test-drive report the verdict was given on.
            $table->foreignId('maintenance_id')->nullable()->after('match_score')
                ->constrained('maintenances')->nullOnDelete();

            // Denormalised on purpose. The vehicle is reachable through the ticket, but the question
            // "does this fault get mismatched on a particular model?" is the one worth asking of this
            // corpus, and it must survive the ticket being deleted.
            $table->foreignId('vehicle_id')->nullable()->after('maintenance_id')
                ->constrained('vehicles')->nullOnDelete();

            $table->index(['context', 'action'], 'ontology_feedback_context_action_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ontology_feedback', function (Blueprint $table) {
            $table->dropIndex('ontology_feedback_context_action_idx');
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropConstrainedForeignId('maintenance_id');
        });
    }
};
