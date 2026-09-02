<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A cached Odoo master-data record — pickable options for the mappings screen, and nothing more.
 *
 * Never read as an accounting source. Nothing in the validation or push path touches this table; that
 * is deliberate, so an empty or stale cache can never be the reason a correct mapping stops working.
 */
class OdooReferenceRecord extends Model
{
    protected $table = 'odoo_reference_records';

    protected $fillable = ['odoo_model', 'odoo_id', 'name', 'code', 'payload', 'active', 'seen_at'];

    protected $casts = [
        'odoo_id' => 'integer',
        'payload' => 'array',
        'active'  => 'boolean',
        'seen_at' => 'datetime',
    ];

    public function scopeForModel(Builder $q, string $odooModel): Builder
    {
        return $q->where('odoo_model', $odooModel);
    }

    /** Free-text search over the two things a person recognises a record by. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        return $q->where(function (Builder $w) use ($term) {
            $w->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%");
        });
    }

    /** Has this cache entry gone unconfirmed long enough that the screen should say so? */
    public function isStale(): bool
    {
        $hours = (int) config('odoo.master_data.stale_after_hours', 168);

        return $this->seen_at === null || $this->seen_at->lt(now()->subHours($hours));
    }
}
