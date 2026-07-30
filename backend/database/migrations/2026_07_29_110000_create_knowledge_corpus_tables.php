<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The KNOWLEDGE CORPUS — the retrieval half of the Automotive Knowledge Engine.
 *
 * V1 enrichment asked the model what it already knew. That is a training-data answer: unattributable,
 * unversioned, and impossible to audit. These four tables make enrichment RETRIEVAL-GROUNDED — the
 * engine finds documentation first, then generates from it, then keeps the evidence.
 *
 * FOUR TABLES, ONE JOB EACH
 *  - knowledge_sources   — the registry of bodies of knowledge we are ALLOWED to use, and how.
 *  - knowledge_documents — one manual / bulletin / paper / uploaded PDF.
 *  - knowledge_chunks    — retrievable passages of a document, with an optional embedding.
 *  - evidence_links      — "this term / this relationship is backed by THAT passage." Polymorphic,
 *                          because everything in the ontology needs to be able to cite something.
 *
 * ⚠️ LICENSING IS MODELLED, NOT ASSUMED. `knowledge_sources.access` records how each source may
 * legally be used — `licensed` (ALLDATA, Mitchell 1, Haynes, Chilton, OEM service manuals: paid,
 * copyrighted, usable only through a subscription/API the fleet holds), `public_web` (government
 * TSBs, manufacturer public technical pages, supplier technical libraries), `uploaded` (documents
 * the fleet itself owns and ingests), `derived` (our own repair history). A `licensed` source with
 * no credentials configured is simply skipped by the retriever rather than scraped. This is the
 * column that keeps the engine on the right side of a content licence as it grows.
 *
 * `embedding` is nullable and unused until an embeddings provider is configured — retrieval falls
 * back to lexical scoring over `normalized`. See [[KnowledgeRetrievalService]].
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Which bodies of knowledge exist, how much we trust them, how we may use them ----
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();

            $table->string('key', 60)->unique();      // bosch, ase, sae, nhtsa, oem_toyota, fleet_history
            $table->string('name', 160);
            $table->string('publisher', 160)->nullable();

            // Trust tier — drives both retrieval ranking and the confidence a generated term inherits.
            // tier1_oem > tier2_professional > tier3_reference > tier4_public > fleet (our own data,
            // which is separately weighted because it is observational rather than documentary).
            $table->string('tier', 24)->default('tier3_reference')->index();
            $table->unsignedTinyInteger('trust_weight')->default(60);

            // How this source may legally be retrieved from. See the class doc — this is the field
            // that stops the engine from ever scraping a paid corpus.
            //   licensed | public_web | uploaded | derived
            $table->string('access', 20)->default('public_web')->index();

            // For public_web sources: the domains the retriever is allowed to search/fetch. Passed
            // straight to the web-search tool's allowed_domains, so the model cannot wander off into
            // blogs and forums even if it wants to.
            $table->json('domains')->nullable();

            $table->string('base_url', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });

        // ---- One document: a manual, a bulletin, a paper, an uploaded PDF ----
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('knowledge_source_id')->constrained()->cascadeOnDelete();

            $table->string('title', 300);
            // service_manual | tsb | repair_guide | spec_sheet | paper | regulation | internal
            $table->string('doc_type', 30)->default('repair_guide')->index();
            $table->string('url', 1000)->nullable();
            $table->string('publisher', 160)->nullable();
            $table->date('published_at')->nullable();
            $table->string('language', 5)->default('en');

            // Vehicle scope. A Toyota Camry brake procedure must never be cited as evidence for a
            // BMW. NULL means the document is universal (a Bosch braking-systems chapter, an SAE
            // paper on rotor thickness variation). `scope_key` is the denormalised comparison form
            // — see App\Support\VehicleScope — because MySQL treats NULLs as distinct in a unique
            // index and scope matching would otherwise need six nullable comparisons per query.
            $table->string('make', 60)->nullable()->index();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->string('engine', 60)->nullable();
            $table->string('platform', 60)->nullable();
            $table->string('scope_key', 120)->default('*')->index();

            // Ingestion bookkeeping: checksum de-duplicates a re-uploaded file, chunk_count avoids
            // a COUNT on every listing.
            $table->string('checksum', 64)->nullable()->index();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('ingested_at')->nullable();

            $table->timestamps();
        });

        // ---- Retrievable passages ----
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('knowledge_document_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('ordinal')->default(0);       // position within the document
            $table->string('section', 300)->nullable();           // "Section 5.2 — Brake pad wear"
            $table->string('heading', 300)->nullable();
            $table->text('text');
            $table->unsignedInteger('token_count')->default(0);

            // Lexical retrieval key (App\Support\TextNormalizer). Always populated — this is the
            // retrieval path that works with no embeddings provider configured.
            $table->mediumText('normalized')->nullable();

            // Vector retrieval. NULL until an embeddings provider is configured; the retriever
            // silently uses the lexical path meanwhile, so nothing depends on this being filled.
            $table->json('embedding')->nullable();
            $table->string('embedding_model', 60)->nullable()->index();

            $table->timestamps();

            $table->index(['knowledge_document_id', 'ordinal']);
        });

        // ---- "This claim is backed by that passage" ----
        Schema::create('evidence_links', function (Blueprint $table) {
            $table->id();

            // What is being supported — a KeywordTerm, an OntologyEdge, a KeywordProfile, a
            // FindingKeyword. Polymorphic because every assertion the engine makes must be able to
            // cite its basis, and the set of assertion types will keep growing.
            $table->morphs('evidenceable');

            // Where the support came from. All three are nullable because evidence can be as
            // specific as one passage or as coarse as "the model's own knowledge of Bosch practice"
            // — and we would rather record a weak, honestly-labelled citation than none.
            $table->foreignId('knowledge_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('knowledge_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('knowledge_chunk_id')->nullable()->constrained()->nullOnDelete();

            // How this evidence was obtained. This is the honesty column: `model_prior` means the
            // model asserted it from training with no retrieval behind it, and the UI says so
            // rather than dressing it up as a citation.
            //   corpus | web_search | model_prior | fleet | human
            $table->string('retrieval_method', 20)->default('model_prior')->index();

            // The human-readable citation, kept even when the chunk is later deleted — an audit
            // trail that evaporates when someone prunes a document is not an audit trail.
            $table->string('document_title', 300)->nullable();
            $table->string('section', 300)->nullable();
            $table->string('url', 1000)->nullable();
            $table->text('snippet')->nullable();

            $table->unsignedTinyInteger('confidence')->default(50);
            $table->timestamp('retrieved_at')->nullable();

            $table->timestamps();

            $table->index(['retrieval_method', 'confidence'], 'evidence_method_confidence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_links');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('knowledge_sources');
    }
};
