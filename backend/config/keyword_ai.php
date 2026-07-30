<?php

/**
 * AI Keyword Intelligence — configuration for the automotive ontology engine.
 *
 * The findings keyword library ([[keyword-risk-library]]) used to be a hand-curated list of bare
 * strings ("Dent", "Brake noise"). This config drives the layer that turns each of those keywords
 * into a KNOWLEDGE-BASE CONCEPT: many surface forms (synonyms, workshop slang, abbreviations,
 * spelling variants, misspellings, Arabic translations) plus structured engineering metadata
 * (system, components, causes, repairs), each carrying a confidence + evidence source.
 *
 * Two halves, deliberately separable:
 *  - ENRICHMENT (this file's `model` / `sources` / `prompt` keys) — an offline batch that calls
 *    Claude once per keyword and writes rows. Needs ANTHROPIC_API_KEY; nothing else does.
 *  - MATCHING (`matching` key) — pure PHP over the rows that already exist. Works with zero API
 *    access, which is why the search layer never depends on the AI being reachable.
 *
 * See [[traceability-visibility-requirement]]: every generated row records WHERE it came from
 * (source = ai/human/seed), HOW sure the model was (confidence), and WHICH run produced it, so the
 * admin screen can always answer "why is this word in my database?".
 */

return [

    /*
    |----------------------------------------------------------------------
    | Model
    |----------------------------------------------------------------------
    | Enrichment is a low-volume, high-value batch (one call per keyword, a few hundred keywords
    | total, re-run only when the library changes) so it runs on the strongest model rather than
    | the cheapest. `effort` trades thinking depth against tokens — medium is plenty for a
    | well-specified extraction task with a locked JSON schema.
    */
    'api_key'     => env('ANTHROPIC_API_KEY'),
    'model'       => env('KEYWORD_AI_MODEL', 'claude-opus-5'),
    'effort'      => env('KEYWORD_AI_EFFORT', 'medium'),
    'max_tokens'  => (int) env('KEYWORD_AI_MAX_TOKENS', 16000),
    'timeout'     => (int) env('KEYWORD_AI_TIMEOUT', 300),

    /*
    |----------------------------------------------------------------------
    | Trusted knowledge sources
    |----------------------------------------------------------------------
    | The model is told to ground every term and every repair action in professional automotive
    | documentation and to name which body of knowledge it drew on. This list is injected verbatim
    | into the system prompt AND is the allowed vocabulary for `evidence_sources`, so an admin can
    | filter the library by provenance. Blogs/forums are explicitly excluded unless corroborated.
    */
    'sources' => [
        'OEM service manuals',
        'Manufacturer repair manuals',
        'ASE (National Institute for Automotive Service Excellence)',
        'Haynes',
        'Chilton',
        'ALLDATA',
        'Mitchell 1',
        'Bosch Automotive Handbook',
        'Denso',
        'NGK',
        'ACDelco',
        'MOTOR',
        'SAE technical papers',
        'Official workshop documentation',
        'Fleet maintenance manuals',
        'Government transportation repair documentation',
    ],

    /*
    |----------------------------------------------------------------------
    | Generation targets
    |----------------------------------------------------------------------
    | Soft targets handed to the model. `min_terms` is what makes the difference between a keyword
    | list and a search ontology — one obvious term is useless for matching free text; a dozen
    | surface forms per concept is what lets "strange metallic sound when braking" land on
    | "Brake noise (squeal / grind)".
    */
    'targets' => [
        'min_terms'     => 12,
        'max_terms'     => 40,
        'min_arabic'    => 4,   // at least this many of the terms must be Arabic
        'min_misspell'  => 2,   // deliberate misspellings — they maximise real-world matching
    ],

    /*
    |----------------------------------------------------------------------
    | Retrieval (RAG)
    |----------------------------------------------------------------------
    | Enrichment retrieves documentation BEFORE generating, so terms and relationships can cite a
    | source instead of resting on the model's training. Two retrieval paths, both optional and both
    | degrading cleanly to "model prior, and we'll say so":
    |
    |  - `corpus`     — documents ingested into knowledge_chunks (`knowledge:ingest`). Highest trust.
    |  - `web`        — Claude's server-side web search/fetch, HARD-RESTRICTED to the domains
    |                   registered against public-web sources in the knowledge_sources table. The
    |                   model physically cannot read a domain that isn't registered.
    |
    | Turn `web` off for a fully air-gapped enrichment run that uses only the local corpus.
    */
    'retrieval' => [
        'corpus'             => env('KEYWORD_AI_RETRIEVAL_CORPUS', true),
        'web'                => env('KEYWORD_AI_RETRIEVAL_WEB', true),
        'max_searches'       => 4,
        'max_fetches'        => 4,
        'max_content_tokens' => 12000,
        'passages'           => 6,   // corpus passages injected into the prompt
    ],

    /*
    |----------------------------------------------------------------------
    | Licensed sources
    |----------------------------------------------------------------------
    | ALLDATA, Mitchell 1, Haynes, Chilton and OEM factory service manuals are PAID, COPYRIGHTED
    | products. There is no lawful way to retrieve their content without a subscription and their
    | API — so the engine never scrapes them. A source registered as `licensed` in knowledge_sources
    | is skipped by the retriever unless credentials appear here, at which point its ingestion
    | adapter can pull content into the local corpus like any other document.
    |
    | Add a key here only when the fleet actually holds that subscription.
    */
    'licensed_sources' => [
        'alldata'    => ['api_key' => env('ALLDATA_API_KEY'),    'base_url' => env('ALLDATA_BASE_URL')],
        'mitchell1'  => ['api_key' => env('MITCHELL1_API_KEY'),  'base_url' => env('MITCHELL1_BASE_URL')],
        'haynes'     => ['api_key' => env('HAYNES_API_KEY'),     'base_url' => env('HAYNES_BASE_URL')],
        'chilton'    => ['api_key' => env('CHILTON_API_KEY'),    'base_url' => env('CHILTON_BASE_URL')],
    ],

    /*
    |----------------------------------------------------------------------
    | Embeddings (semantic search)
    |----------------------------------------------------------------------
    | Anthropic does not sell an embeddings endpoint, so vector search needs a separate vendor.
    | Leave `driver` as `null` and retrieval stays lexical — which works, just less well on
    | paraphrases. Set it when you've chosen a provider; nothing else in the engine changes.
    */
    'embeddings' => [
        'driver'     => env('KEYWORD_AI_EMBEDDINGS_DRIVER'),   // null | voyage | openai | local
        'model'      => env('KEYWORD_AI_EMBEDDINGS_MODEL'),
        'api_key'    => env('KEYWORD_AI_EMBEDDINGS_KEY'),
        'dimensions' => (int) env('KEYWORD_AI_EMBEDDINGS_DIMS', 1024),
    ],

    /*
    |----------------------------------------------------------------------
    | Matching engine
    |----------------------------------------------------------------------
    | Scores for KeywordOntologyService. A concept's score is the best single term match, boosted a
    | little for each ADDITIONAL distinct term that also matched (a sentence hitting three of a
    | concept's terms is stronger evidence than one hitting a single term).
    |
    | `min_score` is the floor for a row to be returned at all; `strong_score` is the bar above
    | which the UI presents a match as confident rather than as a suggestion.
    */
    'matching' => [
        'min_score'         => 25,
        'strong_score'      => 70,
        'limit'             => 8,
        'fuzzy_threshold'   => 0.84,  // similar_text ratio for a single-token near miss (typos)
        'multi_term_bonus'  => 6,     // per extra distinct term matched, capped at 3 extras
        'kind_weights'      => [
            'canonical'       => 1.00,
            'synonym'         => 0.98,
            'translation'     => 0.98,
            'workshop_phrase' => 0.95,
            'abbreviation'    => 0.92,
            'spelling_variant'=> 0.92,
            // Raised from 0.85 after a safety-relevant misranking: "breaks making a grinding noise"
            // put Engine noise (68) above Brake noise (67), because Engine noise matched the single
            // generic token "noise" at full weight while the far more specific misspelling
            // "grinding breaks" was docked 15%.
            //
            // A misspelling is not weak evidence of intent — someone typing "grinding breaks" means
            // brakes, unambiguously. The small remaining discount only breaks ties in favour of
            // correctly-spelled vocabulary; it must never let a generic partial match outrank a
            // specific one, least of all on a braking fault.
            'misspelling'     => 0.96,

            // Customer wording is real evidence but weaker evidence: "it feels heavy" genuinely
            // indicates a fault, and genuinely indicates several. Ranking it below the technical
            // vocabulary means a complaint SURFACES the right concepts without a loose sensation
            // ever outranking a technician naming the fault outright.
            'customer_phrase' => 0.82,
        ],
    ],

];
