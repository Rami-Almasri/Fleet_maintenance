<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One kind of DAMAGE the fleet can record — the authoritative "what was done to the car" vocabulary.
 *
 * Every row here is implicitly `kind = damage` (the same invariant ServiceCatalog and FaultCatalog
 * carry). A maintenance_task pointing at one of these rows is damage; nothing else needs to guess.
 *
 * Damage is a separate KIND rather than a flavour of fault because it behaves differently in three
 * ways that matter commercially and analytically:
 *   • it says nothing about vehicle reliability, so it must never reach recurrence, health or forecasting;
 *   • it has a liable party, so it is chargeable and sometimes insurable;
 *   • it is still fully reportable and costed — excluded from fault stats, never excluded from the books.
 *
 * @see config/damage_catalog.php  the content, and the rule for what belongs in it
 * @see docs/Service-Fault-Damage-Domain.md
 */
class DamageCatalog extends Model
{
    use HasFactory;

    protected $table = 'damage_catalog';

    /** How the damage happened. `unknown` is honest and common — most rows are logged after the fact. */
    public const TYPE_IMPACT    = 'impact';
    public const TYPE_SCRATCH   = 'scratch';
    public const TYPE_CRACK     = 'crack';
    public const TYPE_TEAR      = 'tear';
    public const TYPE_VANDALISM = 'vandalism';
    public const TYPE_UNKNOWN   = 'unknown';

    public const TYPES = [
        self::TYPE_IMPACT, self::TYPE_SCRATCH, self::TYPE_CRACK,
        self::TYPE_TEAR, self::TYPE_VANDALISM, self::TYPE_UNKNOWN,
    ];

    protected $fillable = [
        'slug', 'name', 'name_ar', 'category_key', 'area_key', 'damage_type',
        'is_chargeable', 'is_insurable', 'affects_roadworthiness', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_chargeable'          => 'boolean',
        'is_insurable'           => 'boolean',
        'affects_roadworthiness' => 'boolean',
        'is_active'              => 'boolean',
        'sort_order'             => 'integer',
    ];

    /** The events recorded against this damage type. */
    public function tasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class, 'damage_catalog_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** Damage that grounds a car — read by the readiness gate instead of re-deriving it from severity. */
    public function scopeGrounding($q)
    {
        return $q->where('affects_roadworthiness', true);
    }
}
