<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyword TERMS — the surface forms of a findings keyword.
 *
 * This is the table that turns the keyword library from a *list* into an *ontology*. Before it, a
 * fault had exactly one string: "Brake noise (squeal / grind)". A technician who typed "brake
 * squeal", "grinding brakes", "صوت الفرامل", or "break noise" matched nothing.
 *
 * Now `finding_keywords` holds the CONCEPT (the stable analytics key, its risk grade, its category)
 * and this table holds every way a human might write it — synonyms, workshop slang, abbreviations,
 * British/American spellings, common misspellings, and Arabic translations. Matching happens
 * against `normalized` (see App\Support\TextNormalizer): lower-cased, punctuation-stripped, and for
 * Arabic also diacritic/alef/yeh-normalised, so "A/C", "a c" and "AC" collapse to one key.
 *
 * Every row is traceable ([[traceability-visibility-requirement]]): `source` says who wrote it
 * (seed / ai / human), `confidence` how sure the model was, `source_quality` which body of
 * professional documentation backs it, and `enrichment_run_id` which batch produced it. Nothing in
 * here is a black box, and a human edit is never overwritten by a later AI run (see the service).
 *
 * NOTE: nothing about ticket storage changes. `maintenances.findings` still persists the CANONICAL
 * English keyword string exactly as before — terms are the search / input surface, not the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_terms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('finding_keyword_id')->constrained()->cascadeOnDelete();

            // The term exactly as a human would write it (display form), and the comparison key.
            // 191 keeps the composite unique index inside InnoDB's utf8mb4 key-length limit.
            $table->string('term', 191);
            $table->string('normalized', 191)->index();

            // 'en' | 'ar'. Kept explicit rather than sniffed so an admin can add a bilingual variant.
            $table->string('lang', 5)->default('en')->index();

            // What KIND of surface form this is — drives both the match weighting (a misspelling is
            // weaker evidence than a synonym) and how the admin UI groups the chips.
            //   canonical | synonym | workshop_phrase | abbreviation | spelling_variant
            //   | misspelling | translation
            $table->string('kind', 24)->default('synonym')->index();

            // 0–100. How confident the generator is that this term really denotes this fault.
            $table->unsignedTinyInteger('confidence')->default(80);

            // Provenance. 'seed' = shipped with the app, 'ai' = generated, 'human' = admin typed or
            // edited it. Human always wins: enrichment never touches a row marked human.
            $table->string('source', 12)->default('ai')->index();

            // Which class of professional documentation backs the term (oem / ase / manual /
            // workshop / general) and how often the wording is actually heard in a workshop
            // (very_high / high / medium / low / rare). Both feed `search_rank`.
            $table->string('source_quality', 20)->nullable();
            $table->string('workshop_frequency', 20)->nullable();

            // Pre-computed ordering hint (0–100) so listing/ranking never re-derives the blend of
            // confidence + frequency + kind at query time.
            $table->unsignedTinyInteger('search_rank')->default(50)->index();

            // Soft off-switch: retire a bad term without losing the audit trail of it existing.
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            // One row per (concept, comparison key): re-running enrichment upserts rather than
            // piling up near-duplicates, and two spellings that normalise identically collapse.
            $table->unique(['finding_keyword_id', 'normalized'], 'keyword_terms_concept_normalized_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_terms');
    }
};
