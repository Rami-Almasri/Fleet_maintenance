<?php

namespace App\Ontology\DTO;

use App\Models\EvidenceLink;

/**
 * One passage of knowledge, from any source.
 *
 * The universal currency of retrieval: a corpus chunk, a web page extract and a fleet statistic all
 * arrive as this. Everything downstream — merging, reranking, prompt grounding, evidence storage,
 * confidence scoring — works on this shape alone and never on a source-specific one.
 *
 * `citation()` is why the class is worth having rather than passing arrays around: an evidence link
 * must survive the deletion of whatever produced it, so the passage carries a fully denormalised
 * citation from the moment it is retrieved.
 */
final class RetrievedPassage
{
    public function __construct(
        /** The text handed to the model. */
        public readonly string $text,

        /** Retriever key: 'corpus' | 'web' | 'fleet' | … */
        public readonly string $source,

        /** How this was obtained — maps to EvidenceLink::METHOD_*. */
        public readonly string $retrievalMethod,

        /** Relevance, 0.0–1.0, as scored by the retriever that produced it. */
        public readonly float $score = 0.5,

        /** Citation, denormalised so it outlives its origin row. */
        public readonly ?string $title = null,
        public readonly ?string $section = null,
        public readonly ?string $url = null,

        /** Origin ids, when the passage came from stored rows. Null for web and derived sources. */
        public readonly ?int $chunkId = null,
        public readonly ?int $documentId = null,
        public readonly ?int $sourceId = null,

        /**
         * Which confidence dimension this passage feeds: 'documentation' | 'fleet' | 'human'.
         * Carried per passage rather than derived from `source`, so one retriever can legitimately
         * emit passages of different kinds (e.g. a corpus containing both manuals and internal
         * procedures written by staff).
         */
        public readonly string $dimension = 'documentation',

        /** Free-form extras a retriever wants to preserve (observation counts, vehicle scope…). */
        public readonly array $meta = [],
    ) {
    }

    /** One-line citation for prompts and UI. */
    public function citation(): string
    {
        return collect([$this->title, $this->section])->filter()->implode(' — ') ?: $this->source;
    }

    /**
     * The attributes needed to persist this as an [[EvidenceLink]]. Kept here so the shape of a
     * citation is defined once, next to the data, rather than reassembled by every writer.
     *
     * @return array<string,mixed>
     */
    public function toEvidenceAttributes(int $confidence): array
    {
        return [
            'knowledge_source_id'   => $this->sourceId,
            'knowledge_document_id' => $this->documentId,
            'knowledge_chunk_id'    => $this->chunkId,
            'retrieval_method'      => $this->retrievalMethod,
            'document_title'        => $this->title ? mb_substr($this->title, 0, 300) : null,
            'section'               => $this->section ? mb_substr($this->section, 0, 300) : null,
            'url'                   => $this->url ? mb_substr($this->url, 0, 1000) : null,
            'snippet'               => mb_substr($this->text, 0, 1000),
            'confidence'            => max(0, min(100, $confidence)),
            'retrieved_at'          => now(),
        ];
    }

    /** True when this passage represents real retrieval rather than an unsourced model claim. */
    public function isGrounded(): bool
    {
        return $this->retrievalMethod !== EvidenceLink::METHOD_MODEL_PRIOR;
    }
}
