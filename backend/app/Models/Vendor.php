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

    protected $fillable = [
        'name',
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
