<?php

namespace App\Ontology\Matching\Stages;

use App\Ontology\Contracts\EmbeddingProvider;
use App\Ontology\Contracts\KnowledgeRetriever;
use App\Ontology\Matching\MatchCandidate;
use App\Ontology\Matching\MatchQuery;
use App\Ontology\Matching\MatchStage;
use App\Ontology\Matching\TermIndex;
use App\Services\KeywordOntologyService;
use App\Support\TextNormalizer;

/**
 * Stage 6 — semantic recall. Runs LAST and only ever ADDS.
 *
 * This is the stage that eventually handles "the steering wheel shakes only while braking at
 * highway speed" → Brake vibration, where no literal wording overlaps at all.
 *
 * ── THE RULE THAT KEEPS IT SAFE ──────────────────────────────────────────────────────────────────
 * Semantic evidence may surface a candidate the deterministic stages missed, and may strengthen one
 * they found. It may NEVER outrank an exact match or suppress a lexical hit. That is enforced
 * structurally rather than by convention: this stage caps its own score below the exact/alias
 * bands, and a candidate's score is the strongest single piece of evidence, so a semantic hit
 * cannot displace a stronger deterministic one no matter how confident the vector is.
 *
 * Deterministic-first is not a transitional stance. Lexical matching is auditable, instant, offline,
 * and explainable to a technician in one sentence; embeddings are none of those things. Semantic
 * search is here to catch what the vocabulary missed, not to replace the vocabulary.
 *
 * ── CURRENT STATE ────────────────────────────────────────────────────────────────────────────────
 * With no embedding provider configured, this degrades to KNOWLEDGE-BASED recall: it asks the
 * retrieval layer for passages about the query and credits the concepts those passages already
 * point at. That is genuinely useful today — a fleet statistic or a manual passage can surface a
 * fault the words missed — and it means the stage is exercised in production before any embedding
 * vendor is chosen, rather than being dead code waiting for a purchase order.
 */
class SemanticStage implements MatchStage
{
    /** Hard ceiling: below the alias band (88-96) so semantic can never outrank an exact form. */
    private const MAX_SCORE = 68.0;

    /**
     * Only the embedding provider is injected. The fleet retriever and the matcher are resolved
     * lazily inside `run()` to break a container cycle: KeywordOntologyService → MatchPipeline →
     * SemanticStage → FleetRetriever → KeywordOntologyService. Constructor-injecting either end
     * would make the container recurse the moment anything asked for a matcher.
     */
    public function __construct(private readonly EmbeddingProvider $embeddings)
    {
    }

    private function fleetRetriever(): KnowledgeRetriever
    {
        return app(\App\Ontology\Retrieval\FleetRetriever::class);
    }

    private function ontology(): KeywordOntologyService
    {
        return app(KeywordOntologyService::class);
    }

    public function key(): string
    {
        return 'semantic';
    }

    public function run(MatchQuery $query, TermIndex $index, array &$candidates): void
    {
        if ($this->embeddings->isAvailable()) {
            $this->runVector($query, $index, $candidates);

            return;
        }

        $this->runKnowledgeBased($query, $candidates);
    }

    /**
     * Vector recall over the term corpus.
     *
     * Terms are embedded and compared to the query vector. Only similarity above a firm floor
     * counts — a low cosine is not weak evidence, it is noise, and admitting it would fill every
     * result set with plausible-looking wrong answers.
     */
    private function runVector(MatchQuery $query, TermIndex $index, array &$candidates): void
    {
        $vectors = $this->embeddings->embed([$query->text]);
        $queryVector = $vectors[0] ?? null;

        if (! $queryVector) {
            return;
        }

        // Embedding every term on every query would be prohibitive, so this path expects vectors to
        // have been precomputed and stored. Until that backfill exists, the knowledge-based path
        // below is the live behaviour — see the class doc.
        $this->runKnowledgeBased($query, $candidates);
    }

    /**
     * Recall via the knowledge layer: ask the retrievers what they know about this query, and
     * credit the concepts their passages are about.
     *
     * The fleet retriever is the useful one here — it resolves a query to a concept and returns
     * measured relationships, which surfaces neighbouring faults ("cars with this symptom also
     * needed X") that share no vocabulary with what was typed.
     */
    private function runKnowledgeBased(MatchQuery $query, array &$candidates): void
    {
        $fleet = $this->fleetRetriever();
        if (! $fleet->isAvailable()) {
            return;
        }

        $passages = $fleet->retrieve($query->text, $query->scopeChain, 4);

        foreach ($passages as $passage) {
            $related = $passage->meta['related_keyword_id'] ?? null;

            // The passage names a related fault in prose; resolve it back to a concept so the
            // candidate set gains it. Deliberately uses the ordinary matcher — no special path.
            $label = $passage->meta['related_label'] ?? null;
            if (! $related && $label) {
                $resolved = $this->ontology()->resolve($label, ['limit' => 1, 'min_score' => 70])->first();
                $related = $resolved['keyword']->id ?? null;
            }

            if (! $related) {
                continue;
            }

            $score = min(self::MAX_SCORE, $passage->score * 100);

            $candidates[$related] ??= new MatchCandidate($related);
            $candidates[$related]->addEvidence(
                $this->key(),
                $score,
                'related through our own maintenance history: '.mb_substr($passage->text, 0, 160),
                'fleet',
                ['source' => $passage->source, 'observed' => $passage->meta['observed_count'] ?? null],
            );
        }
    }

    /** Exposed for the pipeline's capability report — semantic is optional and says so. */
    public function isSemantic(): bool
    {
        return $this->embeddings->isAvailable();
    }
}
