<?php

namespace App\Models\Concerns;

use App\Models\OdooMapping;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Gives a model its side of the "is this the same thing in Odoo?" answer.
 *
 * Used by the three things that have to be recognised across both systems — {@see \App\Models\Vehicle}
 * (→ analytic account), {@see \App\Models\ComponentCatalog} (→ product) and {@see \App\Models\Vendor}
 * (→ partner). The trait deliberately exposes only READS. Writing a mapping is a decision with a person
 * and a timestamp attached, and it goes through {@see \App\Services\Odoo\OdooMappingService} so that
 * provenance is never optional.
 */
trait HasOdooMapping
{
    public function odooMappings(): MorphMany
    {
        return $this->morphMany(OdooMapping::class, 'mappable');
    }

    /** The mapping for one Odoo model, whatever its status — including suggested and stale ones. */
    public function odooMappingFor(string $odooModel): ?OdooMapping
    {
        return $this->odooMappings->firstWhere('odoo_model', $odooModel)
            ?? $this->odooMappings()->where('odoo_model', $odooModel)->first();
    }

    /**
     * The Odoo id to POST WITH, or null.
     *
     * Null covers three genuinely different situations — never mapped, only suggested, or gone stale —
     * and they are collapsed here on purpose: at the moment of posting they mean the same thing, which
     * is "we do not have a confirmed answer, so nothing may be sent". Which of the three it was is a
     * question for the mappings screen, which reads {@see odooMappingFor()} and can tell them apart.
     */
    public function odooIdFor(string $odooModel): ?int
    {
        $mapping = $this->odooMappingFor($odooModel);

        return $mapping && $mapping->isUsable() ? (int) $mapping->odoo_id : null;
    }

    /** A single-mapping accessor for the common case of a model with one Odoo counterpart. */
    public function odooMapping(): MorphOne
    {
        return $this->morphOne(OdooMapping::class, 'mappable');
    }
}
