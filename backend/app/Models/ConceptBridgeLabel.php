<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one benchmark question.
 *
 * `source` is the field the whole evaluation rests on: HUMAN labels are the benchmark, AI labels are
 * a machine baseline used only to (a) give an early directional read and (b) measure — on the rows a
 * human also answered — how far machine labels can be trusted elsewhere. The two are never averaged
 * together; a model grading a model measures agreement, not correctness.
 */
class ConceptBridgeLabel extends Model
{
    public const SOURCE_HUMAN = 'human';
    public const SOURCE_AI    = 'ai';

    // What kind of statement the segment is. Testing whether the matcher reads ACTIONS as FAULTS.
    public const TYPE_FAULT       = 'fault';
    public const TYPE_ACTION      = 'action';
    public const TYPE_PART        = 'part';
    public const TYPE_PROCEDURE   = 'procedure';
    public const TYPE_OPERATIONAL = 'operational';
    public const TYPE_UNCLEAR     = 'unclear';
    public const TYPES = [
        self::TYPE_FAULT, self::TYPE_ACTION, self::TYPE_PART,
        self::TYPE_PROCEDURE, self::TYPE_OPERATIONAL, self::TYPE_UNCLEAR,
    ];

    /**
     * CORRECT_BROAD is the verdict that decides an architecture question, not just an accuracy one:
     * OEM documentation speaks about thermostats and water pumps, not "cooling system". If a large
     * share of matches are merely broad, fleet history cannot meet automotive knowledge at the same
     * resolution and the ontology needs component-level concepts before any ingestion.
     */
    public const VERDICT_SPECIFIC = 'correct_specific';
    public const VERDICT_BROAD    = 'correct_broad';
    public const VERDICT_WRONG    = 'incorrect';
    public const VERDICT_NONE     = 'none_predicted';
    public const VERDICTS = [
        self::VERDICT_SPECIFIC, self::VERDICT_BROAD, self::VERDICT_WRONG, self::VERDICT_NONE,
    ];

    public const QUALITIES = ['strong', 'medium', 'weak'];

    /** Verdicts that count as the matcher being right (broad still means right, just imprecise). */
    public const CORRECT = [self::VERDICT_SPECIFIC, self::VERDICT_BROAD];

    protected $fillable = [
        'concept_bridge_sample_id', 'source', 'user_id', 'labeller',
        'segment_type', 'verdict', 'evidence_quality',
        'valid_concepts', 'invalid_concepts', 'missing_concepts', 'notes',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(ConceptBridgeSample::class, 'concept_bridge_sample_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeHuman($q)
    {
        return $q->where('source', self::SOURCE_HUMAN);
    }

    public function scopeAi($q)
    {
        return $q->where('source', self::SOURCE_AI);
    }
}
