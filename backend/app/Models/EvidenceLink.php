<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "This claim is backed by that document." The citation behind one thing the engine asserts.
 *
 * Attached polymorphically to a [[KeywordTerm]], an [[OntologyEdge]], a [[KeywordProfile]] or a
 * [[FindingKeyword]], because every assertion the knowledge engine makes has to be able to say
 * where it came from.
 *
 * `retrieval_method` is the honesty column and the reason this table is worth having. `corpus` and
 * `web_search` mean a document was actually retrieved and read. `fleet` means we counted it in our
 * own tickets. `model_prior` means the model asserted it from training with nothing retrieved — a
 * perfectly legitimate source of a synonym, but NOT a citation, and the UI labels it as such rather
 * than dressing it up. A knowledge base that can't tell those apart is just a confident guess.
 *
 * The citation fields (`document_title`, `section`, `url`, `snippet`) are DENORMALISED on purpose:
 * they survive the deletion of the chunk or document they came from. An audit trail that evaporates
 * when someone prunes the corpus is not an audit trail.
 */
class EvidenceLink extends Model
{
    public const METHOD_CORPUS      = 'corpus';       // retrieved from an ingested document
    public const METHOD_WEB_SEARCH  = 'web_search';   // retrieved live from an allow-listed domain
    public const METHOD_MODEL_PRIOR = 'model_prior';  // asserted from training — not a citation
    public const METHOD_FLEET       = 'fleet';        // measured in our own maintenance history
    public const METHOD_HUMAN       = 'human';        // a person stated it

    public const METHODS = [
        self::METHOD_CORPUS, self::METHOD_WEB_SEARCH, self::METHOD_MODEL_PRIOR,
        self::METHOD_FLEET, self::METHOD_HUMAN,
    ];

    /**
     * How much a method contributes to a grounding score, 0–100. Retrieved documentation is the
     * gold standard; our own measured history is close behind; an untethered model claim scores
     * low by design, so a profile built entirely from priors reports a low grounding score and the
     * admin knows to enrich it again once a corpus is available.
     */
    public const METHOD_GROUNDING = [
        self::METHOD_CORPUS      => 100,
        self::METHOD_WEB_SEARCH  => 80,
        self::METHOD_FLEET       => 85,
        self::METHOD_HUMAN       => 70,
        self::METHOD_MODEL_PRIOR => 20,
    ];

    protected $table = 'evidence_links';

    protected $fillable = [
        'evidenceable_type', 'evidenceable_id',
        'knowledge_source_id', 'knowledge_document_id', 'knowledge_chunk_id',
        'retrieval_method', 'document_title', 'section', 'url', 'snippet',
        'confidence', 'retrieved_at',
    ];

    protected $casts = [
        'confidence'   => 'integer',
        'retrieved_at' => 'datetime',
    ];

    public function evidenceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'knowledge_chunk_id');
    }

    /** Evidence that involved actually reading something, as opposed to a model prior. */
    public function scopeRetrieved(Builder $q): Builder
    {
        return $q->whereIn('retrieval_method', [self::METHOD_CORPUS, self::METHOD_WEB_SEARCH, self::METHOD_FLEET]);
    }

    /** This citation's contribution to a grounding score: method strength × stated confidence. */
    public function groundingScore(): int
    {
        $base = self::METHOD_GROUNDING[$this->retrieval_method] ?? 20;

        return (int) round($base * ($this->confidence / 100));
    }
}
