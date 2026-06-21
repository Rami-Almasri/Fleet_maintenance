<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory, SoftDeletes;

    /**
     * OfficeManager "customers" that are really operational placeholders, not people:
     * a car parked on one of these is in the workshop, not rented. Any retail-customer
     * logic (exchange detection, customer history, etc.) must exclude them.
     *   5848 = "MAINTENANCE"   ·   5300 = "CARS IN GARAGE FOR MAINTTENANCE"
     */
    public const PSEUDO_CUSTOMER_NOS = ['5848', '5300'];

    /** Real retail customers only — excludes the maintenance/garage placeholder accounts. */
    public function scopeReal(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereNotIn('customer_no', self::PSEUDO_CUSTOMER_NOS);
    }

    /** Is this one of the operational placeholder accounts (workshop, not a person)? */
    public function isPseudo(): bool
    {
        return in_array((string) $this->customer_no, self::PSEUDO_CUSTOMER_NOS, true);
    }

    protected $fillable = [
        'customer_no',
        'name_en',
        'name_ar',
        'nationality',
        'date_of_birth',
        'mobile1',
        'mobile2',
        'whatsapp',
        'email',
        'sex',
        'city',
        'address',
        'po_box',
        'passport_no',
        'passport_expiry',
        'license_no',
        'license_expiry',
        'id_no',
        'id_expiry',
        'residency_no',
        'residency_expiry',
        'traffic_file_no',
        'vat_number',
        'makani',
        'lat',
        'lon',
        'debit',
        'credit',
        'balance',
        'deposit',
        'external_id',
        'synced_at',
        'origin',
    ];

    protected $casts = [
        'date_of_birth'    => 'date',
        'passport_expiry'  => 'date',
        'license_expiry'   => 'date',
        'id_expiry'        => 'date',
        'residency_expiry' => 'date',
        'synced_at'        => 'datetime',
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
