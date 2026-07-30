<?php

namespace App\Models;

use App\Support\VehicleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document in the knowledge corpus — a service manual, a technical bulletin, an SAE paper, or
 * a PDF the fleet uploaded itself.
 *
 * Vehicle scope matters more here than anywhere else in the engine: a Camry brake procedure cited
 * as evidence for a BMW is worse than no evidence at all, because it looks authoritative. A
 * document with no make/model is universal (a Bosch braking-systems chapter); one with them is
 * only ever retrieved for matching vehicles. See [[VehicleScope]].
 */
class KnowledgeDocument extends Model
{
    public const TYPE_SERVICE_MANUAL = 'service_manual';
    public const TYPE_TSB            = 'tsb';            // technical service bulletin / recall
    public const TYPE_REPAIR_GUIDE   = 'repair_guide';
    public const TYPE_SPEC_SHEET     = 'spec_sheet';
    public const TYPE_PAPER          = 'paper';          // SAE and similar
    public const TYPE_REGULATION     = 'regulation';     // government transportation documentation
    public const TYPE_INTERNAL       = 'internal';       // the fleet's own written procedures

    public const TYPES = [
        self::TYPE_SERVICE_MANUAL, self::TYPE_TSB, self::TYPE_REPAIR_GUIDE, self::TYPE_SPEC_SHEET,
        self::TYPE_PAPER, self::TYPE_REGULATION, self::TYPE_INTERNAL,
    ];

    protected $fillable = [
        'knowledge_source_id', 'title', 'doc_type', 'url', 'publisher', 'published_at', 'language',
        'make', 'model', 'year_from', 'year_to', 'engine', 'platform', 'scope_key',
        'checksum', 'chunk_count', 'ingested_at',
    ];

    protected $casts = [
        'published_at' => 'date',
        'ingested_at'  => 'datetime',
        'chunk_count'  => 'integer',
        'year_from'    => 'integer',
        'year_to'      => 'integer',
    ];

    /** Scope key is always derived — one place, same rule as every other scoped table. */
    protected static function booted(): void
    {
        static::saving(function (self $doc) {
            $doc->scope_key = VehicleScope::key($doc->make, $doc->model, $doc->platform, $doc->engine);
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    /** @param array<int,string> $chain from VehicleScope::chain() */
    public function scopeInScope(Builder $q, array $chain = [VehicleScope::UNIVERSAL]): Builder
    {
        return $q->whereIn('scope_key', $chain);
    }

    /** Citation line for an evidence link: "Bosch Automotive Handbook — Brake Systems (2023)". */
    public function citation(): string
    {
        return collect([
            $this->publisher ?: $this->source?->name,
            $this->title,
            $this->published_at?->format('Y'),
        ])->filter()->implode(' — ');
    }
}
