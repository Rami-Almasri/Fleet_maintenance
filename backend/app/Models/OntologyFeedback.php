<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One human correction to the ontology — the raw material of continuous learning.
 *
 * See the create_ontology_feedback_table migration. Two classes of signal, deliberately in one
 * table because both answer "where is the engine wrong?":
 *
 *  - CURATION (accept/edit/reject/merge/create/delete) — a person changing the knowledge itself.
 *  - MATCH OUTCOMES (confirm_match/reject_match) — a person judging the MATCHER on a specific piece
 *    of text. These carry `query_text`, which makes them labelled retrieval pairs: the single most
 *    valuable thing in the system for a future semantic-search model, and impossible to reconstruct
 *    later if you don't capture it at the moment of judgement.
 *
 * `applied_at` makes this a queue, not just a log: unapplied rows are folded into the next
 * enrichment prompt and stamped, so the same correction isn't nagged at the model forever.
 */
class OntologyFeedback extends Model
{
    protected $table = 'ontology_feedback';

    public const ACTION_ACCEPT        = 'accept';
    public const ACTION_EDIT          = 'edit';
    public const ACTION_REJECT        = 'reject';
    public const ACTION_MERGE         = 'merge';
    public const ACTION_CREATE        = 'create';
    public const ACTION_DELETE        = 'delete';
    public const ACTION_CONFIRM_MATCH = 'confirm_match';
    public const ACTION_REJECT_MATCH  = 'reject_match';

    public const ACTIONS = [
        self::ACTION_ACCEPT, self::ACTION_EDIT, self::ACTION_REJECT, self::ACTION_MERGE,
        self::ACTION_CREATE, self::ACTION_DELETE, self::ACTION_CONFIRM_MATCH, self::ACTION_REJECT_MATCH,
    ];

    /** Signals that say the engine got something WRONG — the ones enrichment must learn from. */
    public const NEGATIVE_ACTIONS = [self::ACTION_REJECT, self::ACTION_DELETE, self::ACTION_REJECT_MATCH];

    /**
     * WHERE a verdict came from. Never averaged together, so never left as a loose string.
     *
     * `match_tester` is an admin probing the library from the keyword-admin page — deliberately hard
     * cases, no vehicle behind them. `test_findings` and `garage_findings` are a real person naming a
     * fault on a real car mid-workflow. The field-observed rows are the ones worth measuring the
     * matcher against; the tester rows are adversarial by construction and would understate it.
     */
    public const CONTEXT_MATCH_TESTER   = 'match_tester';
    public const CONTEXT_TEST_FINDINGS  = 'test_findings';
    public const CONTEXT_GARAGE_FINDINGS = 'garage_findings';

    /** Verdicts given on a real car, in the workflow — as opposed to typed into the admin tester. */
    public const FIELD_CONTEXTS = [self::CONTEXT_TEST_FINDINGS, self::CONTEXT_GARAGE_FINDINGS];

    protected $fillable = [
        'action', 'subject_type', 'subject_id', 'finding_keyword_id',
        'before', 'after', 'query_text', 'match_score', 'reason', 'context', 'user_id', 'applied_at',
        'maintenance_id', 'vehicle_id',
    ];

    protected $casts = [
        'before'      => 'array',
        'after'       => 'array',
        'match_score' => 'integer',
        'applied_at'  => 'datetime',
    ];

    public function findingKeyword(): BelongsTo
    {
        return $this->belongsTo(FindingKeyword::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeNegative(Builder $q): Builder
    {
        return $q->whereIn('action', self::NEGATIVE_ACTIONS);
    }

    /** Not yet folded into the enrichment guidance. */
    public function scopeUnapplied(Builder $q): Builder
    {
        return $q->whereNull('applied_at');
    }

    /** Labelled retrieval pairs — the training set for a future semantic matcher. */
    public function scopeMatchOutcomes(Builder $q): Builder
    {
        return $q->whereIn('action', [self::ACTION_CONFIRM_MATCH, self::ACTION_REJECT_MATCH])
            ->whereNotNull('query_text');
    }
}
