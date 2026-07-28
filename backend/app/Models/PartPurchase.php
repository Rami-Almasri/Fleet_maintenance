<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part purchase — the money event, and the bridge onto the existing maintenance cost chain.
 *
 * Records who bought what, from a GARAGE or an external SUPPLIER ({@see PURCHASE_SOURCES}), for how much,
 * when, and — once fitted — against which fault. On install it generates a maintenance_line_items row
 * (kind=part) and stores its id in {@see maintenance_line_item_id}; cost then rolls up line_item → task →
 * ticket exactly as before. A purchase with no ticket carries only its own price and creates no line item.
 */
class PartPurchase extends Model
{
    public const SOURCE_GARAGE   = 'garage';
    public const SOURCE_SUPPLIER = 'supplier';
    public const PURCHASE_SOURCES = [self::SOURCE_GARAGE, self::SOURCE_SUPPLIER];

    public const RESULT_PENDING = 'pending';
    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILED  = 'failed';
    public const RESULTS = [self::RESULT_PENDING, self::RESULT_SUCCESS, self::RESULT_FAILED];

    protected $fillable = [
        'part_request_id',
        // Phase 2 (blueprint §3d): the awarded RFQ line + supplier quote this PO fulfils, + a human PO ref.
        'rfq_line_id', 'supplier_quote_id', 'po_number',
        'vehicle_id', 'maintenance_id', 'maintenance_task_id',
        'part_name', 'part_number', 'category_key', 'part_class',
        'purchase_source', 'source_vendor_id', 'source_name',
        'repair_location',
        'purchase_price', 'currency', 'quantity',
        'purchased_by', 'purchased_by_name', 'purchased_at',
        'expected_delivery_date', 'delivered_at',
        'installed_by', 'installed_by_name', 'installed_at', 'installed_odometer',
        'result',
        'maintenance_line_item_id',
        'requires_review', 'duplicate_of_purchase_id',
        'notes',
    ];

    protected $casts = [
        'purchase_price'     => 'decimal:2',
        'quantity'           => 'decimal:2',
        'purchased_at'       => 'datetime',
        'expected_delivery_date' => 'date',
        'delivered_at'       => 'datetime',
        'installed_at'       => 'datetime',
        'installed_odometer' => 'integer',
        'requires_review'    => 'boolean',
    ];

    public function isInstalled(): bool
    {
        return $this->installed_at !== null;
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PartRequest::class, 'part_request_id');
    }

    /** Phase 2: the awarded RFQ line this PO was issued against (null for direct buys). */
    public function rfqLine(): BelongsTo
    {
        return $this->belongsTo(RfqLine::class, 'rfq_line_id');
    }

    /** Phase 2: the awarded supplier quote this PO was issued against (null for direct buys). */
    public function supplierQuote(): BelongsTo
    {
        return $this->belongsTo(SupplierQuote::class, 'supplier_quote_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /** The garage or supplier the part was bought from (either is a Vendor row). */
    public function sourceVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'source_vendor_id');
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLineItem::class, 'maintenance_line_item_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(PartPurchase::class, 'duplicate_of_purchase_id');
    }

    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchased_by');
    }

    /** Asset Layer: the physical component this purchase became (set by ComponentService, Phase 2). */
    public function component(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(VehicleComponent::class, 'source_part_purchase_id');
    }
}
