<?php

use App\Models\FindingKeyword;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill: give every keyword already in the library its own canonical terms.
 *
 * Without this, an existing install would have an empty `keyword_terms` table until someone ran
 * `keywords:enrich`, and free-text matching would return nothing at all — even for an exact search
 * of a keyword's own name. Seeding the EN (and AR, where present) strings as `source=seed` terms
 * means the ontology works the moment the migration lands; AI enrichment then widens the vocabulary
 * rather than being a prerequisite for it.
 *
 * Idempotent: `syncCanonicalTerms()` upserts on the normalised key, so re-running changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        FindingKeyword::query()->chunkById(200, function ($keywords) {
            foreach ($keywords as $keyword) {
                $keyword->syncCanonicalTerms();
            }
        });
    }

    public function down(): void
    {
        // The terms table is dropped by its own migration; nothing to unwind here.
    }
};
