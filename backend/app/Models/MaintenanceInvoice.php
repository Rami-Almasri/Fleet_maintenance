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
class MaintenanceInvoice extends Model
{
    protected $table = 'maintenance_invoices';

    protected $fillable = [
        'maintenance_id',
        'vendor_id',
        'is_internal',
        'invoice_no',
        'parts_total',
        'labor_total',
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
    ];

    protected $casts = [
        'is_internal'               => 'boolean',
        'parts_total'               => 'decimal:2',
        'labor_total'               => 'decimal:2',
        'amount'                    => 'decimal:2',
        'receipt_total'             => 'decimal:2',
        'reconciliation_flagged_at' => 'datetime',
        'reconciled_at'             => 'datetime',
        'recorded_at'               => 'datetime',
    ];

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

        $this->parts_total = $parts;
        $this->labor_total = $labor;
        $this->amount      = round($parts + $labor, 2);
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
