<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleRegistration extends Model
{
    /** @use HasFactory<\Database\Factories\VehicleRegistrationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'vehicle_id',
        'chasis_no',
        'expiry_date',
        'status',
        'fines_count',
        'fines_amount',
        'mortgaged_by',
        'is_mortgaged',
        'insurance_company_id',
        'insurance_company_no',
        'insurance_no',
        'insurance_issue_date',
        'insurance_expiry',
        'insurance_type',
        'insurance_bear_amount',
        'external_id',
        'synced_at',
        'origin',
    ];

    protected $casts = [
        'expiry_date'           => 'date',
        'insurance_issue_date'  => 'date',
        'insurance_expiry'      => 'date',
        'insurance_bear_amount' => 'decimal:2',
        'is_mortgaged'          => 'boolean',
        'synced_at'             => 'datetime',
    ];

    /** Days until the insurance expires (negative = already expired, null = unknown). */
    public function getInsuranceDaysLeftAttribute(): ?int
    {
        return $this->insurance_expiry
            ? (int) now()->startOfDay()->diffInDays($this->insurance_expiry, false)
            : null;
    }

    /** Days until the registration / mulkiya expires. */
    public function getRegistrationDaysLeftAttribute(): ?int
    {
        return $this->expiry_date
            ? (int) now()->startOfDay()->diffInDays($this->expiry_date, false)
            : null;
    }

    /**
     * Days the registration is still valid for — calculated from expiry_date
     * (replaces the old stored `valid_days` column). Alias of registration_days_left.
     */
    public function getValidDaysAttribute(): ?int
    {
        return $this->registration_days_left;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function insuranceCompany(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'insurance_company_id');
    }
}
