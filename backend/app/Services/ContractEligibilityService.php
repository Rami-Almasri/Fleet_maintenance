<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\InspectionRecord;
use App\Models\Maintenance;
use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;

/**
 * Contract Eligibility — the SINGLE authority for "may this vehicle go onto a rental / booking
 * contract right now?". It replaces the fragmented condition-only guard (which missed vehicle status
 * entirely, so a Sold / Under-Maintenance / already-Rented car sailed through) with one checklist.
 *
 * Design: a PURE classifier core, assess(array $facts), holds every rule and is fully unit-testable
 * with no DB (see ContractEligibilityServiceTest). evaluate(Vehicle) gathers the facts from the
 * database and delegates to it. assertEligibleForContract(array $data) is the store()-time gate that
 * turns the verdict into a 422 (hard block) / a manager-override requirement / an orange
 * acknowledgement, and returns the audit columns to stamp on the contract.
 *
 * ADD A NEW RULE in ONE place: write a private *Check() method returning check(), and add it to the
 * $checks array in assess(). Its severity (block / manager_override / warn / pass) does the rest.
 */
class ContractEligibilityService
{
    // Check severities. A single BLOCK makes the vehicle ineligible; MANAGER_OVERRIDE is a soft block
    // a manager may clear; WARN is advisory (surfaced, never stops the contract); PASS is clean.
    public const PASS             = 'pass';
    public const BLOCK            = 'block';
    public const WARN             = 'warn';
    public const MANAGER_OVERRIDE = 'manager_override';

    /**
     * Vehicle lifecycle statuses (`vehicles.status`) that make a car ineligible for a NEW rental /
     * booking. Only 'ready' rents; everything else — in the shop, sold, disposed, suspended, office
     * use, returned, or already rented — is blocked. Extend this list to add more blocking states.
     */
    public const BLOCKING_STATUSES = [
        'under_maintenance', 'out_of_order', 'sold', 'disposed', 'suspended', 'returned', 'office_use', 'rented',
    ];

    /** Live movement states (`vehicles.operational_status`) that also block — in the shop / on the road. */
    public const BLOCKING_OPERATIONAL_STATUSES = ['under_maintenance', 'in_transit'];

    /**
     * Do expired insurance / registration HARD-BLOCK a rental? OFF by design: the F-Insurance / F-RTA
     * sync is broadly stale (most cars read "expired" when they aren't), so blocking would false-ground
     * the fleet. It stays a loud WARNING until that sync's freshness is confirmed — flip this to true
     * to promote it. Mirrors VehicleReadinessService::EXPIRY_BLOCKS.
     */
    public const DOCUMENTS_BLOCK = false;

    private const EXPIRY_WARN_DAYS = 14;

    /**
     * The pure checklist. Feed it a fact struct, get a per-check verdict + the block / override / warn
     * buckets. No DB, no side effects — this is the whole rulebook and the unit-test surface.
     *
     * @param array{status?:?string, operational_status?:?string, condition_grade?:?string,
     *   cleaning_status?:?string, open_maintenance?:bool, open_rental?:bool, open_damage?:int,
     *   insurance_days_left?:?int, registration_days_left?:?int} $f
     */
    public function assess(array $f): array
    {
        $checks = [
            $this->statusCheck($f),
            $this->rentalCheck($f),
            $this->maintenanceCheck($f),
            $this->accidentCheck($f),
            $this->conditionCheck($f),
            $this->inspectionCheck($f),
            $this->cleaningCheck($f),
            $this->documentCheck('insurance', 'Insurance', $f['insurance_days_left'] ?? null),
            $this->documentCheck('registration', 'Registration (Mulkiya)', $f['registration_days_left'] ?? null),
        ];

        $line = fn (array $c) => ['key' => $c['key'], 'label' => $c['label'], 'detail' => $c['detail']];
        $of   = fn (string $sev) => array_values(array_map($line, array_filter($checks, fn ($c) => $c['status'] === $sev)));

        $blocks = $of(self::BLOCK);

        return [
            'eligible'         => empty($blocks),
            'requires_manager' => ! empty($of(self::MANAGER_OVERRIDE)),
            'checks'           => $checks,
            'blocks'           => $blocks,
            'overridable'      => $of(self::MANAGER_OVERRIDE),
            'warnings'         => $of(self::WARN),
        ];
    }

    /**
     * Gather one vehicle's facts from the DB and run the checklist. $intent carries operator-supplied
     * signals that aren't facts about the car — currently `pull_from_maintenance` (a deliberate,
     * permission-gated decision to pull an in-shop car out for a customer, closing its ticket now).
     */
    public function evaluate(Vehicle $vehicle, array $intent = []): array
    {
        return $this->assess(array_merge($this->gatherFacts($vehicle), $intent));
    }

    /**
     * The strict yes/no: may a STANDARD user (no manager override in hand) put this vehicle on a new
     * rental / booking right now? Returns false for ANY hard block — a Maintenance / Sold / Inactive
     * lifecycle status, an open maintenance work order, or a pending/unreviewed damage inspection —
     * and also false for a Yellow-graded car, which is rentable only via a manager override
     * (assertEligibleForContract handles that override path). Orange (cosmetic) stays rentable, so it
     * returns true here; the handover acknowledgement is enforced at the store() gate, not by this check.
     */
    public function canRent(Vehicle $vehicle): bool
    {
        $r = $this->evaluate($vehicle);

        return $r['eligible'] && ! $r['requires_manager'];
    }

    /**
     * The store()-time gate. Resolves the vehicle for a Rental (C) or Booking (R), runs the checklist,
     * and enforces it: any hard block → 422; Yellow → a manager override is required; Orange → a
     * customer acknowledgement is required. Returns the acknowledgement / override columns to persist.
     *
     * @return array<string,mixed>
     */
    public function assertEligibleForContract(array $data): array
    {
        $type = $data['contract_type'] ?? null;
        if (! in_array($type, ['C', 'R'], true) || empty($data['vehicle_id'])) {
            return [];
        }

        $vehicle = Vehicle::find($data['vehicle_id']);
        if (! $vehicle) {
            return [];
        }

        // `pull_from_maintenance` is the deliberate "release this car from the shop for a customer"
        // intent (forwarded by the controller only for an authorised user). It lifts the two
        // maintenance-related blocks — the ticket is closed as part of this very rental — but leaves
        // every genuine conflict (rented / sold / Red / open damage / dirty) standing.
        $result = $this->evaluate($vehicle, [
            'pull_from_maintenance' => ! empty($data['pull_from_maintenance']),
        ]);

        // Hard blocks — status, open maintenance, Red grade, open damage, dirty. Never overridable.
        if (! empty($result['blocks'])) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'This vehicle cannot be rented or booked — '
                    . implode('; ', array_map(fn ($b) => $b['detail'], $result['blocks'])) . '.',
            ]);
        }

        // Yellow — a manager-overridable block. The controller only forwards manager_override when the
        // acting user holds operations.override, so its presence here means an authorised approval.
        if ($result['requires_manager']) {
            if (empty($data['manager_override'])) {
                throw ValidationException::withMessages([
                    'manager_override' => 'This vehicle is graded Yellow (maintenance needed). A manager override is required to rent or book it.',
                ]);
            }

            return [
                'condition_ack_grade' => $vehicle->condition_grade,
                'condition_ack_note'  => 'Manager override (Yellow): ' . (trim((string) ($data['override_reason'] ?? '')) ?: 'no reason given'),
                'condition_ack_by'    => $data['override_by'] ?? $data['opened_by'] ?? null,
                'condition_ack_at'    => now(),
            ];
        }

        // Orange — bookable, but the sales agent must confirm the customer was told (a warning, recorded).
        if ($vehicle->requiresConditionAcknowledgement()) {
            if (empty($data['condition_acknowledged'])) {
                throw ValidationException::withMessages([
                    'condition_ack' => sprintf(
                        'This vehicle is graded %s (%s). Confirm the customer was informed of its condition before handover.',
                        ucfirst($vehicle->condition_grade),
                        Vehicle::CONDITION_LABELS[$vehicle->condition_grade] ?? $vehicle->condition_grade,
                    ),
                ]);
            }

            return [
                'condition_ack_grade' => $vehicle->condition_grade,
                'condition_ack_note'  => $vehicle->condition_note,
                'condition_ack_by'    => $data['condition_ack_by'] ?? $data['opened_by'] ?? null,
                'condition_ack_at'    => now(),
            ];
        }

        return [];
    }

    /** Resolve a vehicle's live facts for the checklist. Reuses the app's canonical "in the shop" rules. */
    public function gatherFacts(Vehicle $vehicle): array
    {
        // The governing committed maintenance ticket (if any). We fetch the row itself — not just a
        // boolean — so we can read its Rental Eligibility flag: a ticket the inspector marked NON-deferrable
        // is MANDATORY maintenance and can never be pulled out for a rental (see maintenanceCheck()).
        $ticket = Maintenance::openWorkflow()->where('vehicle_id', $vehicle->id)
            ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)->latest('id')->first();
        $openTicket = (bool) $ticket;
        // Mandatory only when a COMMITTED ticket exists and its inspector marked it non-deferrable. Legacy
        // type-U contracts / hand-entered garage events carry no such decision, so they stay pull-able
        // (their behaviour is unchanged) — only an explicitly-mandatory workflow ticket hard-grounds the car.
        $mandatoryMaintenance = $openTicket && ! $ticket->deferrable_for_rental;
        $openU = Contract::where('contract_type', 'U')->currentlyOpen()->where('vehicle_id', $vehicle->id)->exists();
        // THE double-booking guard: is there already an open RENTAL (type-C) contract on this car?
        // This is the authoritative overlap check — it reads the actual open contract, so it holds
        // even when the drift-prone OM `vehicles.status` hasn't caught up yet (the exact window that
        // used to let a freshly-rented car be rented again). type-U (maintenance) is handled separately.
        $openRental = Contract::where('contract_type', 'C')->currentlyOpen()->where('vehicle_id', $vehicle->id)->exists();
        // A hand-entered garage event still open (not marked returned) — the old guard missed these.
        $openManual = Maintenance::where('origin', Maintenance::ORIGIN_MANUAL)->where('vehicle_id', $vehicle->id)
            ->where('event_status', '<>', 'IN')->exists();

        $openDamage = InspectionRecord::where('vehicle_id', $vehicle->id)
            ->where('damage_flagged', true)->whereNull('review_outcome')->count();

        // Accident cases whose stage still means the CAR is compromised — not merely that its money
        // is unsettled. WHICH stages those are is now the office's answer, read from the published
        // workflow rather than a constant. @see \App\Models\AccidentCase::rentalBlockingKeys()
        $accidents = \App\Models\AccidentCase::forVehicle($vehicle->id)->rentalBlocking()
            ->orderBy('id')->get(['id', 'reference', 'stage']);

        $reg = $vehicle->relationLoaded('registration') ? $vehicle->registration : $vehicle->registration()->first();

        return [
            'status'                 => $vehicle->status,
            'operational_status'     => $vehicle->operational_status,
            'condition_grade'        => $vehicle->condition_grade,
            'cleaning_status'        => $vehicle->cleaning_status,
            'open_maintenance'       => $openTicket || $openU || $openManual,
            // MANDATORY maintenance (deferrable_for_rental = false on the open committed ticket): a hard,
            // non-overridable block — not even pull_from_maintenance releases it.
            'maintenance_mandatory'  => $mandatoryMaintenance,
            'open_rental'            => $openRental,
            'open_damage'            => $openDamage,
            // Unresolved accidents holding the car out of the pool. The count AND a sentence naming
            // the case, because "this vehicle cannot be rented" with no reference is a dead end for
            // whoever is standing at the counter with a customer.
            'open_accidents'         => $accidents->count(),
            'accident_detail'        => $accidents->isEmpty() ? null : sprintf(
                'Accident case %s is still open (%s) — the vehicle cannot be rented until it is resolved',
                $accidents->first()->reference,
                str_replace('_', ' ', $accidents->first()->stage),
            ),
            'insurance_days_left'    => $reg?->insurance_days_left,
            'registration_days_left' => $reg?->registration_days_left,
        ];
    }

    // ── Individual checks (each returns check(): key, label, status, detail) ──────────────────────

    /** THE fix: the vehicle's lifecycle / live status must be rentable (only 'ready' is). */
    private function statusCheck(array $f): array
    {
        $status = $f['status'] ?? null;
        $op     = $f['operational_status'] ?? null;

        $blockedBy = null;
        if ($status !== null && in_array($status, self::BLOCKING_STATUSES, true)) {
            $blockedBy = $status;
        } elseif ($op !== null && in_array($op, self::BLOCKING_OPERATIONAL_STATUSES, true)) {
            $blockedBy = $op;
        }

        // Deferred-maintenance intent releases ONLY the maintenance lifecycle block (the ticket is
        // paused as part of this rental); real conflicts (rented / sold / …) still stand. A MANDATORY
        // ticket is never released this way — its lifecycle block holds even with a pull intent.
        if ($blockedBy === 'under_maintenance' && ! empty($f['pull_from_maintenance']) && empty($f['maintenance_mandatory'])) {
            $blockedBy = null;
        }

        return $this->check(
            'status', 'Vehicle availability',
            $blockedBy ? self::BLOCK : self::PASS,
            $blockedBy ? 'Vehicle is ' . str_replace('_', ' ', $blockedBy) . ' — not available to rent' : 'Available (Ready)',
        );
    }

    /**
     * THE double-booking block: a car already out on an open rental (type-C) contract can't be
     * rented or booked again. Authoritative and drift-proof — it reads the open contract itself,
     * not the cached `vehicles.status`, so a rental created seconds ago on this same car (even
     * before any sync/reconcile) still blocks a second one. Never overridable.
     */
    private function rentalCheck(array $f): array
    {
        $open = ! empty($f['open_rental']);
        return $this->check(
            'rental', 'Existing rental',
            $open ? self::BLOCK : self::PASS,
            $open ? 'An open rental contract is already active on this vehicle' : 'No active rental',
        );
    }

    /**
     * AN UNRESOLVED ACCIDENT. A hard block, and NOT lifted by `pull_from_maintenance`.
     *
     * This is the rule that keeps requirement "a car with an open accident must never appear
     * available" honest, and it lives here rather than as a new `operational_status` because THIS is
     * the single authority for "may this vehicle go onto a rental contract right now". A status
     * enum has a dozen readers and would have to be taught the same thing a dozen times; this
     * checklist has one caller and one meaning.
     *
     * The pull intent does not release it, deliberately. That intent exists so a car can be taken out
     * of the workshop for a customer with the repair paused — a commercial trade-off about scheduling.
     * An accident case still at "nobody has looked at the damage yet" is not a scheduling question,
     * and the person clicking "pull from maintenance" has no way to know what is wrong with the car.
     *
     * It is scoped to RENTAL_BLOCKING_STAGES, not to every open case: a case sitting at `settlement`
     * is an argument about money with a repaired car standing in the yard, and grounding that would
     * cost real rental days over an insurer's paperwork. @see \App\Models\AccidentCase
     */
    private function accidentCheck(array $f): array
    {
        $open = (int) ($f['open_accidents'] ?? 0);

        if ($open === 0) {
            return $this->check('accident', 'Accident hold', self::PASS, 'No unresolved accident');
        }

        return $this->check(
            'accident', 'Accident hold', self::BLOCK,
            $f['accident_detail'] ?? 'An unresolved accident case is open on this vehicle',
        );
    }

    /** An open work order (workflow ticket, type-U contract, or hand-entered garage event). */
    private function maintenanceCheck(array $f): array
    {
        $open = ! empty($f['open_maintenance']);
        if (! $open) {
            return $this->check('maintenance', 'Open maintenance', self::PASS, 'No open maintenance');
        }

        // MANDATORY maintenance — the inspector marked this ticket non-deferrable at the Decide step. The
        // car is grounded until the workshop completes it; this is a HARD block that pull_from_maintenance
        // does NOT lift (the whole point of the flag). Rental eligibility was decided once, and it said no.
        if (! empty($f['maintenance_mandatory'])) {
            return $this->check(
                'maintenance', 'Open maintenance', self::BLOCK,
                'Mandatory maintenance in progress — this vehicle cannot be rented until the workshop completes it',
            );
        }

        // Deferrable (or legacy) work order + the pull-from-maintenance intent: the operator is deliberately
        // pulling the car out for a customer, pausing the ticket as part of this rental — so it no longer blocks.
        if (! empty($f['pull_from_maintenance'])) {
            return $this->check(
                'maintenance', 'Open maintenance', self::PASS,
                'Open work order — will be paused and the car released for this rental (maintenance resumes on return)',
            );
        }

        // Deferrable, but no pull intent supplied yet: still a block, but one the rental flow may clear by
        // offering the pause/pull action (it re-submits with pull_from_maintenance).
        return $this->check(
            'maintenance', 'Open maintenance', self::BLOCK,
            'An open maintenance work order is active on this vehicle',
        );
    }

    /** Visual condition grade: Red blocks, Yellow needs a manager override, Orange warns (ack). */
    private function conditionCheck(array $f): array
    {
        return match ($f['condition_grade'] ?? 'green') {
            'red'    => $this->check('condition', 'Condition grade', self::BLOCK, 'Graded Red — grounded'),
            'yellow' => $this->check('condition', 'Condition grade', self::MANAGER_OVERRIDE, 'Graded Yellow — needs a manager override'),
            'orange' => $this->check('condition', 'Condition grade', self::WARN, 'Graded Orange — needs a handover acknowledgement'),
            default  => $this->check('condition', 'Condition grade', self::PASS, 'Green — clean'),
        };
    }

    /** Unreviewed damage flags from inspection. */
    private function inspectionCheck(array $f): array
    {
        $n = (int) ($f['open_damage'] ?? 0);
        return $this->check(
            'inspection', 'Damage review',
            $n > 0 ? self::BLOCK : self::PASS,
            $n > 0 ? $n . ' unreviewed damage flag' . ($n === 1 ? '' : 's') . ' awaiting review' : 'No open damage flags',
        );
    }

    /** Cleaning: an explicit "dirty" blocks; unknown / not-yet-assessed warns; "clean" passes. */
    private function cleaningCheck(array $f): array
    {
        return match ($f['cleaning_status'] ?? null) {
            'dirty' => $this->check('cleaning', 'Cleaning', self::BLOCK, 'Marked dirty — needs cleaning before handover'),
            'clean' => $this->check('cleaning', 'Cleaning', self::PASS, 'Clean'),
            default => $this->check('cleaning', 'Cleaning', self::WARN, 'Not yet assessed'),
        };
    }

    /** Shared expiry verdict (insurance / registration). Warn-only unless DOCUMENTS_BLOCK is on. */
    private function documentCheck(string $key, string $label, ?int $daysLeft): array
    {
        if ($daysLeft === null) {
            return $this->check($key, $label, self::WARN, 'No expiry on record');
        }
        if ($daysLeft < 0) {
            return $this->check($key, $label, self::DOCUMENTS_BLOCK ? self::BLOCK : self::WARN, 'Expired ' . abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' ago');
        }
        if ($daysLeft <= self::EXPIRY_WARN_DAYS) {
            return $this->check($key, $label, self::WARN, 'Expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'));
        }
        return $this->check($key, $label, self::PASS, 'Valid (' . $daysLeft . ' days left)');
    }

    private function check(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }
}
