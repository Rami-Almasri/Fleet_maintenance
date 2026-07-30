<?php

namespace App\Ontology\Versioning;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Models\OntologyEdge;
use App\Ontology\Contracts\EmbeddingProvider;
use App\Ontology\Contracts\ExtractionProvider;
use App\Ontology\Contracts\ResearchProvider;

/**
 * Captures everything that could make a regenerated enrichment differ from the previous one.
 *
 * Reproducibility in an LLM pipeline is not about determinism — the same prompt to the same model
 * can differ run to run. It is about ATTRIBUTABILITY: when the output changes, being able to say
 * which of the moving parts moved. There are only four kinds:
 *
 *   1. the providers      (a vendor or model was swapped)
 *   2. the software       (prompt / ontology / retrieval versions were bumped)
 *   3. the knowledge      (documents ingested or removed, fleet edges re-mined)
 *   4. genuine variance   (everything above is identical — so it was the model, and now you know)
 *
 * That fourth line is the valuable one. Without a snapshot you can never reach it, and every
 * unexplained diff gets blamed on the AI.
 *
 * The corpus hash is over document CHECKSUMS rather than counts, so swapping one document for
 * another of the same size still changes it.
 */
class RunProvenance
{
    public function __construct(
        private readonly ResearchProvider $research,
        private readonly ExtractionProvider $extraction,
        private readonly EmbeddingProvider $embeddings,
    ) {
    }

    /**
     * The full provenance stamp for a run.
     *
     * @param  array<string,int>  $retrievalSummary  retriever key => passages contributed
     * @return array<string,mixed>  columns for keyword_enrichment_runs
     */
    public function stamp(string $scopeKey = '*', array $retrievalSummary = []): array
    {
        return [
            'research_provider'   => $this->research->name(),
            'research_model'      => $this->research->version(),
            'extraction_provider' => $this->extraction->name(),
            'extraction_model'    => $this->extraction->version(),
            'embedding_model'     => $this->embeddings->isAvailable() ? $this->embeddings->version() : null,

            'prompt_version'    => (string) config('knowledge_platform.versions.prompt'),
            'ontology_version'  => (string) config('knowledge_platform.versions.ontology'),
            'retrieval_version' => (string) config('knowledge_platform.versions.retrieval'),

            'source_snapshot'   => $this->corpusSnapshot(),
            'retrieval_summary' => $retrievalSummary,
            'scope_key'         => $scopeKey,

            // Kept in step with the extraction model for rows written before the split existed.
            'model' => $this->extraction->version(),
        ];
    }

    /**
     * The state of the knowledge base at this moment.
     *
     * Counts make a change visible at a glance; the hash makes it precise. Fleet edges are counted
     * separately from documents because they move on a completely different cadence — a nightly
     * mining job changes fleet knowledge without touching a single document.
     *
     * @return array<string,mixed>
     */
    public function corpusSnapshot(): array
    {
        $checksums = KnowledgeDocument::query()
            ->orderBy('id')
            ->pluck('checksum')
            ->filter()
            ->implode('|');

        return [
            'sources'     => KnowledgeSource::query()->where('is_active', true)->count(),
            'documents'   => KnowledgeDocument::query()->count(),
            'chunks'      => KnowledgeChunk::query()->count(),
            'fleet_edges' => OntologyEdge::query()->where('source', OntologyEdge::SOURCE_FLEET)->count(),
            // Short hash: this is a change detector, not a security control.
            'corpus_hash' => $checksums === '' ? null : substr(hash('sha256', $checksums), 0, 16),
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Explain the difference between two runs of the same keyword — the question this whole class
     * exists to answer. Returns a list of plain sentences, or an empty list when nothing that
     * could affect output changed (in which case the difference was model variance, and saying so
     * plainly is more useful than implying a cause).
     *
     * @return array<int,string>
     */
    public function diff(?object $previous, ?object $current): array
    {
        if (! $previous || ! $current) {
            return [];
        }

        $changes = [];

        $fields = [
            'extraction_model'    => 'the extraction model',
            'research_model'      => 'the research model',
            'extraction_provider' => 'the extraction provider',
            'research_provider'   => 'the research provider',
            'embedding_model'     => 'the embedding model',
            'prompt_version'      => 'the prompt version',
            'ontology_version'    => 'the ontology version',
            'retrieval_version'   => 'the retrieval version',
        ];

        foreach ($fields as $field => $label) {
            $was = $previous->{$field} ?? null;
            $now = $current->{$field} ?? null;
            if ($was !== $now) {
                $changes[] = ucfirst($label).' changed from '.($was ?: 'none').' to '.($now ?: 'none').'.';
            }
        }

        $before = $previous->source_snapshot ?? [];
        $after  = $current->source_snapshot ?? [];

        if (($before['corpus_hash'] ?? null) !== ($after['corpus_hash'] ?? null)) {
            $changes[] = sprintf(
                'The document corpus changed: %d → %d documents, %d → %d passages.',
                $before['documents'] ?? 0, $after['documents'] ?? 0,
                $before['chunks'] ?? 0, $after['chunks'] ?? 0,
            );
        }

        if (($before['fleet_edges'] ?? 0) !== ($after['fleet_edges'] ?? 0)) {
            $changes[] = sprintf(
                'Fleet knowledge changed: %d → %d learned relationships.',
                $before['fleet_edges'] ?? 0, $after['fleet_edges'] ?? 0,
            );
        }

        return $changes;
    }
}
