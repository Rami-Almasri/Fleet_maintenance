<?php

namespace App\Models;

use App\Services\Expenses\ExpenseCategoryClassifier;
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
        'remarks', 'category', 'category_matched',
        'debit', 'credit', 'amount', 'source', 'imported_at',
    ];

    protected $casts = [
        'entry_date'  => 'date',
        'debit'       => 'decimal:2',
        'credit'      => 'decimal:2',
        'amount'      => 'decimal:2',
        'imported_at' => 'datetime',
    ];

    /**
     * A line without a category would silently count as 'other' — and therefore always as cost, even
     * when its remark plainly says it is a sub-rental recharge. Deriving it here means the invariant
     * "every row is classified" holds for any writer, not just the importer.
     */
    protected static function booted(): void
    {
        static::creating(function (self $line) {
            if ((string) ($line->category ?? '') === '') {
                $c = (new ExpenseCategoryClassifier())->classify($line->remarks);
                $line->category = $c['key'];
                $line->category_matched = $c['matched'];
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
