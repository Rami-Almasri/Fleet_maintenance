<?php

namespace App\Models;

use App\Contracts\FinancialEventSource;
use App\Support\ExpenseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One tank of fuel put into one car.
 *
 * Operational first: the litres and the odometer reading are what make consumption answerable per car,
 * and they are the reason this record exists at all (see the create_fuel_fills_table migration). The
 * cost is one of its facts, and it reaches Odoo through exactly the same path as a garage bill — this
 * class implements {@see FinancialEventSource} and nothing else about fuel is special.
 *
 * A fill costing nothing raises no obligation, so an internal transfer from a company tank can be
 * recorded for its consumption data without inventing a bill nobody received.
 */
class FuelFill extends Model implements FinancialEventSource
{
    protected $table = 'fuel_fills';

    protected $fillable = [
        'vehicle_id', 'maintenance_id',
        'filled_at', 'litres', 'odometer_km', 'fuel_grade',
        'vendor_id', 'station_name',
        'cost', 'currency',
        'receipt_no', 'receipt_date', 'receipt_disk', 'receipt_key',
        'notes', 'recorded_by',
    ];

    protected $casts = [
        'filled_at'    => 'datetime',
        'litres'       => 'decimal:2',
        'odometer_km'  => 'integer',
        'cost'         => 'decimal:2',
        'receipt_date' => 'date',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The station. A supplier like any other — it needs an Odoo partner before a bill can be posted. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** The maintenance visit this fuel was bought for, when there was one. */
    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Litres per 100 km is not derivable from one fill — it needs the previous one. Kept out of here. */
    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where('vehicle_id', $vehicleId);
    }

    // ── Financial event source ────────────────────────────────────────────────────────────────────

    public function financialExpenseType(): ?string
    {
        return round((float) $this->cost, 2) > 0 ? ExpenseType::FUEL : null;
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

    public function financialMaintenanceId(): ?int
    {
        return $this->maintenance_id;
    }

    public function financialVendorId(): ?int
    {
        return $this->vendor_id;
    }

    public function financialInvoiceNumber(): ?string
    {
        $no = trim((string) $this->receipt_no);

        return $no === '' ? null : $no;
    }

    /** The receipt's own date where one was keyed, otherwise the day the car was filled. */
    public function financialInvoiceDate(): ?string
    {
        $date = $this->receipt_date ?: $this->filled_at;

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
        $plate   = $this->vehicle?->plate_no;
        $station = trim((string) ($this->vendor?->name ?: $this->station_name));

        return trim(implode(' — ', array_filter([
            'Fuel' . ($this->litres ? ' ' . rtrim(rtrim(number_format((float) $this->litres, 2), '0'), '.') . ' L' : ''),
            $plate ? 'Vehicle ' . $plate : null,
            $station !== '' ? $station : null,
        ])));
    }

    /**
     * Fuel is a single service charge, not an itemised bill — so no lines, and the builder writes one
     * service line from the amount above. Deliberately NOT posted as a "fuel product": a fleet that has
     * not chosen to carry fuel in its Odoo product catalogue should not have one invented for it (§26).
     */
    public function financialLines(): array
    {
        return [];
    }
}
