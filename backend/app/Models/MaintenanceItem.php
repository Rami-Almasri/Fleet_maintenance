<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line on a maintenance visit (invoice-style): one service + its cost.
 * Belongs to a Contract (contract_type = 'U').
 */
class MaintenanceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'service_name',
        'cost',
        'notes',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
