<?php

namespace App\Models;

use App\Observers\ContractObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(ContractObserver::class)]
class Contract extends Model
{
    /** @use HasFactory<\Database\Factories\ContractFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contract_no',
        'contract_type',
        'state',
        'vehicle_id',
        'customer_id',
        'parent_contract_id',
        'exchange_linked_at',
        'exchange_linked_by',
        'carried_balance',
        'day_price',
        'week_price',
        'month_price',
        'hour_price',
        'year_price',
        'out_date',
        'out_time',
        'out_milage',
        'out_fuel',
        'opened_by',
        'in_date',
        'in_time',
        'in_milage',
        'in_fuel',
        'closed_by',
        'days',
        'km',
        'rents_debit',
        'breachs_debit',
        'salik_debit',
        'damages_debit',
        'extra_charges_debit',
        'co_driver_debit',
        'km_debit',
        'fuel_debit',
        'gps_debit',
        'cdw_debit',
        'extra_driver_debit',
        'vat_debit',
        'deposit_debit',
        'rents_credit',
        'breachs_credit',
        'salik_credit',
        'damages_credit',
        'extra_charges_credit',
        'co_driver_credit',
        'km_credit',
        'fuel_credit',
        'gps_credit',
        'cdw_credit',
        'extra_driver_credit',
        'vat_credit',
        'deposit_credit',
        'contract_debit',
        'contract_credit',
        'contract_balance',
        'contract_refunds',
        'contract_discount',
        'contract_bad_debts',
        'contract_deposit',
        'contract_commissions',
        'contract_income',
        'miles_allowed_pd',
        'miles_allowed_pm',
        'extra_mile_charge',
        'cdw_rate',
        'pai_rate',
        'authorization_amount',
        'insurance_type',
        'trip_direction',
        'under_claim',
        'guarantor_no',
        'contract_status_no',
        'reference',
        'contract_serial',
        'car_serial',
        'driver2',
        'driver3',
        'driver_out',
        'driver_in',
        'co_driver_cost',
        'extra_driver_charge',
        'gps_charge',
        'fuel_charge',
        'ra_vat_percentage',
        'salesman_commission_no1',
        'salesman_commission_value1',
        'salesman_commission_no2',
        'salesman_commission_value2',
        'tax_inclusive',
        'cdw_on_contract',
        'credit_card_no',
        'credit_card_expiry',
        'authorization_date',
        'out_date_hijri',
        'in_date_hijri',
        'remarks',
        'sales_man1',
        'sales_man2',
        'source',
        'external_id',
        'synced_at',
        'origin',
    ];

    protected $casts = [
        'out_date'           => 'date',
        'in_date'            => 'date',
        'authorization_date' => 'date',
        'under_claim'        => 'boolean',
        'tax_inclusive'      => 'boolean',
        'cdw_on_contract'    => 'boolean',
        'synced_at'          => 'datetime',
        'exchange_linked_at' => 'datetime',
    ];

    /**
     * Category is derived from contract_type (the single source of truth) rather
     * than stored: C = rent · U = maintenance · R = booking (reserved + deposit paid).
     */
    protected function category(): Attribute
    {
        return Attribute::make(
            get: fn () => match ($this->contract_type) {
                'C' => 'rent',
                'U' => 'maintenance',
                'R' => 'booking',
                default => null,
            },
        );
    }

    /**
     * "Currently out" — an open contract whose car has NOT come back yet. An `in_date`
     * means the car was returned even if the row was never flipped to 'closed', so we
     * treat in_date as the source of truth for "returned". Use this anywhere we mean
     * "is the car physically out / in maintenance right now".
     */
    public function scopeCurrentlyOpen(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('state', 'open')->whereNull('in_date');
    }

    /**
     * An active/upcoming reservation: a booking (type 'R') whose reserved window has
     * NOT ended yet. Bookings store the reserved period in out_date..in_date, so
     * (unlike rentals) a set in_date is the planned END of the reservation, not an
     * actual return — hence we can't use currentlyOpen() here.
     */
    public function scopeUpcomingReservation(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('contract_type', 'R')
            ->where(function ($q) {
                $q->whereNull('in_date')->orWhereDate('in_date', '>=', now()->toDateString());
            });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** Maintenance header (garage, issues, due, responsible) — only for type 'U'. */
    public function maintenance(): HasOne
    {
        return $this->hasOne(Maintenance::class);
    }

    /** Invoice-style maintenance line items (service + cost + notes). */
    public function items(): HasMany
    {
        return $this->hasMany(MaintenanceItem::class);
    }

    /** Invoices (charges) billed on this contract, from the OfficeManager API. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // ---- Exchange chaining ---------------------------------------------------

    /** The contract this one replaced (the car the customer returned to swap). NULL = chain root. */
    public function parentContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'parent_contract_id');
    }

    /** Direct successors — contracts that swapped IN off the back of this one. */
    public function childContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'parent_contract_id');
    }

    /** True when this contract is part of an exchange chain (has a parent or any child). */
    public function isExchanged(): bool
    {
        return $this->parent_contract_id !== null || $this->childContracts()->exists();
    }

    /**
     * The whole exchange chain this contract belongs to, oldest → newest, including self.
     * Walks up to the root then back down through each single successor. Capped at 50 hops
     * as a cycle/runaway guard (a chain should realistically be a handful of swaps).
     */
    public function exchangeChain(): \Illuminate\Support\Collection
    {
        // climb to the root
        $root = $this;
        $seen = [$root->id => true];
        while ($root->parent_contract_id && ($parent = $root->parentContract()->first())) {
            if (isset($seen[$parent->id]) || count($seen) >= 50) {
                break;
            }
            $seen[$parent->id] = true;
            $root = $parent;
        }

        // descend collecting each successor
        $chain = collect([$root]);
        $cursor = $root;
        $guard = [$root->id => true];
        while ($next = $cursor->childContracts()->orderBy('out_date')->orderBy('id')->first()) {
            if (isset($guard[$next->id]) || $chain->count() >= 50) {
                break;
            }
            $guard[$next->id] = true;
            $chain->push($next);
            $cursor = $next;
        }

        return $chain;
    }

    /**
     * Pickup / return as real timestamps by folding the separate *_time columns into the
     * date. OfficeManager stores out_date/in_date at midnight and the clock time in
     * out_time/in_time ("22:30:00"), so neither alone is enough to measure a sub-day gap.
     */
    protected function outAt(): Attribute
    {
        return Attribute::make(get: fn () => $this->combine($this->out_date, $this->out_time));
    }

    protected function inAt(): Attribute
    {
        return Attribute::make(get: fn () => $this->combine($this->in_date, $this->in_time));
    }

    private function combine($date, ?string $time): ?\Illuminate\Support\Carbon
    {
        if (! $date) {
            return null;
        }
        $d = $date instanceof \Illuminate\Support\Carbon ? $date->copy() : \Illuminate\Support\Carbon::parse($date);

        return ($time && preg_match('/^\d{1,2}:\d{2}/', $time))
            ? \Illuminate\Support\Carbon::parse($d->toDateString() . ' ' . $time)
            : $d->startOfDay();
    }
}
