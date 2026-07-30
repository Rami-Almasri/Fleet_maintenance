<?php

namespace App\Models;

use App\Support\TextNormalizer;
use App\Support\VehicleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One entity in the automotive knowledge graph — a fault, a component, a cause, a repair, a part.
 *
 * See the create_ontology_graph_tables migration for the design. The rule that keeps the graph
 * queryable rather than decorative: `key` is derived from the label through the SAME normaliser the
 * keyword matcher uses, so "Brake Pads", "brake pads" and "BRAKE PADS " are one node, and a
 * technician's free text can land on a component node directly.
 */
class OntologyNode extends Model
{
    /** The closed node vocabulary. A graph whose types grow freely stops being traversable. */
    public const TYPE_FAULT     = 'fault';      // mirrors a FindingKeyword — the graph's entry point
    public const TYPE_SYMPTOM   = 'symptom';    // what the driver reports
    public const TYPE_COMPONENT = 'component';  // brake pads, rotor, caliper
    public const TYPE_CAUSE     = 'cause';      // worn friction material
    public const TYPE_REPAIR    = 'repair';     // replace pads
    public const TYPE_PART      = 'part';       // front pad set (a purchasable thing)
    public const TYPE_PROCEDURE = 'procedure';  // measure pad thickness
    public const TYPE_SYSTEM    = 'system';     // brake system
    public const TYPE_TOOL      = 'tool';       // torque wrench
    public const TYPE_SKILL     = 'skill';      // brake hydraulics certification

    public const TYPES = [
        self::TYPE_FAULT, self::TYPE_SYMPTOM, self::TYPE_COMPONENT, self::TYPE_CAUSE,
        self::TYPE_REPAIR, self::TYPE_PART, self::TYPE_PROCEDURE, self::TYPE_SYSTEM,
        self::TYPE_TOOL, self::TYPE_SKILL,
    ];

    /** Presentation per type — mirrored by the frontend graph view. */
    public const TYPE_META = [
        self::TYPE_FAULT     => ['label' => 'Fault',      'tone' => 'red'],
        self::TYPE_SYMPTOM   => ['label' => 'Symptom',    'tone' => 'amber'],
        self::TYPE_COMPONENT => ['label' => 'Component',  'tone' => 'blue'],
        self::TYPE_CAUSE     => ['label' => 'Cause',      'tone' => 'violet'],
        self::TYPE_REPAIR    => ['label' => 'Repair',     'tone' => 'green'],
        self::TYPE_PART      => ['label' => 'Part',       'tone' => 'cyan'],
        self::TYPE_PROCEDURE => ['label' => 'Procedure',  'tone' => 'indigo'],
        self::TYPE_SYSTEM    => ['label' => 'System',     'tone' => 'slate'],
        self::TYPE_TOOL      => ['label' => 'Tool',       'tone' => 'gray'],
        self::TYPE_SKILL     => ['label' => 'Skill',      'tone' => 'orange'],
    ];

    protected $fillable = [
        'type', 'key', 'label', 'label_ar', 'finding_keyword_id',
        'make', 'model', 'generation', 'engine', 'scope_key',
        'source', 'confidence', 'description', 'is_active',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'is_active'  => 'boolean',
    ];

    /**
     * Derive `key` and `scope_key` on save. Same reasoning as [[KeywordTerm]]: one place turns a
     * label into a comparison key, so nothing that inserts a node can drift from what queries it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $node) {
            $node->key = TextNormalizer::key($node->label);
            $node->scope_key = VehicleScope::key($node->make, $node->model, $node->generation, $node->engine);
        });
    }

    public function findingKeyword(): BelongsTo
    {
        return $this->belongsTo(FindingKeyword::class);
    }

    /** Edges leaving this node — the forward traversal direction. */
    public function outgoing(): HasMany
    {
        return $this->hasMany(OntologyEdge::class, 'from_node_id');
    }

    /** Edges arriving at this node — "what else points here?", e.g. every fault on one component. */
    public function incoming(): HasMany
    {
        return $this->hasMany(OntologyEdge::class, 'to_node_id');
    }

    /** Documentation backing this node's existence. */
    public function evidence(): MorphMany
    {
        return $this->morphMany(EvidenceLink::class, 'evidenceable');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable().'.is_active', true);
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type);
    }

    /**
     * Restrict to knowledge that applies to a given vehicle: universal rows plus anything scoped to
     * that make/model/generation. Passing no scope chain means universal only.
     *
     * @param  array<int,string>  $chain  from VehicleScope::chain()
     */
    public function scopeInScope(Builder $q, array $chain = [VehicleScope::UNIVERSAL]): Builder
    {
        return $q->whereIn('scope_key', $chain);
    }

    public static function typeMeta(?string $type): array
    {
        return self::TYPE_META[$type] ?? ['label' => ucfirst((string) $type), 'tone' => 'gray'];
    }
}
