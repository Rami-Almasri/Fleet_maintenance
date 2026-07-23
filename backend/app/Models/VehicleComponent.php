<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ONE physical asset instance — the heart of the Asset Layer. Created once when the part enters
 * our world; never deleted, never moved to another table. Install/removal legs are filled in
 * place; status + location say where the part is NOW.
 *
 * Standing business rules this model helps enforce:
 *   - a vehicle must always know its current installed components (vehicle_id + status=active);
 *   - every removed component carries a disposition — old parts never disappear from history;
 *   - consumable catalog types never instantiate this model (guarded here AND in the service);
 *   - components are ASSETS, not billing: money stays on maintenance_line_items, linked via
 *     source_line_item_id / source_part_purchase_id.
 *
 * WRITE DISCIPLINE: `status`, `location` and the whole removal leg are intentionally NOT fillable.
 * They are set by explicit assignment inside ComponentService (Phase 2) — the single write choke
 * point that owns the state machine, predecessor locking and event writes. The booted() guard is
 * the last line of defense, not the API.
 */
class VehicleComponent extends Model
{
    // ── Status: where in the lifecycle (3 values — deliberately small) ─────────────────────────
    public const STATUS_IN_STOCK = 'in_stock';  // exists, installable, on no vehicle
    public const STATUS_ACTIVE   = 'active';    // installed on exactly one vehicle
    public const STATUS_RETIRED  = 'retired';   // permanently out — absorbing state
    public const STATUSES = [self::STATUS_IN_STOCK, self::STATUS_ACTIVE, self::STATUS_RETIRED];

    // ── Location: where the thing physically is ────────────────────────────────────────────────
    public const LOC_ON_VEHICLE = 'on_vehicle';
    public const LOC_WAREHOUSE  = 'warehouse';
    public const LOC_REFURB     = 'refurb';     // being reconditioned; not installable until back to warehouse
    public const LOC_SUPPLIER   = 'supplier';   // returned / warranty-returned
    public const LOC_SCRAPPED   = 'scrapped';
    public const LOC_SOLD       = 'sold';       // standalone sale AND sold-with-vehicle (disposition distinguishes)
    public const LOCATIONS = [
        self::LOC_ON_VEHICLE, self::LOC_WAREHOUSE, self::LOC_REFURB,
        self::LOC_SUPPLIER, self::LOC_SCRAPPED, self::LOC_SOLD,
    ];

    /** The only legal (status, location) pairs — anything else is a corrupt row. */
    public const VALID_STATUS_LOCATIONS = [
        self::STATUS_ACTIVE   => [self::LOC_ON_VEHICLE],
        self::STATUS_IN_STOCK => [self::LOC_WAREHOUSE, self::LOC_REFURB],
        self::STATUS_RETIRED  => [self::LOC_SUPPLIER, self::LOC_SCRAPPED, self::LOC_SOLD],
    ];

    // ── Removal reason: WHY it came off ────────────────────────────────────────────────────────
    public const REASON_FAILED         = 'failed';
    public const REASON_WORN_OUT       = 'worn_out';
    public const REASON_ACCIDENT       = 'accident';
    public const REASON_UPGRADE        = 'upgrade';
    public const REASON_RECALL         = 'recall';
    public const REASON_TRANSFER       = 'transfer';        // moved to another vehicle
    public const REASON_VEHICLE_SOLD   = 'vehicle_sold';
    public const REASON_UNKNOWN_LEGACY = 'unknown_legacy';  // backfill only — never selectable in UI
    public const REMOVAL_REASONS = [
        self::REASON_FAILED, self::REASON_WORN_OUT, self::REASON_ACCIDENT,
        self::REASON_UPGRADE, self::REASON_RECALL, self::REASON_TRANSFER,
        self::REASON_VEHICLE_SOLD, self::REASON_UNKNOWN_LEGACY,
    ];

    // ── Disposition: WHERE it went — mandatory whenever removed ────────────────────────────────
    public const DISP_STORED            = 'stored';             // → in_stock/warehouse
    public const DISP_SCRAPPED          = 'scrapped';
    public const DISP_RETURNED_SUPPLIER = 'returned_supplier';
    public const DISP_WARRANTY_RETURN   = 'warranty_return';    // supplier return under warranty → claim tracking
    public const DISP_SOLD              = 'sold';
    public const DISP_TRANSFERRED       = 'transferred';        // stays active, new vehicle
    public const DISP_SOLD_WITH_VEHICLE = 'sold_with_vehicle';
    public const DISP_UNKNOWN_LEGACY    = 'unknown_legacy';     // backfill only — never selectable in UI
    public const DISPOSITIONS = [
        self::DISP_STORED, self::DISP_SCRAPPED, self::DISP_RETURNED_SUPPLIER,
        self::DISP_WARRANTY_RETURN, self::DISP_SOLD, self::DISP_TRANSFERRED,
        self::DISP_SOLD_WITH_VEHICLE, self::DISP_UNKNOWN_LEGACY,
    ];

    // ── Provenance trust label ──────────────────────────────────────────────────────────────────
    public const SOURCE_WORKFLOW        = 'workflow';
    public const SOURCE_MANUAL          = 'manual';
    public const SOURCE_LEGACY_BACKFILL = 'legacy_backfill';
    public const SOURCES = [self::SOURCE_WORKFLOW, self::SOURCE_MANUAL, self::SOURCE_LEGACY_BACKFILL];

    // ── Shadow-launch trust markers (write_mode + validation_status, §7.1 of the launch plan) ───
    public const VALIDATION_PROVISIONAL = 'provisional'; // observation — not yet confirmed against reality
    public const VALIDATION_VALIDATED   = 'validated';   // promoted (shadow gate) or born under enforced validation
    public const VALIDATION_QUARANTINED = 'quarantined'; // known-bad: kept for audit, excluded from every read surface
    public const VALIDATION_STATUSES = [
        self::VALIDATION_PROVISIONAL, self::VALIDATION_VALIDATED, self::VALIDATION_QUARANTINED,
    ];

    /**
     * NOTE: status, location, and the entire removal leg (removed_*, removal_*, disposition,
     * replaced_by_component_id) are deliberately ABSENT — they may only be set by explicit
     * assignment inside ComponentService, never by mass assignment from a request.
     */
    protected $fillable = [
        'component_catalog_id', 'vehicle_id',
        'serial_no', 'part_number', 'brand', 'model', 'label', 'quantity', 'position',
        'installed_at', 'installed_odometer', 'installed_by', 'installed_by_name',
        'technician_name', 'installer_vendor_id', 'supplier_vendor_id',
        'purchase_cost', 'currency', 'warranty_months',
        'source_part_purchase_id', 'source_line_item_id', 'source',
    ];

    protected $casts = [
        'quantity'           => 'decimal:2',
        'installed_at'       => 'datetime',
        'installed_odometer' => 'integer',
        'purchase_cost'      => 'decimal:2',
        'warranty_months'    => 'integer',
        'warranty_until'     => 'date',
        'removed_at'         => 'datetime',
        'removed_odometer'   => 'integer',
        'validated_at'       => 'datetime',
    ];

    /** Trusted for reads: never quarantined (post-gate surfaces additionally prefer validated). */
    public function scopeTrusted(Builder $query): Builder
    {
        return $query->where('validation_status', '!=', self::VALIDATION_QUARANTINED);
    }

    protected static function booted(): void
    {
        static::creating(function (self $component) {
            // Standing business rule: consumables (oil, coolant, …) NEVER become components —
            // their work is a ServiceRecord. Enforced here as well as in the service so no code
            // path (including future ones) can slip a consumable into the asset ledger.
            $catalog = $component->relationLoaded('catalog')
                ? $component->catalog
                : ComponentCatalog::find($component->component_catalog_id);

            if ($catalog && $catalog->isConsumable()) {
                throw new \DomainException(
                    "Catalog entry [{$catalog->slug}] is a consumable — it cannot become a vehicle component. Record it as a service instead."
                );
            }
        });

        static::saving(function (self $component) {
            // Derived, never hand-set: warranty_until = installed_at + warranty_months
            // (same pattern as MaintenanceLineItem — the durability/expiry every report reads).
            if ($component->installed_at && $component->warranty_months) {
                $component->warranty_until = Carbon::parse($component->installed_at)
                    ->startOfDay()
                    ->addMonths((int) $component->warranty_months);
            } elseif (! $component->warranty_months) {
                $component->warranty_until = null;
            }

            // Last line of defense on the (status, location) invariant. The service validates
            // first with a friendly 422; reaching here with a bad pair is a programming error.
            $allowed = self::VALID_STATUS_LOCATIONS[$component->status] ?? null;
            if ($allowed === null || ! in_array($component->location, $allowed, true)) {
                throw new \DomainException(
                    "Invalid component state: status [{$component->status}] cannot be at location [{$component->location}]."
                );
            }

            // active must sit on a vehicle; a spare in stock belongs to the fleet, not a car.
            // retired MAY keep vehicle_id — it means "the last car this part's story ended on"
            // (sold_with_vehicle, legacy predecessors), which keeps the per-vehicle history query
            // trivial: WHERE vehicle_id = ? AND status = retired.
            if ($component->status === self::STATUS_ACTIVE && ! $component->vehicle_id) {
                throw new \DomainException('An active component must be attached to a vehicle.');
            }
            if ($component->status === self::STATUS_IN_STOCK && $component->vehicle_id) {
                throw new \DomainException('A component in stock cannot be attached to a vehicle.');
            }
        });
    }

    // ── Relations ───────────────────────────────────────────────────────────────────────────────

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_catalog_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'supplier_vendor_id');
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'installer_vendor_id');
    }

    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function sourcePurchase(): BelongsTo
    {
        return $this->belongsTo(PartPurchase::class, 'source_part_purchase_id');
    }

    public function sourceLineItem(): BelongsTo
    {
        return $this->belongsTo(MaintenanceLineItem::class, 'source_line_item_id');
    }

    public function removalTicket(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'removal_maintenance_id');
    }

    /** The successor that replaced this part. */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_component_id');
    }

    /** Inverse: the predecessor this part replaced. */
    public function replaces(): HasOne
    {
        return $this->hasOne(self::class, 'replaced_by_component_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ComponentEvent::class, 'vehicle_component_id');
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(ServiceRecord::class, 'related_component_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(MaintenanceMedia::class, 'vehicle_component_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────────────────────────

    /** The "current components" read — what is on this car right now. */
    public function scopeActiveOn(Builder $query, int $vehicleId): Builder
    {
        return $query->where('vehicle_id', $vehicleId)->where('status', self::STATUS_ACTIVE);
    }

    /** Warehouse/spares inventory. */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_STOCK);
    }

    public function scopeRetired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RETIRED);
    }

    /** Predecessor lookup at install time: the active part occupying this slot. */
    public function scopeForSlot(Builder $query, int $catalogId, int $vehicleId, ?string $position): Builder
    {
        return $query->where('component_catalog_id', $catalogId)
            ->where('vehicle_id', $vehicleId)
            ->where('position', $position)
            ->where('status', self::STATUS_ACTIVE);
    }

    public function scopeUnderWarranty(Builder $query): Builder
    {
        return $query->whereDate('warranty_until', '>=', now()->toDateString());
    }

    /**
     * Missed warranty recovery — money left on the table: the part failed and was thrown away
     * while its warranty was still valid. Feeds the finance-leak detector (Phase 2).
     */
    public function scopeWarrantyMissedRecovery(Builder $query): Builder
    {
        return $query->where('removal_reason', self::REASON_FAILED)
            ->where('disposition', self::DISP_SCRAPPED)
            ->whereNotNull('removed_at')
            ->whereColumn('warranty_until', '>=', 'removed_at');
    }

    // ── Accessors (derived read models — never columns) ────────────────────────────────────────

    public function getLifeKmAttribute(): ?int
    {
        if ($this->removed_odometer === null || $this->installed_odometer === null) {
            return null;
        }

        return max(0, $this->removed_odometer - $this->installed_odometer);
    }

    public function getLifeDaysAttribute(): ?int
    {
        if (! $this->removed_at || ! $this->installed_at) {
            return null;
        }

        return (int) $this->installed_at->diffInDays($this->removed_at);
    }

    public function getWarrantyRemainingMonthsAttribute(): ?int
    {
        if (! $this->warranty_until) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInMonths($this->warranty_until, false));
    }

    public function getIsLegacyAttribute(): bool
    {
        return $this->source === self::SOURCE_LEGACY_BACKFILL;
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }
}
