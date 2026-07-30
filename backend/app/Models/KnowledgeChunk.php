<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retrievable passage of a knowledge document.
 *
 * TWO RETRIEVAL PATHS, ONE TABLE. `normalized` is always populated and drives lexical retrieval,
 * which works with zero external dependencies. `embedding` is nullable and only populated once an
 * embeddings provider is configured — Anthropic does not sell embeddings, so that is a separate
 * vendor decision (Voyage, OpenAI, or a local model) and the engine is built to work without it.
 *
 * When an embedding IS present, [[KnowledgeRetrievalService]] uses cosine similarity and falls back
 * to lexical scoring per-chunk, so a partially-embedded corpus degrades gracefully instead of
 * returning nothing for the un-embedded half.
 */
class KnowledgeChunk extends Model
{
    protected $fillable = [
        'knowledge_document_id', 'ordinal', 'section', 'heading', 'text',
        'token_count', 'normalized', 'embedding', 'embedding_model',
    ];

    protected $casts = [
        'ordinal'     => 'integer',
        'token_count' => 'integer',
        'embedding'   => 'array',
    ];

    /** Keep the lexical key and a rough token estimate in step with the text, always. */
    protected static function booted(): void
    {
        static::saving(function (self $chunk) {
            $chunk->normalized = TextNormalizer::key($chunk->text);
            // ~4 characters per token is close enough for chunk sizing and cost estimates; the
            // exact count only matters at the API boundary, where the API counts it for us.
            $chunk->token_count = (int) ceil(mb_strlen((string) $chunk->text) / 4);
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    /** Cosine similarity against a query vector. Returns null when this chunk has no embedding. */
    public function cosineTo(array $queryVector): ?float
    {
        $v = $this->embedding;
        if (! is_array($v) || $v === [] || count($v) !== count($queryVector)) {
            return null;
        }

        $dot = $normA = $normB = 0.0;
        foreach ($v as $i => $a) {
            $b = $queryVector[$i];
            $dot += $a * $b;
            $normA += $a * $a;
            $normB += $b * $b;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return null;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
