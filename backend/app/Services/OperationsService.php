<?php

namespace App\Services;

use App\Exceptions\ReservationConflictException;
use App\Models\Contract;
use App\Models\Vehicle;
use App\Services\MaintenanceAnalyticsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every change of a vehicle's situation (rent, maintenance, test drive, transfer,
 * sale prep) is recorded as a Contract. A car has at most ONE open contract at a time;
 * the vehicle's operational_status mirrors that open contract for fast lookups.
 */
class OperationsService
{
    /** operation category => the vehicle's operational_status while that contract is open */
    public const STATUS_MAP = [
        'rent'        => 'rented',
        'maintenance' => 'maintenance',
        'test_drive'  => 'test',
        'transfer'    => 'transfer',
        'sale_prep'   => 'sale_prep',
    ];

    /** operation category => contract_type (the 3 real types; other movements have none) */
    public const TYPE_MAP = [
        'rent'        => 'C',
        'maintenance' => 'U',
        'test_drive'  => null,
        'transfer'    => null,
        'sale_prep'   => null,
    ];

    public function __construct(protected FleetValidationService $validation)
    {
    }

    /**
     * Start a new operation for a vehicle.
     * Closes any existing open contract, creates a new open contract, and updates
     * the vehicle's operational_status — all in one transaction.
     */
    public function startOperation(Vehicle $vehicle, string $category, array $data = []): Contract
    {
        if (! array_key_exists($category, self::STATUS_MAP)) {
            throw new InvalidArgumentException("Unknown operation category: {$category}");
        }

        // `force` lets an operator knowingly override a soft block (e.g. a reservation clash).
        $force = (bool) ($data['force'] ?? false);
        unset($data['force']);

        // Only renting is blocked by expired docs / restricted status.
        // Maintenance / transfer / sale_prep are allowed so you can still move the car to renew or dispose it.
        if ($category === 'rent') {
            $this->validation->assertCanRent($vehicle);
        }

        // Don't pull a reserved car into the garage if the maintenance window clashes
        // with a paid reservation — unless the operator explicitly overrides.
        if ($category === 'maintenance' && ! $force) {
            $this->assertNoReservationConflict($vehicle, $data['expected_return_date'] ?? null);
        }

        return DB::transaction(function () use ($vehicle, $category, $data) {
            // 1. a car can only be in one place at a time -> close prior open contract(s)
            $this->closeOpenContracts($vehicle);

            // maintenance header fields live on the `maintenances` table, not contracts
            $maint = [];
            foreach (['vendor_id', 'expected_return_date', 'maintenance_tags', 'responsible', 'approved_by', 'maintenance_notes'] as $k) {
                if (array_key_exists($k, $data)) {
                    $maint[$k] = $data[$k];
                    unset($data[$k]);
                }
            }

            // 2. create the new open contract (always linked to the vehicle -> no orphans)
            $contract = $vehicle->contracts()->create(array_merge([
                'out_date'   => now()->toDateString(),
                'out_milage' => $vehicle->odometer,
            ], $data, [
                'contract_type' => self::TYPE_MAP[$category],   // category is derived from this
                'state'         => 'open',
                'origin'        => 'web',
            ]));

            // maintenance visit -> store its garage / expected-return / issues
            if ($category === 'maintenance' && collect($maint)->contains(fn ($v) => $v !== null && $v !== '' && $v !== [])) {
                // classify the situation -> link to the reason vocabulary (sets the priority)
                $reason = app(MaintenanceAnalyticsService::class)
                    ->reasonFor($maint['maintenance_tags'] ?? [], $maint['maintenance_notes'] ?? null);
                $maint['maintenance_reason_id'] = $reason?->id;

                $contract->maintenance()->create($maint);
            }

            // 3. reflect the current movement on the vehicle
            $vehicle->update(['operational_status' => self::STATUS_MAP[$category]]);

            return $contract;
        });
    }

    /**
     * Close an operation: mark the contract closed and free the vehicle.
     */
    public function closeOperation(Contract $contract, array $data = []): Contract
    {
        return DB::transaction(function () use ($contract, $data) {
            $contract->update(array_merge([
                'in_date' => now()->toDateString(),
            ], $data, [
                'state' => 'closed',
            ]));

            if ($contract->vehicle) {
                $contract->vehicle->update(['operational_status' => 'available']);
            }

            return $contract->refresh();
        });
    }

    /**
     * Convenience: move a vehicle to a state, reusing the category->status map.
     * Returns the new open contract.
     */
    public function changeState(Vehicle $vehicle, string $category, array $data = []): Contract
    {
        return $this->startOperation($vehicle, $category, $data);
    }

    /**
     * Block sending a car to maintenance when the maintenance window overlaps an open
     * (paid) reservation. The maintenance window is [today, expected_return_date]; a null
     * expected-return means open-ended (clashes with any upcoming reservation). A
     * reservation with no dates can't be cleared, so it's treated as a conflict too.
     *
     * @throws ReservationConflictException
     */
    protected function assertNoReservationConflict(Vehicle $vehicle, ?string $expectedReturn): void
    {
        $reservations = $vehicle->contracts()
            ->upcomingReservation()
            ->with('customer')
            ->get();

        if ($reservations->isEmpty()) {
            return;
        }

        $maintStart = now()->startOfDay();
        $maintEnd   = $expectedReturn ? Carbon::parse($expectedReturn)->startOfDay() : null;

        $conflicts = [];
        foreach ($reservations as $r) {
            // a booking's reserved window is out_date .. in_date (in_date = planned end)
            $resStart = $r->out_date ? $r->out_date->copy()->startOfDay() : null;
            $resEnd   = $r->in_date
                ? $r->in_date->copy()->startOfDay()
                : (($resStart && $r->days > 0) ? $resStart->copy()->addDays((int) $r->days) : $resStart);

            if (! $resStart) {
                // unknown reservation dates → can't prove it's safe
                $conflicts[] = $this->reservationInfo($r, 'reservation has no dates set');
                continue;
            }

            // windows overlap when maintenance starts on/before the reservation ends
            // AND maintenance ends on/after the reservation starts (open-ended end always overlaps)
            $overlaps = $maintStart->lte($resEnd) && (is_null($maintEnd) || $maintEnd->gte($resStart));
            if ($overlaps) {
                $reason = is_null($maintEnd)
                    ? 'the maintenance is open-ended (no expected return date)'
                    : 'the maintenance runs until ' . $maintEnd->toDateString();
                $conflicts[] = $this->reservationInfo($r, $reason);
            }
        }

        if (! empty($conflicts)) {
            $f = $conflicts[0];
            $msg = "Can't send this car to maintenance — it is reserved ({$f['label']}) and {$f['reason']}. "
                 . 'Reschedule or cancel the reservation, or send anyway to override.';
            throw new ReservationConflictException($msg, $conflicts);
        }
    }

    /** @return array<string,mixed> */
    protected function reservationInfo(Contract $r, string $reason): array
    {
        $window = $r->out_date
            ? $r->out_date->toDateString() . ($r->in_date ? ' → ' . $r->in_date->toDateString() : ($r->days > 0 ? " for {$r->days} day(s)" : ''))
            : 'no date set';

        return [
            'contract_id' => $r->id,
            'contract_no' => $r->contract_no,
            'customer'    => $r->customer?->name_en ?: ($r->customer?->customer_no ? '#' . $r->customer->customer_no : null),
            'window'      => $window,
            'reason'      => $reason,
            'label'       => '#' . ($r->contract_no ?: $r->id) . ', ' . $window,
        ];
    }

    /**
     * Re-derive EVERY vehicle's operational_status from its currently-open contracts
     * (the API only carries a coarse lifecycle StatusNo, so "rented / in maintenance /
     * available right now" can only come from our open contracts). Idempotent — safe to
     * run after every sync. Precedence: maintenance > rented > available.
     *
     * Cars whose open movement is a web operation (test / transfer / sale_prep — those
     * have no contract_type) or a reservation (type 'R', not a physical movement) are
     * left untouched: OperationsService already owns the former, and a reservation
     * doesn't put the car "out". Only available / rented / maintenance are written here.
     *
     * Cars that have LEFT THE FLEET (sold / disposed / returned) never carry live movement,
     * even if an old contract was never closed in OfficeManager — their lifecycle status
     * governs, so they're forced back to 'available' (and never counted as rented/maint).
     *
     * @return array{available:int, rented:int, maintenance:int}
     */
    public const LEFT_FLEET_STATUSES = ['sold', 'disposed', 'returned'];

    public function reconcileAllOperationalStatus(): array
    {
        $openCar = fn (string $type) => Contract::where('contract_type', $type)
            ->currentlyOpen()->whereNotNull('vehicle_id')->distinct()->pluck('vehicle_id')->all();

        $maint = $openCar('U');
        $rent  = array_values(array_diff($openCar('C'), $maint));   // a car in the garage isn't "rented"
        $busy  = Contract::currentlyOpen()->whereNotNull('vehicle_id')->distinct()->pluck('vehicle_id')->all();

        return DB::transaction(function () use ($maint, $rent, $busy) {
            // 1) no open movement at all → available
            $available = Vehicle::whereNotIn('id', $busy ?: [0])
                ->where('operational_status', '!=', 'available')
                ->update(['operational_status' => 'available']);

            // 2) open rental → rented
            $rented = $rent
                ? Vehicle::whereIn('id', $rent)->where('operational_status', '!=', 'rented')
                    ->update(['operational_status' => 'rented'])
                : 0;

            // 3) open maintenance → maintenance (last, so it wins over any stray rental)
            $maintenance = $maint
                ? Vehicle::whereIn('id', $maint)->where('operational_status', '!=', 'maintenance')
                    ->update(['operational_status' => 'maintenance'])
                : 0;

            // 4) cars that have left the fleet never show live movement (lifecycle wins)
            Vehicle::whereIn('status', self::LEFT_FLEET_STATUSES)
                ->whereIn('operational_status', ['rented', 'maintenance'])
                ->update(['operational_status' => 'available']);

            return ['available' => $available, 'rented' => $rented, 'maintenance' => $maintenance];
        });
    }

    /** Close every still-open contract for a vehicle. */
    protected function closeOpenContracts(Vehicle $vehicle): void
    {
        $vehicle->contracts()
            ->where('state', 'open')
            ->update([
                'state'   => 'closed',
                'in_date' => now()->toDateString(),
            ]);
    }
}
