<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One manager override of the "Rental-First" policy: a maintenance (type-U) contract that
 * was knowingly opened on a car that still had a live rental. Records who, why and when so
 * the owner has a transparent trail (see /override-audit).
 */
class PolicyOverrideAudit extends Model
{
    public const UPDATED_AT = null; // append-only log; never edited

    /**
     * Controlled override reasons. Keys are stored on the row; values are shown to staff
     * and snapshot onto the audit row as `reason_label`. 'other' requires free-text notes.
     */
    public const REASON_CODES = [
        'insurance_accident' => 'Major accident requiring an external insurance claim',
        'long_term_offroad'  => 'Long-term off-road requirement',
        'warranty_recall'    => 'Manufacturer warranty / recall work',
        'safety_critical'    => 'Safety-critical fault — car must leave service immediately',
        'other'              => 'Other (explain in notes)',
    ];

    protected $fillable = [
        'user_id', 'user_name',
        'vehicle_id', 'plate',
        'action', 'reason_code', 'reason_label', 'notes',
        'rental_contract_id', 'rental_contract_no',
        'result_contract_id', 'result_contract_no',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
