<?php

namespace App\Services;

use App\Exceptions\ReservationConflictException;
use App\Models\Contract;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MaintenanceAnalyticsService;
use App\Services\MaintenanceWorkflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

        // Drop any legacy "Rental-First" override fields a stale client might still send — the block
        // they belonged to was removed by request (see the note below).
        unset($data['override_reason'], $data['override_notes'], $data['override_by_id'], $data['override_by_name']);

        // Only renting is blocked by expired docs / restricted status.
        // Maintenance / transfer / sale_prep are allowed so you can still move the car to renew or dispose it.
        if ($category === 'rent') {
            $this->validation->assertCanRent($vehicle);
        }

        // NOTE: the old "Rental-First" hard-block (no maintenance contract while a car is on a live
        // rental, manager override + audit) was REMOVED by request — we routinely schedule maintenance
        // for cars with upcoming/active bookings, or swap the customer onto another car, and manage the
        // overlap manually. A rental↔maintenance overlap is no longer a block or a violation. The
        // reservation-clash soft block below (force-overridable) is a separate concern and is kept.

        // Don't pull a reserved car into the garage if the maintenance window clashes
        // with a paid reservation — unless the operator explicitly overrides.
        if ($category === 'maintenance' && ! $force) {
            $this->assertNoReservationConflict($vehicle, $data['expected_return_date'] ?? null);
        }

        // Deferred Maintenance: is this rental pulling the car OUT of the workshop early to go to a
        // customer? Detect it (and capture WHY it was in the shop) BEFORE the transaction closes its
        // open maintenance contract below — afterwards there's nothing left to read.
        $pullingFromMaintenance = $category === 'rent'
            && ($vehicle->operational_status === 'maintenance' || $this->vehicleInMaintenance($vehicle->id));
        $deferNote = $pullingFromMaintenance ? $this->deferredMaintenanceNote($vehicle) : null;
        $openedBy  = $data['opened_by'] ?? null;

        return DB::transaction(function () use ($vehicle, $category, $data, $pullingFromMaintenance, $deferNote, $openedBy) {
            // Concurrency guard: lock the vehicle row for the duration of the transaction so two
            // employees acting on the SAME car at the same instant serialize here — the second waits
            // for the first to commit before it reads the contract state below. This mirrors
            // ContractService::store and closes the double-booking hole where this (second) rental
            // path had no lock. (lockForUpdate is an InnoDB row lock; a no-op on SQLite tests.)
            Vehicle::whereKey($vehicle->id)->lockForUpdate()->first();

            // Double-booking HARD BLOCK (inside the lock): never open a second live rental on a car
            // that is already rented. The movement model (step 1) closes prior open contracts, which
            // for a rent-over-rent would SILENTLY close the first customer's active rental and hand
            // the car to a second customer — so we refuse instead of closing it. (Pulling a car OUT of
            // the workshop for a rental stays allowed: that closes an open MAINTENANCE contract, not a
            // rental, and is handled by the deferred-maintenance path below.)
            if ($category === 'rent') {
                $alreadyRented = $vehicle->contracts()
                    ->where('state', 'open')
                    ->whereNull('in_date')
                    ->where('contract_type', self::TYPE_MAP['rent'])
                    ->exists();
                if ($alreadyRented) {
                    throw ValidationException::withMessages([
                        'vehicle_id' => 'This car already has an open rental contract — close the current rental before starting a new one.',
                    ]);
                }
            }

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

            // 4. Deferred Maintenance bookkeeping:
            //    • renting a car straight out of the workshop → flag it to go back afterwards;
            //    • sending a car (back) into the workshop → the debt is settled, clear the flag.
            if ($pullingFromMaintenance) {
                // Pause an in-progress workflow ticket (preserve its full state) instead of losing it, then
                // raise the standing "owes maintenance" flag. Prior contracts were already closed in step 1.
                $this->pauseWorkflowTicketForRental($vehicle, $deferNote, $openedBy);
                $this->flagDeferredMaintenance($vehicle, $deferNote, $openedBy);
            } elseif ($category === 'maintenance') {
                $this->resolveDeferredMaintenance($vehicle);
            }

            return $contract;
        });
    }

    /**
     * Flag a car "owes maintenance": it was pulled out of the workshop early to satisfy a customer,
     * so once it comes back it must be routed straight to the garage. The note carries WHY it was in
     * the shop so the standing reminder explains itself. Idempotent — re-flagging just refreshes it.
     */
    public function flagDeferredMaintenance(Vehicle $vehicle, ?string $note = null, ?string $by = null): void
    {
        $vehicle->forceFill([
            'is_deferred_maintenance'    => true,
            'deferred_maintenance_reason'       => $note,
            'deferred_maintenance_flagged_at' => now(),
            'deferred_maintenance_flagged_by' => $by,
        ])->save();
    }

    /** Clear the deferred-maintenance flag — the car is (back) in the workshop, or a supervisor dismissed it. */
    public function resolveDeferredMaintenance(Vehicle $vehicle): void
    {
        if (! $vehicle->is_deferred_maintenance) {
            return; // nothing to clear — avoid a needless write / audit
        }
        $vehicle->forceFill([
            'is_deferred_maintenance'    => false,
            'deferred_maintenance_reason'       => null,
            'deferred_maintenance_flagged_at' => null,
            'deferred_maintenance_flagged_by' => null,
        ])->save();
    }

    /**
     * Compose a short "why it was in the shop" note from the car's currently-open maintenance
     * ticket / contract, used to explain the flag when the car is pulled out early for a customer.
     * Prefers the maintenance notes, then the issue tags, then the latest workshop-log reason.
     */
    public function deferredMaintenanceNote(Vehicle $vehicle): ?string
    {
        $c = Contract::where('contract_type', 'U')->currentlyOpen()
            ->where('vehicle_id', $vehicle->id)
            ->with('maintenance')
            ->latest('id')->first();

        if ($c && $c->maintenance) {
            if (! empty($c->maintenance->maintenance_notes)) {
                return $c->maintenance->maintenance_notes;
            }
            $tags = $c->maintenance->maintenance_tags;
            if (is_array($tags) && $tags) {
                return implode(', ', $tags);
            }
            if (is_string($tags) && trim($tags) !== '') {
                return $tags;
            }
        }

        // Fall back to the latest hand-entered workshop event's reason.
        $m = Maintenance::query()->where('vehicle_id', $vehicle->id)
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('out_date')
            ->orderByDesc('out_date')->orderByDesc('id')->first();

        return $m ? ($m->service_main ?: $m->maintenance_type) : null;
    }

    /**
     * Move a car straight from the workshop onto a customer rental WITHOUT leaving overlapping open
     * contracts. The flow is [Maintenance] → [Paused / Closed + Flagged] → [Rental]:
     *   • an in-progress WORKFLOW ticket is PAUSED (its full state — stage, findings, parts, photos,
     *     technician assignments, history — is preserved so "Resume Maintenance" continues from the exact
     *     stage; nothing is closed or lost). This replaces the old behaviour of closing the work order;
     *   • any legacy open maintenance (type-U) CONTRACT — which carries no workflow state to preserve — is
     *     still closed so no two contracts sit open at once;
     *   • the standing deferred-maintenance flag is raised and operational_status reflects the rental.
     * Used by the rental-form path (ContractService) where the new rental row is created directly; the
     * live operation path already closes prior contracts inside startOperation(). Idempotent + safe.
     */
    public function deferMaintenanceForRental(Vehicle $vehicle, ?string $openedBy = null): void
    {
        // Read the reason BEFORE anything closes — it explains the flag + the pause.
        $note = $this->deferredMaintenanceNote($vehicle);

        // Pause an in-progress workflow ticket (keeps every bit of its state) instead of losing it.
        $this->pauseWorkflowTicketForRental($vehicle, $note, $openedBy);

        // Close ONLY a legacy maintenance (type-U) contract — no workflow state to keep; the just-created
        // rental stays the single open contract.
        $vehicle->contracts()
            ->where('state', 'open')
            ->where('contract_type', 'U')
            ->update(['state' => 'closed', 'in_date' => now()->toDateString()]);

        $this->flagDeferredMaintenance($vehicle, $note, $openedBy);

        // No maintenance is holding the car anymore → it's now out on rent, not in the shop.
        $vehicle->update(['operational_status' => 'rented']);
    }

    /**
     * Pause the car's open, in-progress maintenance WORKFLOW ticket (if any) because the car is being
     * pulled out for a rental — preserving all of its state so it can resume from the exact stage later.
     * A thin bridge onto MaintenanceWorkflowService::pauseForRental(), resolved lazily via the container
     * to avoid a constructor dependency cycle (the workflow service depends on this one). No-op when the
     * car has no pausable ticket (a legacy type-U contract or a manual garage log carries no workflow
     * state). `$flagVehicle` is false — this service raises the deferred-maintenance flag itself.
     */
    public function pauseWorkflowTicketForRental(Vehicle $vehicle, ?string $note = null, ?string $openedBy = null): ?Maintenance
    {
        $ticket = Maintenance::openWorkflow()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('workflow_status', Maintenance::PAUSABLE_STATES)
            ->latest('id')
            ->first();

        if (! $ticket) {
            return null;
        }

        // Best-effort: attribute the pause to the acting user when we can resolve them by name (the rental
        // form carries opened_by as a display name, not an id). A miss just means a system-attributed pause.
        $actor = $openedBy ? User::where('name', $openedBy)->first() : null;

        return app(MaintenanceWorkflowService::class)->pauseForRental($ticket, $note, $actor, false);
    }

    /**
     * Close an operation: mark the contract closed and free the vehicle.
     */
    public function closeOperation(Contract $contract, array $data = []): Contract
    {
        // Idempotent: a contract already returned (in_date set) or closed is a no-op. Guards against
        // a double-close (double-click / retry / returning an already-closed rental) re-writing the
        // return figures or re-freeing a car that has since moved on to another movement.
        if ($contract->state === 'closed' || $contract->in_date) {
            return $contract;
        }

        // Return integrity: the closing mileage can't precede pickup, nor the return date the out date.
        $inMilage = $data['in_milage'] ?? null;
        if (is_numeric($inMilage) && is_numeric($contract->out_milage) && (int) $inMilage < (int) $contract->out_milage) {
            throw ValidationException::withMessages([
                'in_milage' => "Return mileage ({$inMilage} km) can't be less than the out mileage ({$contract->out_milage} km).",
            ]);
        }
        $inDate = $data['in_date'] ?? null;
        if ($inDate && $contract->out_date) {
            $in  = Carbon::parse((string) $inDate)->startOfDay();
            $out = $contract->out_date->copy()->startOfDay();
            if ($in->lt($out)) {
                throw ValidationException::withMessages([
                    'in_date' => "Return date ({$in->toDateString()}) can't be before the out date ({$out->toDateString()}).",
                ]);
            }
        }

        return DB::transaction(function () use ($contract, $data) {
            $contract->update(array_merge([
                'in_date' => now()->toDateString(),
            ], $data, [
                'state' => 'closed',
            ]));

            // Re-derive from open contracts rather than blindly forcing 'available': if the car is
            // also in the shop (an open type-U ticket), it must stay 'maintenance', not be freed.
            if ($contract->vehicle) {
                $this->reconcileVehicleOperationalStatus($contract->vehicle);
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

        $maintContract = $openCar('U');
        $rent  = array_values(array_diff($openCar('C'), $maintContract));   // a car in the garage isn't "rented"
        $busy  = Contract::currentlyOpen()->whereNotNull('vehicle_id')->distinct()->pluck('vehicle_id')->all();

        // Cars in the garage on a hand-entered workshop event but with NO open contract: a manual
        // OUT/Follow-up/… event keeps the car "In Maintenance" so the sync doesn't reset it to
        // available. ONE definition (manualOnlyGarageVehicleIds) is reused by the dashboard KPI,
        // donut, /maintenance board and foresight, so every surface agrees on the same cars.
        $manualOnly = $this->manualOnlyGarageVehicleIds();

        // Open workflow TICKETS (pending → ready-for-re-inspection) also mean "in maintenance" — the
        // SAME rule the dashboard / board / foresight and the per-vehicle reconcile
        // (vehicleInMaintenance) all use. Omitting them here made the nightly sync force a ticket-only
        // car (no U-contract, no manual event) back to 'available', so it read "Available" fleet-wide
        // while every other surface read "In Maintenance". Include them.
        $ticketCars = Maintenance::openWorkflow()->whereNotNull('vehicle_id')
            ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
            ->distinct()->pluck('vehicle_id')->map(fn ($id) => (int) $id)->all();

        $maint = array_values(array_unique(array_merge($maintContract, $manualOnly, $ticketCars)));

        // Cars out on a Logistics Dispatch (and NOT also under an open contract — a real movement wins
        // over the transit mirror) keep the live "in_transit" status so a sync never resets them.
        $inTransit = array_values(array_diff($this->inTransitVehicleIds(), $busy));

        // A car kept busy ONLY by its garage log, an open workflow ticket, or an active dispatch must
        // not be force-freed in step 1.
        $busyOrGarage = array_values(array_unique(array_merge($busy, $manualOnly, $ticketCars, $inTransit)));

        return DB::transaction(function () use ($maint, $rent, $inTransit, $busyOrGarage) {
            // 1) no open movement at all → available
            $available = Vehicle::whereNotIn('id', $busyOrGarage ?: [0])
                ->where('operational_status', '!=', 'available')
                ->update(['operational_status' => 'available']);

            // 1b) out on a dispatch → in_transit (rented/maintenance below still win if a contract exists)
            $transit = $inTransit
                ? Vehicle::whereIn('id', $inTransit)->where('operational_status', '!=', 'in_transit')
                    ->update(['operational_status' => 'in_transit'])
                : 0;

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
                ->whereIn('operational_status', ['rented', 'maintenance', 'in_transit'])
                ->update(['operational_status' => 'available']);

            // Clear a stale transit mirror left on any car that's no longer on an active dispatch, so the
            // grid never shows "In Transit to …" once the move is done / the car moved on.
            Vehicle::whereNotNull('transit_destination')
                ->when(! empty($inTransit), fn ($q) => $q->whereNotIn('id', $inTransit))
                ->update(['transit_destination' => null]);

            return ['available' => $available, 'in_transit' => $transit, 'rented' => $rented, 'maintenance' => $maintenance];
        });
    }

    /**
     * Re-derive ONE vehicle's operational_status — used as the trigger after a hand-entered
     * workshop event is added / edited / deleted. Precedence is identical to the fleet-wide
     * reconcile, so a manual event and a later sync can never leave the car in two minds:
     *
     *   left the fleet (sold/disposed/returned)        → available
     *   open maintenance contract (type-U)             → maintenance
     *   open rental contract (type-C)                  → rented   (Rental-First: never stomped)
     *   open web movement (test/transfer/sale_prep)    → left as-is (OperationsService owns it)
     *   else, latest manual garage event still open    → maintenance
     *   else                                           → available
     *
     * @return string the resulting operational_status
     */
    public function reconcileVehicleOperationalStatus(Vehicle $vehicle): string
    {
        $vid     = $vehicle->id;
        $openOf  = fn (string $type) => Contract::where('contract_type', $type)
            ->currentlyOpen()->where('vehicle_id', $vid)->exists();
        $hasOpen = Contract::currentlyOpen()->where('vehicle_id', $vid)->exists();

        if (in_array($vehicle->status, self::LEFT_FLEET_STATUSES, true)) {
            $status = 'available';
        } elseif ($this->vehicleInMaintenance($vid)) {
            // Canonical maintenance rule: an open U-contract, an open hand-entered garage event, OR
            // an open workflow ticket. "In the workflow ⇒ in maintenance, period" — it even wins
            // over an open rental, so a rented car sitting in the shop reads the same on every page.
            $status = 'maintenance';
        } elseif ($openOf('C')) {
            $status = 'rented';
        } elseif ($hasOpen) {
            // An open web movement (test / transfer / sale_prep) owns the status — don't override.
            return $vehicle->operational_status;
        } elseif ($this->vehicleInTransit($vid)) {
            $status = 'in_transit';
        } else {
            $status = 'available';
        }

        if ($vehicle->operational_status !== $status) {
            $vehicle->update(['operational_status' => $status]);
        }

        return $status;
    }

    /** Vehicle ids currently out on an open Logistics Dispatch (status 'in_transit'). */
    public function inTransitVehicleIds(): array
    {
        return LogisticsTask::open()->whereNotNull('vehicle_id')
            ->distinct()->pluck('vehicle_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Is this one car out on an open dispatch right now? */
    public function vehicleInTransit(int $vehicleId): bool
    {
        return LogisticsTask::open()->where('vehicle_id', $vehicleId)->exists();
    }

    /**
     * Vehicle ids whose MOST-RECENT hand-entered workshop event is still open (stage ≠ 'IN'),
     * i.e. the car is physically in the garage on a manual log row. Mirrors the board's
     * "latest event wins" rule, so an old OUT left behind a newer IN never counts.
     *
     * @param  array<int>|null  $vehicleIds  limit to these vehicles (null = whole fleet)
     * @return array<int>
     */
    public function manualGarageVehicleIds(?array $vehicleIds = null): array
    {
        return Maintenance::query()
            ->where('origin', Maintenance::ORIGIN_MANUAL)
            ->whereNotNull('vehicle_id')
            ->when($vehicleIds, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
            // keep only each car's latest manual event …
            ->whereRaw('maintenances.id = (
                select m2.id from maintenances m2
                where m2.vehicle_id = maintenances.vehicle_id
                  and m2.origin = ?
                order by m2.out_date desc, m2.id desc
                limit 1
            )', [Maintenance::ORIGIN_MANUAL])
            // … and only if that latest event has NOT come back.
            ->where('event_status', '<>', 'IN')
            ->pluck('vehicle_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Manual-garage cars (latest hand-entered event still open) that have NO open contract of any
     * kind — the set that should count as "In Maintenance" on every surface (dashboard KPI, donut,
     * /maintenance board, foresight exclusion). A car with an open rental/maintenance/web contract
     * is already represented by that contract, so it's excluded here to avoid double-counting and
     * to match the per-vehicle cascade (which only lets a manual event decide when no contract is
     * open).
     *
     * @return array<int>
     */
    public function manualOnlyGarageVehicleIds(): array
    {
        $busy = Contract::currentlyOpen()->whereNotNull('vehicle_id')->distinct()->pluck('vehicle_id')->all();

        return array_values(array_diff($this->manualGarageVehicleIds(), $busy));
    }

    /**
     * THE canonical "in maintenance right now" vehicle set — the single source of truth every
     * surface (dashboard KPI + donut + fleet pulse, the per-vehicle operational_status cascade,
     * and Maintenance Foresight) must agree on. A car is in maintenance if ANY of these is true:
     *   • OfficeManager lifecycle status is `under_maintenance`, or
     *   • it has an OPEN type-U maintenance contract, or
     *   • its latest hand-entered workshop event is still open (manual garage), or
     *   • it has an OPEN workflow TICKET (WF_TICKET_STATES — pending → ready-for-re-inspection).
     * "If it's in the workflow, it's in maintenance — period": an opened ticket counts even before
     * the car is dispatched, so the workflow board can never disagree with the dashboard again.
     *
     * @return array<int>  unique vehicle ids
     */
    public function vehiclesInMaintenance(): array
    {
        return Vehicle::where('status', 'under_maintenance')->pluck('id')
            ->merge(
                Contract::where('contract_type', 'U')->currentlyOpen()
                    ->whereNotNull('vehicle_id')->pluck('vehicle_id')
            )
            ->merge($this->manualGarageVehicleIds())
            ->merge(
                Maintenance::openWorkflow()->whereNotNull('vehicle_id')
                    ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
                    ->pluck('vehicle_id')
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** Is this one car in maintenance right now, per the canonical rule above? */
    public function vehicleInMaintenance(int $vehicleId): bool
    {
        if (Contract::where('contract_type', 'U')->currentlyOpen()->where('vehicle_id', $vehicleId)->exists()) {
            return true;
        }
        if ($this->manualGarageVehicleIds([$vehicleId])) {
            return true;
        }

        if (Maintenance::openWorkflow()->where('vehicle_id', $vehicleId)
            ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)->exists()) {
            return true;
        }

        // Enterprise Handover Workflow: a paused ticket whose vehicle has been marked physically
        // RETURNED (but not yet resumed) is sitting at the garage waiting for the return handover
        // paperwork — block it from being rented out again until that handover clears (or an open
        // incident is acknowledged). See Maintenance::isReturnedPendingHandover().
        return Maintenance::openWorkflow()->where('vehicle_id', $vehicleId)
            ->where('workflow_status', Maintenance::WF_PAUSED_RETURNED_TO_SERVICE)
            ->whereNotNull('vehicle_returned_at')
            ->exists();
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
