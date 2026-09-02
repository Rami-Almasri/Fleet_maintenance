<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to a warranty or a claim goes through here.
 *
 * Evidence class: F (fact capture) with one D (derived) output — the frozen window verdict on a
 * claim. Produces: warranties, warranty_claims. Consumes: part_purchases, vehicle_components,
 * maintenance_tasks, component_catalog (defaults only), vehicles (odometer).
 *
 * The service exists because three rules must hold no matter which endpoint or job writes a row,
 * and none of them can be expressed as a column constraint:
 *
 *  1. THE ANCHOR MUST MATCH THE KIND. A part warranty is worthless without something to point at
 *     (the purchase or the fitted component); a repair warranty is worthless without the FAULT,
 *     because a comeback is only provable as "the same fault again". The database allows all four
 *     anchors to be null — it has to, since any one of them can legitimately be absent — so the
 *     rule lives here.
 *
 *  2. THE CAR MUST AGREE WITH THE ANCHOR. A warranty whose vehicle_id contradicts its purchase is
 *     silently unenforceable: you would go to the supplier with the wrong car. Rather than trust
 *     the caller, the vehicle is DERIVED from the anchor whenever the anchor knows it.
 *
 *  3. A CLAIM'S WINDOW VERDICT IS FROZEN AT CLAIM TIME. Computed against the odometer of the day
 *     the failure happened, then written down and never recomputed. See fileClaim().
 */
class WarrantyService
{
    /**
     * The vehicle timeline. A warranty being recorded, corrected or destroyed is a fact about the
     * CAR, and it belongs on the car's history next to everything else that changed what we may do
     * with it — not only in an audit table nobody browses. Best-effort inside VehicleLogService, so
     * a logging failure can never sink the write it is describing.
     */
    public function __construct(private \App\Services\VehicleLogService $log) {}

    /**
     * Record a warranty someone was given.
     *
     * @param  array $data validated payload (see StoreWarrantyRequest)
     * @throws ValidationException when the anchor does not match the kind
     */
    public function create(array $data, ?User $actor = null): Warranty
    {
        return DB::transaction(function () use ($data, $actor) {
            $data = $this->resolveAnchors($data);
            $this->assertAnchorMatchesKind($data);

            $data['status'] = $data['status'] ?? Warranty::STATUS_ACTIVE;
            $data['subject'] = $data['subject'] ?: $this->describeSubject($data);

            $warranty = new Warranty($data);
            $warranty->created_by      = $actor?->id;
            $warranty->created_by_name = $actor?->name;
            $warranty->save();

            $this->audit($warranty, \App\Models\VehicleLogEvent::EVENT_WARRANTY_RECORDED, $actor,
                "Warranty recorded — {$warranty->subject}"
                . ($warranty->provider_name ? " ({$warranty->provider_name})" : ''));

            return $warranty->fresh();
        });
    }

    /**
     * Edit the promise. The anchors and the kind are NOT editable: re-pointing a warranty at a
     * different part or a different fault is not a correction, it is a different warranty, and
     * allowing it would silently rewrite the history of whatever it used to cover.
     */
    public function update(Warranty $warranty, array $data, ?User $actor = null): Warranty
    {
        unset(
            $data['kind'], $data['vehicle_id'],
            $data['part_purchase_id'], $data['vehicle_component_id'],
            $data['maintenance_task_id'], $data['maintenance_id'],
        );

        $warranty->fill($data);
        $warranty->updated_by      = $actor?->id;
        $warranty->updated_by_name = $actor?->name;
        $warranty->save();

        $this->audit($warranty, \App\Models\VehicleLogEvent::EVENT_WARRANTY_UPDATED, $actor,
            "Warranty updated — {$warranty->subject}");

        return $warranty->fresh();
    }

    /**
     * Kill a warranty that was destroyed before it ran out — an unauthorised repair, the wrong
     * fluid, a supplier who walked away. A reason is mandatory: "void" without a why is an
     * accusation nobody can check later.
     */
    public function void(Warranty $warranty, string $reason, ?User $actor = null): Warranty
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'void_reason' => 'Say why the warranty no longer stands — a void with no reason cannot be defended later.',
            ]);
        }

        $warranty->status          = Warranty::STATUS_VOID;
        $warranty->void_reason     = trim($reason);
        $warranty->updated_by      = $actor?->id;
        $warranty->updated_by_name = $actor?->name;
        $warranty->save();

        $this->audit($warranty, \App\Models\VehicleLogEvent::EVENT_WARRANTY_VOIDED, $actor,
            "Warranty voided — {$warranty->subject}: " . trim($reason));

        return $warranty->fresh();
    }

    /** Undo a void (it was raised in error). Never used to "un-expire" — expiry is not a state. */
    public function reinstate(Warranty $warranty, ?User $actor = null): Warranty
    {
        $warranty->status          = Warranty::STATUS_ACTIVE;
        $warranty->void_reason     = null;
        $warranty->updated_by      = $actor?->id;
        $warranty->updated_by_name = $actor?->name;
        $warranty->save();

        return $warranty->fresh();
    }

    /**
     * Build a part warranty from a purchase, pre-filled from the catalog's defaults.
     *
     * The defaults are a STARTING POINT copied at this moment, never a live link: changing the
     * catalog later must not silently rewrite a promise a supplier already made. Anything the
     * caller passes wins over the default.
     */
    public function createFromPurchase(PartPurchase $purchase, array $overrides = [], ?User $actor = null): Warranty
    {
        $catalog = $this->catalogForPurchase($purchase);

        $data = array_merge([
            'kind'                 => Warranty::KIND_PART,
            'vehicle_id'           => $purchase->vehicle_id,
            'part_purchase_id'     => $purchase->id,
            'component_catalog_id' => $catalog?->id,
            'subject'              => $purchase->part_name,
            'provider_vendor_id'   => $purchase->source_vendor_id,
            'provider_name'        => $purchase->source_name,
            // The clock starts when the part went ON the car, not when it was bought — a part that
            // sat in a drawer for two months did not spend that time failing.
            'starts_on'            => optional($purchase->installed_at ?? $purchase->purchased_at)->toDateString()
                                        ?? now()->toDateString(),
            'start_odometer'       => $purchase->installed_odometer,
            'duration_months'      => $catalog?->default_warranty_months,
            'duration_km'          => $catalog?->default_warranty_km,
        ], array_filter($overrides, fn ($v) => $v !== null));

        return $this->create($data, $actor);
    }

    /**
     * Build a repair warranty for a fault the garage says it fixed.
     *
     * There is no catalog default here on purpose: a repair warranty is what the GARAGE agreed to,
     * not a property of the part. Whoever dispatched the job types what was agreed.
     */
    public function createForRepair(MaintenanceTask $task, array $data, ?User $actor = null): Warranty
    {
        return $this->create(array_merge([
            'kind'                => Warranty::KIND_REPAIR,
            'maintenance_task_id' => $task->id,
            'maintenance_id'      => $task->maintenance_id,
            'vehicle_id'          => $task->maintenance?->vehicle_id,
            'subject'             => $task->symptom ?: 'Repair',
            'starts_on'           => now()->toDateString(),
        ], $data), $actor);
    }

    /**
     * File a claim, freezing whether the warranty was actually live WHEN THE FAILURE HAPPENED.
     *
     * This is the method the whole feature exists for. The verdict is computed once, at the date
     * and odometer of the failure, and stored — because the answer must not drift as the car keeps
     * being driven. Filing a claim outside the window is ALLOWED and recorded as such: the fleet
     * often argues a borderline case, and a claim we lost on distance is exactly the evidence
     * needed to negotiate the next contract.
     */
    public function fileClaim(Warranty $warranty, array $data, ?User $actor = null): WarrantyClaim
    {
        return DB::transaction(function () use ($warranty, $data, $actor) {
            $claimedOn = $data['claimed_on'] ?? now()->toDateString();

            // Prefer the reading given with the claim; fall back to the car's current odometer so a
            // distance-bounded warranty is still judged rather than silently reported as unknown.
            $odometer = $data['claim_odometer'] ?? $warranty->vehicle?->odometer;

            $verdict = $warranty->evaluate($claimedOn, $odometer !== null ? (int) $odometer : null);

            $claim = new WarrantyClaim(array_merge($data, [
                'warranty_id'     => $warranty->id,
                'vehicle_id'      => $warranty->vehicle_id,
                'claimed_on'      => $claimedOn,
                'claim_odometer'  => $odometer,
                // FROZEN. Never recomputed on read.
                'was_in_window'   => $verdict['state'] === Warranty::STATE_ACTIVE,
                'window_evidence' => $verdict['evidence'],
                'outcome'         => $data['outcome'] ?? WarrantyClaim::OUTCOME_PENDING,
            ]));
            $claim->created_by      = $actor?->id;
            $claim->created_by_name = $actor?->name;
            $claim->save();

            return $claim->fresh();
        });
    }

    /**
     * Record the counterparty's answer.
     *
     * A rejection MUST carry their reason. That sentence is the entire value of a lost claim: it is
     * what turns "they refused" into "they refuse corrosion claims after 6 months", which is
     * something the next contract can be written against.
     */
    public function resolveClaim(WarrantyClaim $claim, array $data, ?User $actor = null): WarrantyClaim
    {
        $outcome = $data['outcome'];

        if ($outcome === WarrantyClaim::OUTCOME_REJECTED && trim((string) ($data['outcome_reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'outcome_reason' => 'Record the reason they gave for refusing — a refusal with no reason teaches us nothing.',
            ]);
        }

        // Money only exists where something was actually recovered. A rejected claim carrying an
        // amount would inflate every "recovered from suppliers" total that ever reads this table.
        if (in_array($outcome, [WarrantyClaim::OUTCOME_REJECTED], true)) {
            $data['recovered_amount'] = null;
            $data['remedy']           = 'none';
        }

        $claim->fill($data);
        $claim->resolved_on = $data['resolved_on'] ?? now()->toDateString();
        $claim->save();

        return $claim->fresh();
    }

    // ── reads ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Everything covering one car right now, each with its live verdict judged against the car's
     * CURRENT odometer — the reading that decides the distance leg.
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        return Warranty::with(['catalog:id,name,name_ar', 'provider:id,name', 'claims'])
            ->forVehicle($vehicle->id)
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (Warranty $w) => [
                'warranty' => $w,
                'verdict'  => $w->evaluate(null, $vehicle->odometer !== null ? (int) $vehicle->odometer : null),
            ])
            ->all();
    }

    /**
     * Is this fault already under a live repair warranty? The comeback check.
     *
     * Answers "the car is back with the same fault — is the garage still on the hook?" and is the
     * reason maintenance_task_id exists on the table at all.
     */
    public function liveRepairCoverFor(MaintenanceTask $task, ?int $odometer = null): ?Warranty
    {
        $odometer ??= $task->maintenance?->vehicle?->odometer;

        return Warranty::ofKind(Warranty::KIND_REPAIR)
            ->where('maintenance_task_id', $task->id)
            ->where('status', Warranty::STATUS_ACTIVE)
            ->get()
            ->first(fn (Warranty $w) => $w->isLive($odometer !== null ? (int) $odometer : null));
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────────

    /**
     * Rule 2: the car is DERIVED from the anchor whenever the anchor knows it, rather than trusted
     * from the caller. A warranty pointing at the wrong car is unenforceable and looks fine.
     */
    private function resolveAnchors(array $data): array
    {
        if (! empty($data['part_purchase_id'])) {
            $purchase = PartPurchase::find($data['part_purchase_id']);
            if ($purchase) {
                $data['vehicle_id'] = $purchase->vehicle_id;
            }
        }

        if (! empty($data['vehicle_component_id'])) {
            $component = VehicleComponent::find($data['vehicle_component_id']);
            if ($component) {
                $data['vehicle_id']           = $component->vehicle_id;
                $data['component_catalog_id'] = $data['component_catalog_id'] ?? $component->component_catalog_id;
            }
        }

        if (! empty($data['maintenance_task_id'])) {
            $task = MaintenanceTask::with('maintenance')->find($data['maintenance_task_id']);
            if ($task) {
                $data['maintenance_id'] = $data['maintenance_id'] ?? $task->maintenance_id;
                if ($task->maintenance?->vehicle_id) {
                    $data['vehicle_id'] = $task->maintenance->vehicle_id;
                }
            }
        }

        return $data;
    }

    /** Rule 1: a warranty with no anchor for its kind cannot be enforced against anybody. */
    private function assertAnchorMatchesKind(array $data): void
    {
        $kind = $data['kind'] ?? null;

        if ($kind === Warranty::KIND_PART
            && empty($data['part_purchase_id'])
            && empty($data['vehicle_component_id'])) {
            throw ValidationException::withMessages([
                'part_purchase_id' => 'A part warranty must point at the purchase or the fitted component it covers.',
            ]);
        }

        /**
         * kind=vehicle has NO anchor beyond the car, and that is the whole point of it rather than an
         * omission. A manufacturer's warranty is a promise about the vehicle itself — it exists
         * before anything has been bought, fitted or repaired, which is exactly why it is the only
         * kind that can stop a purchase before it happens. Requiring an anchor here would have made
         * it impossible to record the promise a car arrives with.
         *
         * The vehicle_id check below still applies to it, and is the only rule it needs.
         */
        if ($kind === Warranty::KIND_REPAIR
            && empty($data['maintenance_task_id'])
            && empty($data['maintenance_id'])) {
            throw ValidationException::withMessages([
                'maintenance_task_id' => 'A repair warranty must point at the fault (or at least the ticket) it covers — otherwise a comeback cannot be matched to it.',
            ]);
        }

        if (empty($data['vehicle_id'])) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'A warranty must belong to a car.',
            ]);
        }
    }

    /** Best-effort catalog match for a purchase, by name — the identity this codebase already uses. */
    private function catalogForPurchase(PartPurchase $purchase): ?ComponentCatalog
    {
        if (! $purchase->part_name) {
            return null;
        }

        return ComponentCatalog::active()
            ->where('name', $purchase->part_name)
            ->first();
    }

    /**
     * One row on the car's timeline. Best-effort and deliberately silent on failure: recording a
     * warranty must not fail because the history table did.
     *
     * The `meta` payload carries the structured facts (kind, provider, both expiry legs) so the
     * timeline can render a warranty change without joining back to a row that may since have been
     * edited — the same discipline every other event on that trail follows.
     */
    private function audit(Warranty $warranty, string $event, ?User $actor, string $description): void
    {
        if (! $warranty->vehicle) {
            return;
        }

        $this->log->recordVehicle($warranty->vehicle, $event, $actor, [
            'source_tag'  => 'warranty',
            'description' => $description,
            'meta'        => [
                'warranty_id'     => $warranty->id,
                'kind'            => $warranty->kind,
                'subject'         => $warranty->subject,
                'provider_kind'   => $warranty->provider_kind,
                'provider_name'   => $warranty->provider_name,
                'reference_no'    => $warranty->reference_no,
                'starts_on'       => $warranty->starts_on?->toDateString(),
                'expires_on'      => $warranty->expires_on?->toDateString(),
                'expires_at_km'   => $warranty->expires_at_km,
                'status'          => $warranty->status,
            ],
        ]);
    }

    private function describeSubject(array $data): string
    {
        if (! empty($data['component_catalog_id'])) {
            $c = ComponentCatalog::find($data['component_catalog_id']);
            if ($c) {
                return $c->name;
            }
        }

        return match ($data['kind'] ?? null) {
            Warranty::KIND_REPAIR  => 'Repair',
            // The default sentence for a whole-car promise names the counterparty, because "Vehicle
            // warranty" on its own tells a reader nothing they did not already know from the car.
            Warranty::KIND_VEHICLE => trim(($data['provider_name'] ?? '') . ' vehicle warranty') ?: 'Vehicle warranty',
            default                => 'Part',
        };
    }
}
