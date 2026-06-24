<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment / receipt recorded on the website — the credit (collection) side of a
 * contract. Anchored to a contract, optionally to a single invoice. Sum of a
 * contract's payments, netted against its invoices, is its real outstanding balance.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_ref', 'contract_id', 'invoice_id', 'customer_id',
        'amount', 'paid_on', 'method', 'reference', 'notes', 'recorded_by', 'origin',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
