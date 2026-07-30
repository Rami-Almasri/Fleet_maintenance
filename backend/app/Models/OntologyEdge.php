<?php

namespace App\Models;

use App\Support\VehicleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One directed, weighted, scoped relationship in the knowledge graph.
 *
 * See the create_ontology_graph_tables migration. The distinction that matters most when reading
 * this class: an edge is either ASSERTED (`source` = ai / human / seed — a claim about how cars
 * work, weighted by how strongly the documentation supports it) or OBSERVED (`source` = fleet —
 * a counted fact about our own cars, weighted by `observed_rate` over `observed_count` cases).
 *
 * Both live in the same table because a technician asking "what usually causes this?" wants one
 * ranked answer, not two lists. They keep separate provenance because a 92%-of-214-cases fleet edge
 * and a plausible-sounding AI edge should never be presented as the same kind of thing — see
 * [[MatchExplanationService]], which spells out which is which.
 */
class OntologyEdge extends Model
{
    // --- Fault-centred relations: the spine of a diagnostic answer -------------------------------
    public const REL_PRESENTS_AS     = 'presents_as';       // fault  → symptom
    public const REL_AFFECTS         = 'affects_component'; // fault  → component
    public const REL_CAUSED_BY       = 'caused_by';         // fault  → cause
    public const REL_FIXED_BY        = 'fixed_by';          // fault  → repair
    public const REL_INSPECTED_BY    = 'inspected_by';      // fault  → procedure
    public const REL_RELATED_TO      = 'related_to';        // fault  → fault

    // --- Execution relations: what carrying out the work needs -----------------------------------
    public const REL_REQUIRES_PART   = 'requires_part';     // repair → part
    public const REL_REQUIRES_TOOL   = 'requires_tool';     // repair → tool
    public const REL_REQUIRES_SKILL  = 'requires_skill';    // repair → skill
    public const REL_PRECEDES        = 'precedes';          // procedure → procedure (inspection order)
    public const REL_PART_OF         = 'part_of';           // component → system

    public const RELATIONS = [
        self::REL_PRESENTS_AS, self::REL_AFFECTS, self::REL_CAUSED_BY, self::REL_FIXED_BY,
        self::REL_INSPECTED_BY, self::REL_RELATED_TO, self::REL_REQUIRES_PART,
        self::REL_REQUIRES_TOOL, self::REL_REQUIRES_SKILL, self::REL_PRECEDES, self::REL_PART_OF,
    ];

    /** Human phrasing per relation, used by explanations and the graph view. */
    public const RELATION_META = [
        self::REL_PRESENTS_AS    => ['label' => 'presents as',      'inverse' => 'is a symptom of'],
        self::REL_AFFECTS        => ['label' => 'affects',          'inverse' => 'is affected by'],
        self::REL_CAUSED_BY      => ['label' => 'is caused by',     'inverse' => 'causes'],
        self::REL_FIXED_BY       => ['label' => 'is fixed by',      'inverse' => 'fixes'],
        self::REL_INSPECTED_BY   => ['label' => 'is checked by',    'inverse' => 'checks for'],
        self::REL_RELATED_TO     => ['label' => 'is related to',    'inverse' => 'is related to'],
        self::REL_REQUIRES_PART  => ['label' => 'requires part',    'inverse' => 'is used in'],
        self::REL_REQUIRES_TOOL  => ['label' => 'requires tool',    'inverse' => 'is used for'],
        self::REL_REQUIRES_SKILL => ['label' => 'requires skill',   'inverse' => 'is needed for'],
        self::REL_PRECEDES       => ['label' => 'is done before',   'inverse' => 'is done after'],
        self::REL_PART_OF        => ['label' => 'is part of',       'inverse' => 'contains'],
    ];

    public const SOURCE_AI    = 'ai';
    public const SOURCE_HUMAN = 'human';
    public const SOURCE_FLEET = 'fleet';
    public const SOURCE_SEED  = 'seed';

    protected $fillable = [
        'from_node_id', 'to_node_id', 'relation', 'weight', 'confidence', 'source',
        'observed_count', 'observed_rate', 'last_observed_at', 'scope_key', 'note', 'is_active',
    ];

    protected $casts = [
        'weight'           => 'integer',
        'confidence'       => 'integer',
        'observed_count'   => 'integer',
        'observed_rate'    => 'integer',
        'last_observed_at' => 'datetime',
        'is_active'        => 'boolean',
    ];

    public function from(): BelongsTo
    {
        return $this->belongsTo(OntologyNode::class, 'from_node_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(OntologyNode::class, 'to_node_id');
    }

    /** The documentation this relationship was drawn from. */
    public function evidence(): MorphMany
    {
        return $this->morphMany(EvidenceLink::class, 'evidenceable');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable().'.is_active', true);
    }

    public function scopeRelation(Builder $q, string|array $relation): Builder
    {
        return $q->whereIn('relation', (array) $relation);
    }

    /** @param array<int,string> $chain from VehicleScope::chain() */
    public function scopeInScope(Builder $q, array $chain = [VehicleScope::UNIVERSAL]): Builder
    {
        return $q->whereIn('scope_key', $chain);
    }

    /**
     * The ranking score for this edge, blending how strong the relationship is with how much we
     * trust its provenance and how specific it is to the vehicle asked about.
     *
     * Fleet edges get a deliberate premium: an observation of our own cars beats a plausible claim,
     * and one made on 200 cases beats one made on 3. Vehicle-specific knowledge outranks universal
     * knowledge, which is the whole point of scoping.
     */
    public function rank(): float
    {
        $provenance = match ($this->source) {
            self::SOURCE_FLEET => 1.25,     // we measured it here
            self::SOURCE_HUMAN => 1.15,     // our workshop asserted it
            self::SOURCE_SEED  => 1.00,
            default            => 0.95,     // AI claim
        };

        // A fleet edge is only as good as its sample. 3 cases is an anecdote; 100 is a pattern.
        if ($this->source === self::SOURCE_FLEET) {
            $provenance *= min(1.0, 0.4 + ($this->observed_count / 50) * 0.6);
        }

        $specificity = 1 + (VehicleScope::specificity($this->scope_key) * 0.05);

        return $this->weight * ($this->confidence / 100) * $provenance * $specificity;
    }

    public static function relationMeta(?string $relation): array
    {
        return self::RELATION_META[$relation] ?? ['label' => str_replace('_', ' ', (string) $relation), 'inverse' => 'relates to'];
    }
}
