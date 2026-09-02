<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "Our row N is Odoo's row M." The explicit answer §16/§18/§19 require, for vehicles, parts and suppliers.
 *
 * The only status that means anything to the validator is MAPPED. A `suggested` row is a proposal a
 * matcher made and nobody confirmed, and it is treated as ABSENT — that is the whole difference between
 * this table and name matching. Fuzzy comparison may fill the picker; it may not decide what gets posted
 * to a ledger.
 */
class OdooMapping extends Model
{
    protected $table = 'odoo_mappings';

    /** Odoo models we map into. */
    public const MODEL_ANALYTIC = 'account.analytic.account';
    public const MODEL_PRODUCT  = 'product.product';
    public const MODEL_PARTNER  = 'res.partner';

    /** Decided — the validator accepts it. */
    public const STATUS_MAPPED = 'mapped';
    /** Proposed by matching, not confirmed. Treated as absent. */
    public const STATUS_SUGGESTED = 'suggested';
    /** Recorded as genuinely having no counterpart, so it stops appearing in the mapping backlog. */
    public const STATUS_UNMAPPED = 'unmapped';
    /** The last master-data pull could not find odoo_id any more. Treated as absent, and visible. */
    public const STATUS_STALE = 'stale';

    public const STATUSES = [self::STATUS_MAPPED, self::STATUS_SUGGESTED, self::STATUS_UNMAPPED, self::STATUS_STALE];

    /** How the mapping was arrived at. Only MANUAL and EXTERNAL_REF may produce STATUS_MAPPED. */
    public const MATCHED_MANUAL       = 'manual';
    public const MATCHED_EXTERNAL_REF = 'external_ref';
    public const MATCHED_VIN          = 'vin';
    public const MATCHED_PLATE        = 'plate';
    public const MATCHED_SUGGESTED    = 'suggested';

    protected $fillable = [
        'mappable_type', 'mappable_id',
        'odoo_model', 'odoo_id', 'odoo_ref', 'odoo_name',
        'previous_odoo_id', 'previous_odoo_name', 'changed_at', 'change_count',
        'status', 'matched_by', 'mapped_by', 'mapped_at', 'last_synced_at', 'notes',
    ];

    protected $casts = [
        'odoo_id'          => 'integer',
        'previous_odoo_id' => 'integer',
        'change_count'     => 'integer',
        'changed_at'       => 'datetime',
        'mapped_at'        => 'datetime',
        'last_synced_at'   => 'datetime',
    ];

    /**
     * The one word this row should be shown as. Six states, and they are genuinely different questions:
     *
     *   unmapped   nobody has looked at this yet — it is work
     *   none       somebody looked and the answer is "no counterpart" — it is finished, not work
     *   suggested  a matcher proposed something; a human has not confirmed it. NOT usable.
     *   stale      the last master-data pull could not find the target any more. NOT usable.
     *   changed    confirmed, and it has been re-pointed at least once — the row to look at twice
     *   mapped     confirmed and stable
     *
     * `changed` deliberately outranks `mapped` in the display: both are usable, but only one of them
     * means "documents in Odoo may have been coded against a different target than this row now names".
     */
    public function displayState(): string
    {
        if ($this->status === self::STATUS_UNMAPPED) {
            return 'none';
        }
        if ($this->status === self::STATUS_SUGGESTED) {
            return 'suggested';
        }
        if ($this->status === self::STATUS_STALE) {
            return 'stale';
        }
        if ($this->status === self::STATUS_MAPPED && $this->odoo_id === null) {
            return 'unmapped';
        }

        return (int) $this->change_count > 0 ? 'changed' : 'mapped';
    }

    public function mappable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Usable means: decided, and pointing at something. A mapping with no odoo_id is a placeholder row
     * (usually STATUS_UNMAPPED, recorded so the backlog stops asking about it) and can never be posted.
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_MAPPED && $this->odoo_id !== null;
    }

    /** Only mappings that may actually be posted with. */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_MAPPED)->whereNotNull('odoo_id');
    }

    public function scopeForModel(Builder $q, string $odooModel): Builder
    {
        return $q->where('odoo_model', $odooModel);
    }
}
