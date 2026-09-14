<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One billable line on a maintenance ticket — a replaced PART or a LABOR charge. Together the lines
 * make up the ticket's structured cost (see [[maintenances.cost]] = parts_total + labor_total). A
 * part line additionally carries the install date + warranty so the fleet can answer durability
 * questions ("how long did these brake pads last?"); a labor line is hours × rate.
 *
 * The columns are shaped to map cleanly onto Odoo later: a part → BOM component / vendor-bill line,
 * a labor line → expense / service product, `category_key` → product category / analytic account,
 * and the parent's `vehicle_id` → the per-asset analytic account that rolls up total cost of
 * ownership. `line_total` and `warranty_until` are kept consistent automatically on every save.
 */
class MaintenanceLineItem extends Model
{
    use HasFactory;

    protected $table = 'maintenance_line_items';

    /** A replaced part — count × unit price, with durability/warranty tracking. */
    public const KIND_PART = 'part';
    /** A garage labor charge — hours × rate. */
    public const KIND_LABOR = 'labor';
    /** Tax charged on a document. Always positive; belongs to the invoice, not to a fault. */
    public const KIND_VAT = 'vat';
    /** A reduction granted on a document. Always NEGATIVE, so every band is a plain sum. */
    public const KIND_DISCOUNT = 'discount';
    /** A correction backed by a {@see CostAdjustment} — the fourth source document. Signed either way. */
    public const KIND_ADJUSTMENT = 'adjustment';

    public const KINDS = [self::KIND_PART, self::KIND_LABOR, self::KIND_VAT, self::KIND_DISCOUNT, self::KIND_ADJUSTMENT];

    /**
     * The kinds a human keys directly onto an invoice as work done. VAT and discount are document-level
     * arithmetic rather than work, and an adjustment is written by its own service — so the invoice line
     * editor offers only these two, and Diagnosis-First (every line names a fault) applies only to them.
     */
    public const WORK_KINDS = [self::KIND_PART, self::KIND_LABOR];

    /**
     * The three records a billed part can come from, most certain first — a purchase carries a price
     * that was actually paid, a request carries only what was asked for, and a required line is the
     * inspector's technical call. {@see part_source} names which one; part_source_id points at the row.
     */
    public const PART_SOURCE_PURCHASE = 'purchase';
    public const PART_SOURCE_REQUEST  = 'request';
    public const PART_SOURCE_REQUIRED = 'required';
    public const PART_SOURCES = [self::PART_SOURCE_PURCHASE, self::PART_SOURCE_REQUEST, self::PART_SOURCE_REQUIRED];

    protected $fillable = [
        // Was this line done under warranty, and who honoured it? CLASSIFICATION ONLY — it never
        // touches the amount. A warranty line keeps whatever cost was recorded, because the moment
        // this flag starts editing money it becomes a second, silent accounting path.
        // @see the add_under_warranty_to_maintenance_work migration.
        'under_warranty', 'warranty_provider',
        'maintenance_id',
        'maintenance_invoice_id',
        'maintenance_task_id',
        'vehicle_id',
        'kind',
        'finding_text',
        'category_key',
        'description',
        'part_number',
        // WHICH PART this is, as a reference rather than a spelling. `description` stays as the label
        // that was billed (the garage's own wording is evidence); the id is what anything counting
        // parts, comparing prices or measuring lifespan joins on.
        'component_catalog_id',
        'catalog_matched_by',
        // WHERE the billed part came from — the purchase paid for, the request raised, or the
        // inspector's required-part line. See PART_SOURCES: this is what the price on the line was
        // filled from, and what proves the part belongs on THIS bill rather than a supplier's.
        'part_source',
        'part_source_id',
        'tire_brand',
        'tire_dot',
        'tire_tread_mm',
        'quantity',
        'uom',
        'unit_price',
        'line_total',
        'installed_on',
        'installed_odometer',
        'warranty_months',
        'warranty_until',
        'odoo_product_ref',
        'odoo_external_id',
        'odoo_synced_at',
        'created_by',
        'entry_source',
        // The STRUCTURED origin: which of the four source documents backs this amount. Denormalised from
        // the relations so spend can be reported by origin with a plain join, and kept in step by the
        // services that write lines (and by PartInvoiceService when a purchase is later invoiced).
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'under_warranty'     => 'boolean',
        'quantity'           => 'decimal:2',
        'tire_tread_mm'      => 'decimal:1',
        'unit_price'         => 'decimal:2',
        'line_total'         => 'decimal:2',
        'installed_on'       => 'date',
        'installed_odometer' => 'integer',
        'warranty_months'    => 'integer',
        'warranty_until'     => 'date',
        'odoo_synced_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        // Keep the derived fields honest on every write, so callers only ever set the raw inputs:
        //   line_total      = quantity × unit_price (the auto-sum the totals roll up from)
        //   warranty_until  = installed_on + warranty_months (the durability/expiry the report reads)
        static::saving(function (MaintenanceLineItem $item) {
            $item->line_total = round((float) $item->quantity * (float) $item->unit_price, 2);

            if ($item->installed_on && $item->warranty_months) {
                $item->warranty_until = Carbon::parse($item->installed_on)->addMonths((int) $item->warranty_months);
            } elseif (! $item->warranty_months) {
                $item->warranty_until = null;
            }
        });

        // After a line lands or leaves, roll its money up the chain: the fault it's tagged to (parts_cost /
        // labor_cost), which in turn re-derives the parent ticket total. A line that switched tasks updates
        // BOTH the old and the new fault. Quiet saves on the rollup side keep this from looping.
        static::saved(fn (MaintenanceLineItem $item) => $item->rollUpCosts());
        static::deleted(fn (MaintenanceLineItem $item) => $item->rollUpCosts());
    }

    /**
     * Recompute the cached cost of every fault this line touches (the current task, plus the previous one
     * if the line was just re-assigned), then the parent ticket. When the line belongs to no task, the
     * ticket total is still refreshed directly so a task-less (general) charge keeps `cost` correct.
     */
    public function rollUpCosts(): void
    {
        // Refresh the invoice(s) this line touches — the current one plus, if it was just re-assigned, the
        // previous one — so each invoice's parts/labor split + grand amount stays honest.
        $invoiceIds = array_unique(array_filter([
            $this->maintenance_invoice_id,
            $this->getOriginal('maintenance_invoice_id'),
        ]));
        if ($invoiceIds) {
            MaintenanceInvoice::with('lineItems')->whereIn('id', $invoiceIds)->get()
                ->each->recalcTotals();
        }

        $taskIds = array_unique(array_filter([
            $this->maintenance_task_id,
            $this->getOriginal('maintenance_task_id'),
        ]));

        if ($taskIds) {
            MaintenanceTask::with('lineItems')->whereIn('id', $taskIds)->get()
                ->each->recalcCosts(); // each task recompute bubbles up to the parent ticket
            return;
        }

        optional($this->maintenance)->recalcFromTasks(true);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    /** The specific fault this part/labor line repairs (null = a general, ticket-wide charge). */
    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /** The garage invoice this cost line belongs to (see [[one Ticket → many Invoices]]). */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(MaintenanceInvoice::class, 'maintenance_invoice_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The catalog part this line billed (null on a labor line, or on history typed before the picker). */
    public function catalogPart(): BelongsTo
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_catalog_id');
    }

    public function isPart(): bool
    {
        return $this->kind === self::KIND_PART;
    }
}
