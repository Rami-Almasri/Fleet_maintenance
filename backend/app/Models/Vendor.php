<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    /** @use HasFactory<\Database\Factories\VendorFactory> */
    use HasFactory, SoftDeletes;
    // The supplier's side of the Odoo partner mapping (§19). `external_id` on this table is the
    // OfficeManager reference and is NOT an Odoo id — the two are different systems, so the Odoo
    // answer lives in odoo_mappings where its provenance can be recorded.
    use \App\Models\Concerns\HasOdooMapping;

    protected $fillable = [
        'name',
        'payment_terms_days',
        'payment_terms_note',
        'type',
        'phone',
        'email',
        'rating',
        'notes',
        'active',
        'external_id',
        'synced_at',
        'origin',
    ];

    protected $casts = [
        'active'    => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * Vehicle registrations insured by this vendor (when type = insurance).
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(VehicleRegistration::class, 'insurance_company_id');
    }
}
