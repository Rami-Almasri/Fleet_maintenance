<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice (charge) from the OfficeManager API, linked to a contract.
 */
class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_no', 'invoice_date', 'customer_id', 'contract_id',
        'contract_serial', 'car_no', 'total_value', 'vat_value', 'total_after_vat',
        'synced_at', 'origin',
    ];

    protected $casts = [
        'invoice_date'    => 'date',
        'total_value'     => 'decimal:2',
        'vat_value'       => 'decimal:2',
        'total_after_vat' => 'decimal:2',
        'synced_at'       => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
