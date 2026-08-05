<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slice of a payment, pointed at one bill.
 *
 * This is what makes an invoice's `paid_amount` a SUM rather than a hand-kept running total: settlement
 * is derived from these rows, the same way cost is derived from line items and an invoice total is
 * derived from its parts. Nothing about the money is typed in two places.
 *
 * The target is either kind of bill, because the fleet pays garages and suppliers from the same account
 * and "what do we still owe" should be one question with one answer.
 */
class PaymentAllocation extends Model
{
    protected $table = 'payment_allocations';

    public const DOC_SUPPLIER_INVOICE = 'supplier_invoice';
    public const DOC_GARAGE_INVOICE   = 'garage_invoice';
    public const DOCUMENT_TYPES = [self::DOC_SUPPLIER_INVOICE, self::DOC_GARAGE_INVOICE];

    protected $fillable = ['supplier_payment_id', 'document_type', 'document_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /** The bill this slice settles. Resolved by type — the only place the mapping lives. */
    public function document(): ?Model
    {
        return match ($this->document_type) {
            self::DOC_SUPPLIER_INVOICE => PartInvoice::find($this->document_id),
            self::DOC_GARAGE_INVOICE   => MaintenanceInvoice::find($this->document_id),
            default                    => null,
        };
    }

    /** The document type string for a given model — the inverse of {@see document()}. */
    public static function typeFor(Model $document): string
    {
        return $document instanceof PartInvoice
            ? self::DOC_SUPPLIER_INVOICE
            : self::DOC_GARAGE_INVOICE;
    }
}
