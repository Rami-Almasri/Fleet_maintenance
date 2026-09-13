<?php

namespace App\Services\Accident;

use App\Models\AccidentCase;
use App\Models\Contract;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * WHO HAD THE CAR WHEN IT CRASHED — answered once, at the moment of reporting, and then frozen.
 *
 * ── WHY THIS IS A SEPARATE CLASS AND WHY IT ONLY EVER RUNS ONCE ────────────────────────────────
 *
 * The naive version of this feature reads "who has the car" off the vehicle whenever the accident
 * page is opened. That works perfectly for about six hours. Then the rental closes, the car is
 * cleaned and re-let the same afternoon, and the page starts telling an insurer that the accident
 * happened while a customer who first saw the car on Thursday was driving it.
 *
 * So this resolver runs ONCE, at intake, and everything it finds is COPIED onto the case (see the
 * *_snapshot columns). Afterwards the case answers from itself. The live foreign keys stay, because
 * a working link to a contract that still exists is genuinely useful — but they are the convenience,
 * not the record.
 *
 * ── IT SEARCHES BY TIME, NOT BY "NOW" ──────────────────────────────────────────────────────────
 *
 * An accident is very often reported hours or days after it happened — the driver limped home, told
 * somebody on Monday. So the lookup is anchored to `occurred_at`, not to the clock: the contract that
 * COVERED that instant, not the one that is open now. When the two differ, the older one is the true
 * answer and the newer one is the trap.
 *
 * ── IT NEVER GUESSES ───────────────────────────────────────────────────────────────────────────
 *
 * Every answer is either a row it found or `unknown`. There is no date-proximity matching, no
 * "probably the last renter", no confidence score — the same rule the Damage & Accidents log was
 * rebuilt under. A detected context is stamped `context_detected = true`; a stated one is not, and
 * the page says which it is. Whoever is reporting may always overrule it, and their answer wins,
 * because they were there and the database was not.
 */
class AccidentContextResolver
{
    /**
     * Work out who was responsible for a car at a moment, and return the frozen column set.
     *
     * @param  string|null  $stated  a responsible-party type the reporter chose by hand. When given,
     *                               it OVERRULES detection for the party TYPE — but any contract
     *                               found is still attached, because "the customer's additional
     *                               driver was at the wheel" is one answer with two facts in it.
     * @return array<string,mixed> columns ready to assign onto an AccidentCase
     */
    public function resolve(Vehicle $vehicle, ?Carbon $at, ?string $stated = null): array
    {
        $at ??= Carbon::now();

        $rental = $this->rentalCoveringMoment($vehicle->id, $at);
        $detected = $rental !== null;

        // The order below IS the precedence, and the rental wins outright. A car on hire that is also
        // sitting on an open workshop ticket (a paused repair released back to the customer) belongs
        // to the customer for accident purposes: they were driving it.
        $type = $stated ?: match (true) {
            $rental !== null                              => AccidentCase::PARTY_RENTAL_CUSTOMER,
            $this->onOpenMaintenance($vehicle->id)        => AccidentCase::PARTY_WORKSHOP,
            $this->onOpenLogisticsMove($vehicle->id)      => AccidentCase::PARTY_LOGISTICS,
            // Nothing found. NOT "parked" — that would be an inference dressed as a fact, and a car
            // with no open record is just as likely to have been out on an unlogged errand.
            default                                       => AccidentCase::PARTY_UNKNOWN,
        };

        $customer = $rental?->customer;

        return [
            'responsible_party_type'     => $type,
            'context_detected'           => $detected,
            'contract_id'                => $rental?->id,
            'contract_ref'               => $rental?->id,
            'contract_no_snapshot'       => $rental?->contract_no,
            'contract_state_snapshot'    => $rental?->state,
            'contract_out_date_snapshot' => $rental?->out_date,
            'contract_in_date_snapshot'  => $rental?->in_date,
            'customer_id'                => $customer?->id,
            'customer_ref'               => $customer?->id,
            'customer_name_snapshot'     => $customer?->name_en ?: $customer?->name_ar,
            'customer_phone_snapshot'    => $customer?->mobile1 ?: $customer?->whatsapp,
            // Everything the resolver saw, kept whole. Columns cover the questions we know to ask;
            // this covers the one somebody asks in two years.
            'context_snapshot'           => [
                'resolved_at'      => Carbon::now()->toIso8601String(),
                'anchored_on'      => $at->toIso8601String(),
                'stated_by_user'   => $stated,
                'detected_type'    => $detected ? AccidentCase::PARTY_RENTAL_CUSTOMER : null,
                'vehicle_status'   => $vehicle->status,
                'operational'      => $vehicle->operational_status,
                'open_maintenance' => $this->onOpenMaintenance($vehicle->id),
                'open_movement'    => $this->onOpenLogisticsMove($vehicle->id),
                'contract'         => $rental ? [
                    'id'            => $rental->id,
                    'contract_no'   => $rental->contract_no,
                    'contract_type' => $rental->contract_type,
                    'state'         => $rental->state,
                    'out_date'      => optional($rental->out_date)->toDateString(),
                    'in_date'       => optional($rental->in_date)->toDateString(),
                    'out_milage'    => $rental->out_milage,
                    'driver_out'    => $rental->driver_out,
                ] : null,
                'customer'         => $customer ? [
                    'id'          => $customer->id,
                    'customer_no' => $customer->customer_no,
                    'name_en'     => $customer->name_en,
                    'name_ar'     => $customer->name_ar,
                    'mobile1'     => $customer->mobile1,
                    'license_no'  => $customer->license_no,
                ] : null,
            ],
        ];
    }

    /**
     * The RENTAL contract that covered a given instant. Type 'C' only — a type 'U' is the car's own
     * maintenance visit and a type 'R' is a reservation nobody has collected, and calling either of
     * them "the customer who had the car" would be a fabrication.
     *
     * Open-ended contracts (no `in_date`) count for any moment at or after pickup, which is the
     * common case for a car that is still out. Closed ones must bracket the moment.
     */
    private function rentalCoveringMoment(int $vehicleId, Carbon $at): ?Contract
    {
        return Contract::query()
            ->with('customer')
            ->where('vehicle_id', $vehicleId)
            ->where('contract_type', 'C')
            ->whereDate('out_date', '<=', $at->toDateString())
            ->where(function ($q) use ($at) {
                $q->whereNull('in_date')->orWhereDate('in_date', '>=', $at->toDateString());
            })
            // Newest pickup first: a car let twice in one day belongs to the later contract at the
            // later moment, and both satisfy the window above.
            ->orderByDesc('out_date')
            ->orderByDesc('id')
            ->first();
    }

    /** Is the car sitting on a committed workshop ticket right now? */
    private function onOpenMaintenance(int $vehicleId): bool
    {
        return Maintenance::query()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
            ->exists();
    }

    /** Is a driver moving it for us on an open dispatch? */
    private function onOpenLogisticsMove(int $vehicleId): bool
    {
        return LogisticsTask::open()->where('vehicle_id', $vehicleId)->exists();
    }
}
