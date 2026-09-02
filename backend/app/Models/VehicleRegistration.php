<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleRegistration extends Model implements \App\Contracts\FinancialEventSource
{
    /** @use HasFactory<\Database\Factories\VehicleRegistrationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'vehicle_id',
        'chasis_no',
        'expiry_date',
        'status',
        'fines_count',
        'fines_amount',
        'mortgaged_by',
        'is_mortgaged',
        'insurance_company_id',
        'insurance_company_no',
        'insurance_no',
        'insurance_issue_date',
        'insurance_expiry',
        'insurance_type',
        'insurance_bear_amount',
        'external_id',
        'synced_at',
        'origin',
        // The renewal's money side. NOT written by VehicleRegistrationImporter — it upserts an explicit
        // list of OM-supplied fields, so these survive every re-import. Adding them to that list would
        // null a fee somebody keyed, which is the one change to avoid here.
        'renewal_cost', 'renewal_currency', 'renewal_date', 'renewal_vendor_id',
        'renewal_reference', 'renewal_receipt_disk', 'renewal_receipt_key',
        'renewal_recorded_by', 'renewal_recorded_at',
    ];

    protected $casts = [
        'expiry_date'           => 'date',
        'insurance_issue_date'  => 'date',
        'insurance_expiry'      => 'date',
        'insurance_bear_amount' => 'decimal:2',
        'is_mortgaged'          => 'boolean',
        'synced_at'             => 'datetime',
        'renewal_cost'          => 'decimal:2',
        'renewal_date'          => 'date',
        'renewal_recorded_at'   => 'datetime',
    ];

    /** Days until the insurance expires (negative = already expired, null = unknown). */
    public function getInsuranceDaysLeftAttribute(): ?int
    {
        return $this->insurance_expiry
            ? (int) now()->startOfDay()->diffInDays($this->insurance_expiry, false)
            : null;
    }

    /** Days until the registration / mulkiya expires. */
    public function getRegistrationDaysLeftAttribute(): ?int
    {
        return $this->expiry_date
            ? (int) now()->startOfDay()->diffInDays($this->expiry_date, false)
            : null;
    }

    /**
     * Days the registration is still valid for — calculated from expiry_date
     * (replaces the old stored `valid_days` column). Alias of registration_days_left.
     */
    public function getValidDaysAttribute(): ?int
    {
        return $this->registration_days_left;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function insuranceCompany(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'insurance_company_id');
    }

    /** Who we paid to renew — a typing centre, a broker, or the authority. NOT the insurer. */
    public function renewalVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'renewal_vendor_id');
    }

    // ── Financial event source: THE RENEWAL FEE ────────────────────────────────────────────────────
    //
    // A registration record as a FinancialEventSource means one thing: what the current renewal cost.
    // Not the insurance premium — that is a different transaction with a different counterparty, and
    // `insurance_bear_amount` is an excess figure rather than a bill we paid. Not the fines either:
    // `fines_amount` is what the authority says is outstanding, which is a liability we may dispute and
    // have not necessarily been billed for. Posting either as a registration expense would be inventing
    // a document, so both are deliberately left alone.

    public function financialExpenseType(): ?string
    {
        return round((float) $this->renewal_cost, 2) > 0
            ? \App\Support\ExpenseType::REGISTRATION
            : null;
    }

    public function financialAmount(): float
    {
        return round((float) $this->renewal_cost, 2);
    }

    public function financialCurrency(): string
    {
        return $this->renewal_currency ?: 'AED';
    }

    public function financialVehicleId(): ?int
    {
        return $this->vehicle_id;
    }

    /** A renewal is fleet administration, not a repair visit. */
    public function financialMaintenanceId(): ?int
    {
        return null;
    }

    public function financialVendorId(): ?int
    {
        return $this->renewal_vendor_id;
    }

    public function financialInvoiceNumber(): ?string
    {
        $ref = trim((string) $this->renewal_reference);

        return $ref === '' ? null : $ref;
    }

    public function financialInvoiceDate(): ?string
    {
        $date = $this->renewal_date ?: $this->renewal_recorded_at;

        return $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : null;
    }

    /** @return array{disk:?string, key:?string}|null */
    public function financialAttachment(): ?array
    {
        return $this->renewal_receipt_key
            ? ['disk' => $this->renewal_receipt_disk, 'key' => $this->renewal_receipt_key]
            : null;
    }

    public function financialDescription(): string
    {
        $plate = $this->vehicle?->plate_no ?: $this->chasis_no;

        return trim(implode(' — ', array_filter([
            'Registration renewal',
            $plate ? 'Vehicle ' . $plate : null,
            $this->expiry_date ? 'valid to ' . \Illuminate\Support\Carbon::parse($this->expiry_date)->toDateString() : null,
        ])));
    }

    /** A fee, not a catalogue item — one service line. */
    public function financialLines(): array
    {
        return [];
    }
}
