<?php

namespace App\Models;

use App\Observers\InvoiceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice (charge) on a contract. Two origins coexist:
 *  - 'api'    — synced from the OfficeManager import (legacy, owns an integer invoice_no)
 *  - 'manual' — created on the website (no invoice_no; identified by a "M-…" invoice_ref)
 *
 * Manual invoices feed the customer's balance/wallet via InvoiceObserver.
 */
#[ObservedBy(InvoiceObserver::class)]
class Invoice extends Model
{
    use HasFactory;

    /**
     * `accident_case_id` is deliberately ABSENT from the fillable set. It is the idempotency key for
     * an accident charge (unique index), and a request body must never be able to claim that an
     * arbitrary invoice settles an accident. AccidentChargeService sets it explicitly after the
     * invoice is created. @see \App\Services\Accident\AccidentChargeService
     */
    protected $fillable = [
        'invoice_no', 'invoice_ref', 'invoice_date', 'customer_id', 'contract_id',
        'vehicle_id', 'vendor_id',
        'contract_serial', 'car_no', 'total_value', 'vat_value', 'total_after_vat',
        'discount', 'total_after_discount', 'period_from', 'period_to',
        'contract_out_date', 'contract_in_date', 'rent_days', 'net_rate', 'car_serial',
        'status_no', 'balance_value', 'paid_amount', 'payment_status',
        'synced_at', 'origin', 'notes',
    ];

    /** Derived settlement state (Track A): the actionable axis shown on the dashboard. */
    public const PAY_PAID     = 'paid';
    public const PAY_PARTIAL  = 'partial';
    public const PAY_NOT_PAID = 'not_paid';

    /**
     * Derive an invoice's payment status from OM money fields. Balance is authoritative:
     * OM closes an invoice by driving BalanceValue to 0 (settled on account or in cash),
     * so balance ≤ 0 = paid; any money in with a remaining balance = partial; else not_paid.
     * A penny of float tolerance keeps 2385.60000001-style rounding from reading as unpaid.
     */
    public static function derivePaymentStatus(?float $balance, ?float $paid, ?float $total): ?string
    {
        if ($balance === null && $paid === null && $total === null) {
            return null; // nothing to go on — leave unknown rather than guess "not_paid"
        }
        $balance = (float) $balance;
        $paid    = (float) $paid;

        if ($balance <= 0.01) {
            return self::PAY_PAID;
        }
        return $paid > 0.01 ? self::PAY_PARTIAL : self::PAY_NOT_PAID;
    }

    /** Outstanding invoices that need chasing — drives the dashboard "Pending" flag/count. */
    public function scopePending($q)
    {
        return $q->whereIn('payment_status', [self::PAY_PARTIAL, self::PAY_NOT_PAID]);
    }

    public function getIsPendingAttribute(): bool
    {
        return in_array($this->payment_status, [self::PAY_PARTIAL, self::PAY_NOT_PAID], true);
    }

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
        'status_no'            => 'integer',
        'balance_value'        => 'decimal:2',
        'paid_amount'          => 'decimal:2',
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

    /** Service Log lines — the parts/services done (money-free). Ordered as entered. */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sequence')->orderBy('id');
    }

    /** The garage/workshop the service-log invoice belongs to (nullable). */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** The car the work was done on (denormalised from the contract; nullable). */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * The accident this invoice bills the customer for. Null on every ordinary rental charge, and
     * at most one invoice may carry any given case — the unique index is what makes charging an
     * accident twice impossible rather than merely unlikely.
     */
    public function accidentCase(): BelongsTo
    {
        return $this->belongsTo(AccidentCase::class, 'accident_case_id');
    }

    /** Website-created invoice (editable here) vs. an OfficeManager-synced one (read-only). */
    public function isManual(): bool
    {
        return $this->origin === 'manual';
    }
}
