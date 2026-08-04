<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer-reported odometer reading captured during an open rental — Evidence class **F**
 * (a fact, at the quality level of "someone read it off the dash and said it on the phone").
 *
 * Written by ops (Leen / Marwa) in response to an oil-projection chase. Consumed by exactly
 * one thing: OilChangeProjectionService, which uses the newest reading as its anchor.
 *
 * 🛑 This never writes `vehicles.odometer`. See the migration for why.
 */
class ContractMileageReading extends Model
{
    use HasFactory;

    /** Reported by the customer over the phone — the normal case. */
    public const SOURCE_CUSTOMER = 'customer_reported';

    /** Read off the car by our own staff (a driver, a branch visit) — higher trust, same use. */
    public const SOURCE_STAFF = 'staff_observed';

    protected $fillable = [
        'contract_id',
        'vehicle_id',
        'odometer',
        'reported_on',
        'recorded_by',
        'reported_by',
        'source',
        'note',
    ];

    protected $casts = [
        'odometer'    => 'integer',
        'reported_on' => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
