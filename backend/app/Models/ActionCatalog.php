<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the generic action vocabulary. See the create_action_catalog_table migration for why
 * this is separate from the ontology's repair nodes.
 */
class ActionCatalog extends Model
{
    protected $table = 'action_catalog';

    // The verbs. Kept small on purpose: a vocabulary with forty verbs is one where five people spell
    // the same action five ways, and cross-fleet comparison quietly stops working.
    public const VERB_REPLACE   = 'replace';
    public const VERB_REPAIR    = 'repair';
    public const VERB_MACHINE   = 'machine';
    public const VERB_ADJUST    = 'adjust';
    public const VERB_CLEAN     = 'clean';
    public const VERB_BLEED     = 'bleed';
    public const VERB_TOP_UP    = 'top_up';
    public const VERB_TIGHTEN   = 'tighten';
    public const VERB_LUBRICATE = 'lubricate';
    public const VERB_RESET     = 'reset';
    public const VERB_PROGRAM   = 'program';
    public const VERB_INSPECT   = 'inspect';
    public const VERB_TEST      = 'test';
    public const VERB_MEASURE   = 'measure';

    public const VERBS = [
        self::VERB_REPLACE, self::VERB_REPAIR, self::VERB_MACHINE, self::VERB_ADJUST,
        self::VERB_CLEAN, self::VERB_BLEED, self::VERB_TOP_UP, self::VERB_TIGHTEN,
        self::VERB_LUBRICATE, self::VERB_RESET, self::VERB_PROGRAM, self::VERB_INSPECT,
        self::VERB_TEST, self::VERB_MEASURE,
    ];

    protected $fillable = [
        'slug', 'verb', 'target', 'label', 'label_ar', 'category_key', 'compatible_systems',
        'requires_part', 'is_verification', 'default_labor_hours', 'required_skill',
        'ontology_repair_node_id', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'compatible_systems'  => 'array',
        'requires_part'       => 'boolean',
        'is_verification'     => 'boolean',
        'is_active'           => 'boolean',
        'default_labor_hours' => 'decimal:2',
        'sort_order'          => 'integer',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable().'.is_active', true);
    }

    public function scopeForSystem(Builder $q, string $system): Builder
    {
        // Matches either the primary category or an entry in the compatible list, so an action
        // shared between brakes and clutch is found from both.
        return $q->where(fn (Builder $w) => $w
            ->where('category_key', $system)
            ->orWhereJsonContains('compatible_systems', $system));
    }

    /** Actions that prove a repair rather than perform one. */
    public function scopeVerification(Builder $q): Builder
    {
        return $q->where('is_verification', true);
    }
}
