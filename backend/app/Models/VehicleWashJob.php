<?php

namespace App\Models;

use App\Contracts\FinancialEventSource;
use App\Support\ExpenseType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One wash of one car — the record behind a cleaning status change.
 *
 * See the create_vehicle_wash_jobs_table migration for why this exists: `vehicles.cleaning_status` is
 * the car's CURRENT state, so it can never be the history of individual washes, and an outsourced wash
 * had nowhere to record what it cost.
 *
 * The financial half is conditional and that is the point. An INTERNAL wash — our own staff, our own
 * bay — was never billed, so it raises no obligation and simply sits in the history. Only an external
 * wash with a cost becomes a CAR_WASH event.
 */
class VehicleWashJob extends Model implements FinancialEventSource
{
    protected $table = 'vehicle_wash_jobs';

    /** What was actually done. Operational vocabulary, not a price list. */
    public const TYPE_EXTERIOR  = 'exterior';
    public const TYPE_INTERIOR  = 'interior';
    public const TYPE_FULL      = 'full';
    public const TYPE_DETAILING = 'detailing';
    public const TYPES = [self::TYPE_EXTERIOR, self::TYPE_INTERIOR, self::TYPE_FULL, self::TYPE_DETAILING];

    /** Our own bay — no third party, no bill, no financial event. */
    public const BY_INTERNAL = 'internal';
    /** Bought from a car wash — the only kind that can carry a cost. */
    public const BY_EXTERNAL = 'external';
    public const PERFORMERS = [self::BY_INTERNAL, self::BY_EXTERNAL];

    protected $fillable = [
        'vehicle_id', 'washed_at', 'wash_type', 'performed_by_kind',
        'vendor_id', 'cost', 'currency',
        'invoice_no', 'invoice_date', 'receipt_disk', 'receipt_key',
        'notes', 'recorded_by',
    ];

    protected $casts = [
        'washed_at'    => 'datetime',
        'cost'         => 'decimal:2',
        'invoice_date' => 'date',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isInternal(): bool
    {
        return $this->performed_by_kind === self::BY_INTERNAL;
    }

    // ── Financial event source ────────────────────────────────────────────────────────────────────

    /**
     * CAR_WASH only when somebody billed us.
     *
     * An internal wash returns null and stays pure history. That is not a shortcut — it is the
     * difference between "this cost nothing externally" and "this cost zero", and recording the latter
     * would put a nil bill in front of finance for every wash the team does in-house.
     */
    public function financialExpenseType(): ?string
    {
        if ($this->isInternal()) {
            return null;
        }

        return round((float) $this->cost, 2) > 0 ? ExpenseType::CAR_WASH : null;
    }

    public function financialAmount(): float
    {
        return round((float) $this->cost, 2);
    }

    public function financialCurrency(): string
    {
        return $this->currency ?: 'AED';
    }

    public function financialVehicleId(): ?int
    {
        return $this->vehicle_id;
    }

    /** A wash is not part of a repair visit — it stands on its own. */
    public function financialMaintenanceId(): ?int
    {
        return null;
    }

    public function financialVendorId(): ?int
    {
        return $this->vendor_id;
    }

    public function financialInvoiceNumber(): ?string
    {
        $no = trim((string) $this->invoice_no);

        return $no === '' ? null : $no;
    }

    public function financialInvoiceDate(): ?string
    {
        $date = $this->invoice_date ?: $this->washed_at;

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    /** @return array{disk:?string, key:?string}|null */
    public function financialAttachment(): ?array
    {
        return $this->receipt_key
            ? ['disk' => $this->receipt_disk, 'key' => $this->receipt_key]
            : null;
    }

    public function financialDescription(): string
    {
        $plate = $this->vehicle?->plate_no;

        return trim(implode(' — ', array_filter([
            'Car wash (' . $this->wash_type . ')',
            $plate ? 'Vehicle ' . $plate : null,
            $this->vendor?->name,
        ])));
    }

    /** A service, not a catalogue item — one service line, no fabricated Odoo product. */
    public function financialLines(): array
    {
        return [];
    }
}
