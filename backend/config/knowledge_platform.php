<?php

use App\Ontology\Providers\Anthropic\AnthropicExtractionProvider;
use App\Ontology\Providers\Anthropic\AnthropicResearchProvider;
use App\Ontology\Providers\NullDriver\NullEmbeddingProvider;
use App\Ontology\Providers\NullDriver\NullRerankerProvider;
use App\Ontology\Providers\NullDriver\NullResearchProvider;
use App\Ontology\Retrieval\CorpusRetriever;
use App\Ontology\Retrieval\FleetRetriever;
use App\Ontology\Retrieval\WebRetriever;

/**
 * The Automotive Knowledge Platform — wiring.
 *
 * NOTE ON NAMING: `config/knowledge.php` and `App\Services\Knowledge` already belong to the
 * maintenance-side Fleet Knowledge Engine (repair recommendations over maintenance_signatures).
 * This platform lives under `App\Ontology` and this file, so the two never collide. The boundary
 * is the one from [[repair-intelligence-boundary]]: maintenance reads the ontology, never the
 * reverse.
 *
 * Everything the engine does behind an interface is selected here. Business logic never names a
 * vendor: swapping Claude for GPT, Gemini, Mistral or a local model is an edit to this file plus
 * one class implementing the contract — no change to enrichment, matching, retrieval or scoring.
 *
 * FOUR CAPABILITIES, FOUR CONTRACTS
 *   research   — read the open literature and report findings      (ResearchProvider)
 *   extraction — turn context into a validated structured payload  (ExtractionProvider)
 *   embedding  — turn text into a vector                           (EmbeddingProvider)
 *   reranker   — reorder retrieved passages by true relevance      (RerankerProvider)
 *
 * They are separate on purpose. Research wants a strong tool-using model; extraction wants cheap
 * and schema-reliable; embedding and reranking are usually a different vendor entirely. Collapsing
 * them into one "AI provider" would make mixing impossible — which is what real deployments need.
 *
 * Every capability has a null implementation, so an unconfigured one degrades to a documented
 * no-op instead of an exception: the engine keeps working with less signal and says so.
 */

return [

    /*
    |----------------------------------------------------------------------
    | Active providers
    |----------------------------------------------------------------------
    | Each key resolves to a class in `implementations`. `null` disables the capability — the null
    | implementation is used and its absence is reported in the engine's output.
    */
    'providers' => [
        'research'   => env('KNOWLEDGE_RESEARCH_PROVIDER', 'anthropic'),
        'extraction' => env('KNOWLEDGE_EXTRACTION_PROVIDER', 'anthropic'),
        'embedding'  => env('KNOWLEDGE_EMBEDDING_PROVIDER'),    // null until a vendor is chosen
        'reranker'   => env('KNOWLEDGE_RERANKER_PROVIDER'),
    ],

    /*
    |----------------------------------------------------------------------
    | Implementations
    |----------------------------------------------------------------------
    | To run enrichment on OpenAI: write `OpenAiExtractionProvider implements ExtractionProvider`,
    | register it below as `'openai' => OpenAiExtractionProvider::class`, set the env var. Nothing
    | else in the codebase changes.
    */
    'implementations' => [
        'research' => [
            'anthropic' => AnthropicResearchProvider::class,
            'null'      => NullResearchProvider::class,
        ],
        'extraction' => [
            'anthropic' => AnthropicExtractionProvider::class,
        ],
        'embedding' => [
            'null' => NullEmbeddingProvider::class,
        ],
        'reranker' => [
            'null' => NullRerankerProvider::class,
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Per-provider settings
    |----------------------------------------------------------------------
    | Namespaced by provider key so two vendors can be configured side by side (research on one,
    | extraction on another) without their settings colliding.
    */
    'anthropic' => [
        'api_key'    => env('ANTHROPIC_API_KEY'),
        'model'      => env('KNOWLEDGE_ANTHROPIC_MODEL', 'claude-opus-5'),
        'effort'     => env('KNOWLEDGE_ANTHROPIC_EFFORT', 'medium'),
        'max_tokens' => (int) env('KNOWLEDGE_ANTHROPIC_MAX_TOKENS', 16000),
    ],

    /*
    |----------------------------------------------------------------------
    | Retrieval sources
    |----------------------------------------------------------------------
    | Every source implements the SAME KnowledgeRetriever contract — a local corpus, the public
    | web, our own fleet history, and (once licensed) ALLDATA or Mitchell 1 are all just
    | retrievers. The engine asks the manager for passages and never learns where they came from.
    |
    | `weight` scales a retriever's passages when results are merged; `enabled` turns one off
    | without unregistering it.
    */
    'retrievers' => [
        'corpus' => ['class' => CorpusRetriever::class, 'enabled' => true, 'weight' => 1.00],
        'fleet'  => ['class' => FleetRetriever::class,  'enabled' => true, 'weight' => 1.00],
        'web'    => ['class' => WebRetriever::class,    'enabled' => env('KNOWLEDGE_RETRIEVAL_WEB', true), 'weight' => 0.85],
    ],

    'retrieval' => [
        'passages'   => 8,     // total passages handed to extraction after the merge
        'per_source' => 4,     // cap per retriever, so one chatty source can't crowd out the rest
    ],

    /*
    |----------------------------------------------------------------------
    | Confidence model
    |----------------------------------------------------------------------
    | Every layer contributes its own dimension and the final score is a weighted blend of the
    | dimensions actually PRESENT. Tunable here rather than buried in code, because the right
    | balance is an operational judgement that shifts as the corpus and fleet history grow.
    |
    | `fleet` outweighs `documentation` deliberately: a pattern measured on our own cars, in this
    | climate, with these drivers, predicts our next repair better than a general manual. `ai` is
    | weighted lowest — an unsourced model claim is the weakest evidence in the system.
    */
    'confidence' => [
        'weights' => [
            'human'         => 1.00,   // a person stated it
            'fleet'         => 0.90,   // measured on our own vehicles
            'documentation' => 0.85,   // retrieved from professional sources
            'lexical'       => 0.70,   // the words actually matched
            'embedding'     => 0.60,   // semantically close
            'ai'            => 0.45,   // model prior, nothing retrieved
        ],

        // A score built from one dimension is less trustworthy than the same score built from four
        // that agree. Each additional dimension lifts the ceiling; a single-dimension answer is
        // capped, so "one strong lexical hit" can never read as certainty.
        'coverage_ceiling' => [1 => 72, 2 => 86, 3 => 94, 4 => 100],
    ],

    /*
    |----------------------------------------------------------------------
    | Versioning
    |----------------------------------------------------------------------
    | Bump a version when the thing it names changes in a way that could change output. They are
    | stamped on every enrichment run, so a regenerated entry can always be explained — "the prompt
    | went 2.0 → 2.1", not "the AI felt different today".
    */
    'versions' => [
        'prompt'    => env('KNOWLEDGE_PROMPT_VERSION', '2.0'),
        'ontology'  => env('KNOWLEDGE_ONTOLOGY_VERSION', '2.0'),
        'retrieval' => env('KNOWLEDGE_RETRIEVAL_VERSION', '2.0'),
    ],

    /*
    |----------------------------------------------------------------------
    | Matching pipeline
    |----------------------------------------------------------------------
    | Deterministic stages run in order, each contributing explainable evidence. Semantic retrieval
    | runs LAST and only ever ADDS recall: it can surface a candidate the deterministic stages
    | missed, but it can never outrank or suppress an exact match.
    */
    'matching' => [
        'stages'    => ['exact', 'alias', 'phrase', 'token', 'fuzzy', 'semantic'],
        'min_score' => 25,
        'strong'    => 70,
        'limit'     => 8,
    ],

];
