<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice (charge) on a contract. Two origins coexist:
 *  - 'api'    — synced from the OfficeManager import (legacy, owns an integer invoice_no)
 *  - 'manual' — created on the website (no invoice_no; identified by a "M-…" invoice_ref)
 */
class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_no', 'invoice_ref', 'invoice_date', 'customer_id', 'contract_id',
        'contract_serial', 'car_no', 'total_value', 'vat_value', 'total_after_vat',
        'discount', 'total_after_discount', 'period_from', 'period_to',
        'contract_out_date', 'contract_in_date', 'rent_days', 'net_rate', 'car_serial',
        'synced_at', 'origin', 'notes',
    ];

    protected $casts = [
        'invoice_date'         => 'date',
        'total_value'          => 'decimal:2',
        'vat_value'            => 'decimal:2',
        'total_after_vat'      => 'decimal:2',
        'discount'             => 'decimal:2',
        'total_after_discount' => 'decimal:2',
        'period_from'          => 'date',
        'period_to'            => 'date',
        'contract_out_date'    => 'date',
        'contract_in_date'     => 'date',
        'rent_days'            => 'integer',
        'net_rate'             => 'decimal:2',
        'synced_at'            => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Website-created invoice (editable here) vs. an OfficeManager-synced one (read-only). */
    public function isManual(): bool
    {
        return $this->origin === 'manual';
    }
}
