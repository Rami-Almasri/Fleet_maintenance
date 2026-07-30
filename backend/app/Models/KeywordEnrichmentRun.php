<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI enrichment attempt against one findings keyword — success or failure, always logged.
 *
 * See the create_keyword_enrichment_runs_table migration. This is both the audit trail behind
 * "where did this word come from?" and the idempotency key for `keywords:enrich --stale`.
 */
class KeywordEnrichmentRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    /**
     * Transient: the raw parsed model payload, attached only by a `--dry-run` enrichment so the CLI
     * can print what WOULD be written. Never persisted, never present on a row read from the DB.
     *
     * @var array<string,mixed>
     */
    public array $preview = [];

    protected $fillable = [
        'finding_keyword_id', 'status', 'model', 'terms_added', 'terms_updated',
        'profile_written', 'input_tokens', 'output_tokens', 'duration_ms', 'error', 'triggered_by',
    ];

    protected $casts = [
        'terms_added'     => 'integer',
        'terms_updated'   => 'integer',
        'profile_written' => 'boolean',
        'input_tokens'    => 'integer',
        'output_tokens'   => 'integer',
        'duration_ms'     => 'integer',
    ];

    public function findingKeyword(): BelongsTo
    {
        return $this->belongsTo(FindingKeyword::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function scopeSucceeded(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_SUCCESS);
    }
}
