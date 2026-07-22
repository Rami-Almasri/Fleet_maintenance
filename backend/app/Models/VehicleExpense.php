<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One imported expense line (see the vehicle_expenses migration). The SOLE store of vehicle expense —
 * read exclusively through {@see \App\Contracts\VehicleExpenseProvider}.
 */
class VehicleExpense extends Model
{
    protected $fillable = [
        'car_serial', 'vehicle_id', 'entry_date', 'account_type',
        'remarks', 'debit', 'credit', 'amount', 'source', 'imported_at',
    ];

    protected $casts = [
        'entry_date'  => 'date',
        'debit'       => 'decimal:2',
        'credit'      => 'decimal:2',
        'amount'      => 'decimal:2',
        'imported_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
