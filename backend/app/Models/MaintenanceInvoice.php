<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One garage bill on a maintenance ticket.
 *
 * The ticket (`maintenances`) is the CONTAINER; a MaintenanceInvoice is ONE invoice inside it — the bill a
 * single garage handed us for the faults IT fixed. A ticket worked in two garages carries two invoices
 * ([[one Ticket → many Invoices]]). Each invoice:
 *
 *   - covers a set of FAULTS  (maintenance_tasks.maintenance_invoice_id → this invoice; fault→one invoice)
 *   - itemises its own COST   (maintenance_line_items.maintenance_invoice_id → this invoice; parts + labor)
 *   - validates against its own printed RECEIPT total (+ a mandatory variance note on any mismatch)
 *   - reconciles on its OWN clock (reconciliation_status: pending → reconciled)
 *
 * `amount` (= parts_total + labor_total) is this invoice's grand total, kept honest from its lines. The
 * ticket's `cost` stays the sum of ALL its lines (unchanged), i.e. the sum of its invoices' amounts.
 */
class MaintenanceInvoice extends Model implements \App\Contracts\FinancialEventSource
{
    use \App\Models\Concerns\IsFinancialDocument;

    protected $table = 'maintenance_invoices';

    protected $fillable = [
        'maintenance_id',
        'vendor_id',
        'is_internal',
        'invoice_no',
        'parts_total',
        'labor_total',
        'vat_total',
        'discount_total',
        'amount',
        'receipt_total',
        'variance_explanation',
        'reconciliation_status',
        'reconciliation_flagged_at',
        'reconciled_by',
        'reconciled_at',
        'receipt_photo_disk',
        'receipt_photo_key',
        'notes',
        'recorded_by',
        'recorded_at',
        // Lifecycle — see IsFinancialDocument + FinancialDocumentStatus. Distinct from
        // `reconciliation_status`, which answers a different question: reconciliation is "did finance match
        // this against the accounting system", while `status` is "where is this bill in its own life".
        'status', 'due_date', 'terms_days',
        'approved_by', 'approved_by_name', 'approved_at',
        'paid_amount', 'paid_at', 'payment_reference', 'paid_by',
        'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'is_internal'               => 'boolean',
        'parts_total'               => 'decimal:2',
        'labor_total'               => 'decimal:2',
        'vat_total'                 => 'decimal:2',
        'discount_total'            => 'decimal:2',
        'amount'                    => 'decimal:2',
        'receipt_total'             => 'decimal:2',
        'reconciliation_flagged_at' => 'datetime',
        'reconciled_at'             => 'datetime',
        'recorded_at'               => 'datetime',
        'paid_amount'               => 'decimal:2',
        'due_date'                  => 'date',
        'approved_at'               => 'datetime',
        'paid_at'                   => 'datetime',
        'cancelled_at'              => 'datetime',
    ];

    // ── Financial document (IsFinancialDocument) ──────────────────────────────────────────────────

    /** What settling this garage bill in full would cost. */
    public function documentTotal(): float
    {
        return round((float) $this->amount, 2);
    }

    /**
     * A garage bill has no return path of its own — parts go back to the SUPPLIER that sold them, and a
     * labour refund is recorded as an adjustment rather than as a credit against this document. So there
     * is nothing to derive here, and a garage invoice never reports itself as refunded.
     */
    public function documentRefunded(): float
    {
        return 0.0;
    }

    /** A garage bill is keyed on its ticket, by whoever runs maintenance — the gate its own routes carry. */
    public function paperPermission(): string
    {
        return 'maintenance.manage';
    }

    /** A garage bill carries no separate invoice date — it is reckoned from when we recorded it. */
    public function documentDate(): ?string
    {
        $date = $this->recorded_at ?: $this->created_at;

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    protected static function booted(): void
    {
        // An invoice appearing / changing / leaving re-derives the ticket's invoice roll-up (its receipt
        // total + headline reconciliation status). saveQuietly on the ticket side avoids a write loop.
        static::saved(fn (MaintenanceInvoice $inv) => optional($inv->maintenance)->recalcInvoiceAggregate(true));
        static::deleted(fn (MaintenanceInvoice $inv) => optional($inv->maintenance)->recalcInvoiceAggregate(true));
    }

    // ── Relationships ─────────────────────────────────────────────────────────────────────────────

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    /** The garage that issued this invoice. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** The faults this invoice covers (fault→one invoice, so a plain hasMany by the back-reference). */
    public function tasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class, 'maintenance_invoice_id');
    }

    /** The part/labor cost lines that make up this invoice's total. */
    public function lineItems(): HasMany
    {
        return $this->hasMany(MaintenanceLineItem::class, 'maintenance_invoice_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    // ── Roll-up ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Re-derive this invoice's parts/labor split + grand `amount` from its own line items, then bubble the
     * change up to the parent ticket. Called from the line-item write hook and after the invoice service
     * replaces the line set. Persists quietly so it never re-triggers the saved() roll-up.
     */
    public function recalcTotals(): void
    {
        $lines = $this->relationLoaded('lineItems') ? $this->lineItems : $this->lineItems()->get();

        $parts = round((float) $lines->where('kind', MaintenanceLineItem::KIND_PART)->sum('line_total'), 2);
        $labor = round((float) $lines->where('kind', MaintenanceLineItem::KIND_LABOR)->sum('line_total'), 2);
        // VAT is positive, discount is stored negative — so the grand total stays a plain sum and no
        // reader has to remember which way each band points.
        $vat      = round((float) $lines->where('kind', MaintenanceLineItem::KIND_VAT)->sum('line_total'), 2);
        $discount = round((float) $lines->where('kind', MaintenanceLineItem::KIND_DISCOUNT)->sum('line_total'), 2);
        $adjust   = round((float) $lines->where('kind', MaintenanceLineItem::KIND_ADJUSTMENT)->sum('line_total'), 2);

        $this->parts_total    = $parts;
        $this->labor_total    = $labor;
        $this->vat_total      = $vat;
        $this->discount_total = $discount;
        $this->amount         = round($parts + $labor + $vat + $discount + $adjust, 2);
        $this->saveQuietly();
    }

    /** The signed gap between the keyed lines and the printed receipt (null when no receipt was entered). */
    public function variance(): ?float
    {
        return $this->receipt_total === null
            ? null
            : round((float) $this->amount - (float) $this->receipt_total, 2);
    }

    public function isReconciled(): bool
    {
        return $this->reconciliation_status === Maintenance::RECON_RECONCILED;
    }

    // ── Financial event source (App\Contracts\FinancialEventSource) ────────────────────────────────
    //
    // A garage bill is the CANONICAL producer of a repair/routine obligation, and it already holds
    // every fact the accounting system needs: the supplier, the number, the date, the receipt scan and
    // the itemised lines. So the financial layer ASKS this object rather than asking a person to type
    // the same things into a finance screen — that is §31, enforced by the interface rather than
    // remembered by a developer. Everything below is a read of existing state; nothing new is stored.

    /**
     * Which kind of cost this bill is, taken from the ticket's own classification.
     *
     * The ticket already answers "was this a breakdown or a scheduled service", so the expense type is
     * derived from it rather than being a second thing to set. A bill with nothing to pay generates NO
     * obligation and returns null — its event becomes NOT_REQUIRED, which is how a zero-cost or warranty
     * bill stays visible as "no money involved" instead of silently having no event at all.
     */
    public function financialExpenseType(): ?string
    {
        if (round((float) $this->amount, 2) <= 0) {
            return null;
        }

        return $this->maintenance?->maintenance_type === Maintenance::TYPE_ROUTINE
            ? \App\Support\ExpenseType::ROUTINE
            : \App\Support\ExpenseType::REPAIR;
    }

    public function financialAmount(): float
    {
        return round((float) $this->amount, 2);
    }

    public function financialCurrency(): string
    {
        return 'AED';
    }

    public function financialVehicleId(): ?int
    {
        return $this->maintenance?->vehicle_id;
    }

    public function financialMaintenanceId(): ?int
    {
        return $this->maintenance_id;
    }

    /**
     * The garage that issued the bill.
     *
     * An INTERNAL job (is_internal) has no third party to pay, so it names no vendor — and because a
     * vendor bill requires a mapped partner, such an event blocks with `supplier_missing` until somebody
     * decides how in-house work should be recorded. That is the honest outcome: we genuinely do not know
     * who to bill, and guessing would post a cost against the wrong partner.
     */
    public function financialVendorId(): ?int
    {
        return $this->is_internal ? null : $this->vendor_id;
    }

    public function financialInvoiceNumber(): ?string
    {
        $no = trim((string) $this->invoice_no);

        return $no === '' ? null : $no;
    }

    /** The bill's own date — recorded_at, which is what documentDate() already reckons this bill from. */
    public function financialInvoiceDate(): ?string
    {
        return $this->documentDate();
    }

    /** @return array{disk:?string, key:?string}|null */
    public function financialAttachment(): ?array
    {
        return $this->receipt_photo_key
            ? ['disk' => $this->receipt_photo_disk, 'key' => $this->receipt_photo_key]
            : null;
    }

    public function financialDescription(): string
    {
        $plate  = $this->maintenance?->vehicle?->plate_no ?: $this->maintenance?->plate;
        $garage = $this->vendor?->name;

        return trim(implode(' — ', array_filter([
            'Maintenance ticket #' . $this->maintenance_id,
            $plate ? 'Vehicle ' . $plate : null,
            $garage,
        ])));
    }

    /**
     * The postable lines: the bill's own WORK lines, and only those.
     *
     * VAT, discount and adjustment bands are deliberately excluded. They share the lineItems() relation
     * with work lines ([[invoice-bands-are-ledger-lines]]), but they are document-level arithmetic
     * rather than things bought — Odoo computes tax from its own tax records on the bill, so sending a
     * VAT line as a product line would tax the bill twice. The event's amount therefore reconciles
     * against the WORK total, and {@see \App\Services\Odoo\FinancialEventBuilder} sets it from these
     * lines rather than from `amount`.
     *
     * @return list<array{origin_type:?string, origin_id:?int, component_catalog_id:?int, kind:string,
     *                    description:string, quantity:float, uom:?string, unit_price:float}>
     */
    public function financialLines(): array
    {
        $lines = $this->relationLoaded('lineItems') ? $this->lineItems : $this->lineItems()->get();

        return $lines
            ->whereIn('kind', MaintenanceLineItem::WORK_KINDS)
            ->map(fn (MaintenanceLineItem $li) => [
                'origin_type'          => $li->getMorphClass(),
                'origin_id'            => $li->id,
                'component_catalog_id' => $li->component_catalog_id,
                'kind'                 => $li->kind === MaintenanceLineItem::KIND_PART
                    ? \App\Models\FinancialEventLine::KIND_PART
                    : \App\Models\FinancialEventLine::KIND_LABOR,
                'description'          => (string) ($li->description ?: 'Line ' . $li->id),
                'quantity'             => (float) $li->quantity,
                'uom'                  => $li->uom,
                'unit_price'           => (float) $li->unit_price,
            ])
            ->values()
            ->all();
    }

    /** A viewable URL for the uploaded receipt photo (signed temporary for S3, plain URL otherwise). */
    public function receiptPhotoUrl(): ?string
    {
        if (! $this->receipt_photo_key) {
            return null;
        }
        $disk = Storage::disk($this->receipt_photo_disk ?: 'public');

        try {
            return ($this->receipt_photo_disk === 's3')
                ? $disk->temporaryUrl($this->receipt_photo_key, Carbon::now()->addMinutes(30))
                : $disk->url($this->receipt_photo_key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
