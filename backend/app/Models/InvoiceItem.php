<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a service-log invoice — a part replaced or a service performed (e.g. "Oil Filter",
 * "Brake Pads"). Deliberately money-FREE: the financial ledger is decoupled; these rows exist to
 * build a searchable technical history per vehicle and to compare what was done across garages.
 * The car / date / garage are read from the parent Invoice.
 */
class InvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'description', 'category_key', 'sequence'];

    protected $casts = ['sequence' => 'integer'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
