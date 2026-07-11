<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One manual mileage correction — a non-destructive overlay on a single contract reading
 * (out_milage = start, in_milage = end). `corrected_value` is what the Mileage Chain Audit uses;
 * NULL means the row only carries a note. Survives OfficeManager re-syncs (it never touches the
 * `contracts` row). See MileageChainService for how it's applied.
 */
class MileageOverride extends Model
{
    protected $fillable = [
        'contract_id', 'field', 'original_value', 'corrected_value', 'note', 'user_id', 'user_name',
    ];

    protected $casts = [
        'original_value'  => 'integer',
        'corrected_value' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
