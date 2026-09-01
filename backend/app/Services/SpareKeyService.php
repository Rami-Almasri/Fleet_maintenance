<?php

namespace App\Services;

use App\Http\Resources\VehicleResource;
use App\Models\ComponentCatalog;
use App\Models\PartRequest;
use App\Models\SpareKeyRequirement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Models\VehicleLogEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The spare-key lifecycle, end to end — and almost none of it is new machinery.
 *
 * WHAT THIS SERVICE ACTUALLY DOES is join three things that already existed and were never wired
 * together for a part nobody fits at a garage:
 *
 *   the NEED   → {@see SpareKeyRequirement}, the one genuinely new record (see its migration for why)
 *   the BUY    → {@see PartWorkflowService}, untouched: the same request → approve → purchase chain,
 *                the same approval gate, the same duplicate engine, the same audit stamps
 *   the KEY    → {@see ComponentService}, the sole writer of the asset ledger, through a receipt door
 *                added for numbered sets
 *
 * The only step that is genuinely different from a brake pad is RECEIPT: a key is not fitted, it is
 * handed over, so `receive()` marks the purchase delivered and registers the physical keys in ONE
 * transaction. Never one without the other — a purchase that says "received" with no key behind it is
 * the exact failure this method exists to make impossible.
 */
class SpareKeyService
{
    public function __construct(
        private PartWorkflowService $parts,
        private ComponentService $components,
        private SpareKeyProjection $projection,
        private NotificationScanner $notifier,
        private VehicleLogService $log,
    ) {}

    // ───────────────────────────── 1. the need ─────────────────────────────

    /**
     * Record that a car needs a spare key, and tell the supervisors.
     *
     * The duplicate guard is the UNIQUE INDEX on `open_vehicle_id`, not a select-then-insert: two
     * people clicking the button at the same moment must not both create a requirement, and only the
     * database can promise that. A collision is reported as the plain fact it is — there is already
     * an open requirement — rather than as a constraint error.
     *
     * @param array $data reason_code?, quantity?, notes?
     */
    public function raise(Vehicle $vehicle, array $data, User $actor): SpareKeyRequirement
    {
        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        try {
            $requirement = DB::transaction(function () use ($vehicle, $data, $actor, $quantity) {
                $row = new SpareKeyRequirement();
                $row->fill([
                    'vehicle_id'        => $vehicle->id,
                    'quantity'          => $quantity,
                    'reason_code'       => in_array($data['reason_code'] ?? null, SpareKeyRequirement::REASONS, true)
                        ? $data['reason_code']
                        : SpareKeyRequirement::REASON_MISSING,
                    'notes'             => $data['notes'] ?? null,
                    'source'            => SpareKeyRequirement::SOURCE_APP,
                    'requested_by'      => $actor->id,
                    'requested_by_name' => $actor->name ?: $actor->email,
                    'requested_at'      => Carbon::now(),
                ]);
                $row->status          = SpareKeyRequirement::STATUS_REQUIRED;
                $row->open_vehicle_id = $vehicle->id;
                $row->save();

                return $row;
            });
        } catch (QueryException $e) {
            abort_if($this->isOpenLockCollision($e), 409,
                "{$vehicle->plate_no} already has an open spare-key requirement. Raise the quantity on that one instead of opening a second.");

            throw $e;
        }

        $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_SPARE_KEY_REQUIRED, $actor, [
            'source_tag'  => 'parts',
            'description' => $quantity > 1
                ? "Spare key required — {$quantity} keys ({$requirement->reason_code})"
                : "Spare key required ({$requirement->reason_code})",
            'meta'        => [
                'spare_key_requirement_id' => $requirement->id,
                'quantity'                 => $quantity,
                'reason_code'              => $requirement->reason_code,
                'requested_by_name'        => $requirement->requested_by_name,
            ],
        ]);

        $this->notifyRecipients($requirement, $vehicle, $actor);

        return $requirement->fresh();
    }

    /** Withdraw a need — the key turned up, the car left the fleet, it was raised by mistake. */
    public function cancel(SpareKeyRequirement $requirement, User $actor, string $reason): SpareKeyRequirement
    {
        abort_unless($requirement->isOpen(), 409,
            "This requirement is already {$requirement->status} — there is nothing to cancel.");
        abort_if((int) $requirement->received_quantity > 0, 409,
            'Keys have already been received against this requirement. Complete it, or remove the key from the vehicle instead.');

        $requirement->forceFill([
            'status'            => SpareKeyRequirement::STATUS_CANCELLED,
            'open_vehicle_id'   => null,
            'cancelled_by'      => $actor->id,
            'cancelled_by_name' => $actor->name ?: $actor->email,
            'cancelled_at'      => Carbon::now(),
            'cancel_reason'     => $reason,
        ])->save();

        if ($vehicle = $requirement->vehicle) {
            $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_SPARE_KEY_CANCELLED, $actor, [
                'source_tag'  => 'parts',
                'description' => "Spare key requirement cancelled — {$reason}",
                'meta'        => ['spare_key_requirement_id' => $requirement->id, 'reason' => $reason],
            ]);
        }

        return $requirement->fresh();
    }

    // ───────────────────────────── 2. the buy ─────────────────────────────

    /**
     * Turn a need into a purchase request — the EXISTING one. From here the spare key travels the
     * standard parts board: approve / reject, buy, and the duplicate engine watches it like any part.
     *
     * It goes through PartWorkflowService::createRequest rather than through PartRequestController,
     * because that controller requires a maintenance ticket ("a part is ALWAYS requested against a
     * ticket") and a spare key has none. The SERVICE has never required one — vehicle_id is enough —
     * so this is the same write path with the ticket-shaped door left unopened, not a bypass of it.
     *
     * @param array $data quantity?, estimated_price?, currency?, notes?
     */
    public function createPurchaseRequest(SpareKeyRequirement $requirement, array $data, User $actor): PartRequest
    {
        abort_unless($requirement->isOpen(), 409,
            "This requirement is {$requirement->status} — a purchase request cannot be raised against it.");

        $live = $requirement->livePurchaseRequest();
        abort_if($live !== null, 409,
            "Purchase request #{$live?->id} is already open for this requirement ({$live?->status}).");

        $outstanding = $requirement->outstandingQuantity();
        abort_if($outstanding < 1, 409, 'Every key on this requirement has already been received.');

        $quantity = max(1, min($outstanding, (int) ($data['quantity'] ?? $outstanding)));
        $catalog  = $this->catalog();
        $vehicle  = $requirement->vehicle;

        $request = DB::transaction(function () use ($requirement, $data, $actor, $quantity, $catalog) {
            $req = $this->parts->createRequest([
                // Our own diagnosis that the car is short a key, not a customer walk-in.
                'source'               => PartRequest::SOURCE_GARAGE,
                'vehicle_id'           => $requirement->vehicle_id,
                'part_name'            => $catalog->name,
                'component_catalog_id' => $catalog->id,
                'category_key'         => $catalog->category_key,
                'quantity'             => $quantity,
                // The reason a spare key is bought is the requirement, and it says so in words a
                // buyer reading the board can act on without opening anything.
                'reason'               => $this->reasonSentence($requirement),
                'estimated_price'      => $data['estimated_price'] ?? null,
                'currency'             => $data['currency'] ?? 'AED',
                'notes'                => $data['notes'] ?? $requirement->notes,
            ], $actor);

            // The traceability link — set after creation so PartWorkflowService keeps its single
            // signature and stays unaware of spare keys.
            $req->forceFill(['spare_key_requirement_id' => $requirement->id])->save();

            $this->projection->sync($requirement);

            return $req->fresh();
        });

        if ($vehicle) {
            $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_SPARE_KEY_PURCHASE_REQUESTED, $actor, [
                'source_tag'  => 'parts',
                'description' => "Spare key purchase request #{$request->id} raised — {$quantity} key(s)",
                'meta'        => [
                    'spare_key_requirement_id' => $requirement->id,
                    'part_request_id'          => $request->id,
                    'quantity'                 => $quantity,
                ],
            ]);
        }

        $this->notifyApprovers($requirement, $request, $vehicle, $actor);

        return $request;
    }

    // ───────────────────────────── 3. the key ─────────────────────────────

    /**
     * The key arrived. Mark the purchase delivered AND register the physical keys, transactionally.
     *
     * THE TWO FAILURES THIS SHAPE RULES OUT, which are the whole reason it is one method:
     *   · "delivered" with no component — the car's configuration silently never learns it has a key;
     *   · a component with the purchase still awaiting receipt — an asset from nowhere.
     * Both were possible while the two steps were separate calls, and neither is now: they commit
     * together or not at all.
     *
     * IDEMPOTENT. Submitting twice registers nothing the second time — ComponentService::receiveSetUnits
     * creates only the shortfall against what this purchase has already produced, and the delivery
     * stamp is skipped when it is already set instead of raising the 409 markDelivered would.
     * A double-clicked "Mark Received" is a user accident, not a business event.
     *
     * @param array $data quantity?, serial_no?, brand?, model?, note?
     * @return array{requirement: SpareKeyRequirement, components: array<int, VehicleComponent>}
     */
    public function receive(SpareKeyRequirement $requirement, array $data, User $actor): array
    {
        $request = $requirement->livePurchaseRequest();
        abort_if($request === null, 409,
            'There is no open purchase request on this requirement — raise one and have it approved before receiving a key.');

        $purchase = $request->purchases()->orderByDesc('id')->first();
        // The order gate: a key cannot arrive before it was bought. Approval alone is permission to
        // spend, not evidence that anything was ordered.
        abort_if($purchase === null, 409,
            "Purchase request #{$request->id} has no purchase recorded against it yet — record the buy first.");

        $vehicle = $requirement->vehicle;
        abort_if($vehicle === null, 409, 'This requirement has no vehicle.');

        $catalog  = $this->catalog();
        $quantity = max(1, min(
            $requirement->outstandingQuantity() ?: 1,
            (int) ($data['quantity'] ?? $purchase->quantity ?? 1)
        ));

        [$components, $newlyDelivered] = DB::transaction(function () use ($purchase, $catalog, $vehicle, $quantity, $data, $actor) {
            $wasPending = $purchase->delivered_at === null;

            if ($wasPending) {
                $this->parts->markDelivered($purchase, $actor);
            }

            $units = $this->components->receiveSetUnits(
                $purchase->fresh(),
                $catalog,
                $vehicle,
                $quantity,
                [
                    'serial_no' => $data['serial_no'] ?? null,
                    'brand'     => $data['brand'] ?? null,
                    'model'     => $data['model'] ?? null,
                    'note'      => isset($data['note']) ? ' — ' . $data['note'] : null,
                ],
                $actor,
            );

            return [$units, $wasPending];
        });

        $requirement = $this->projection->sync($requirement);

        if ($newlyDelivered) {
            $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_SPARE_KEY_RECEIVED, $actor, [
                'source_tag'  => 'parts',
                'description' => "Spare key received — {$quantity} key(s) now belong to this vehicle",
                'meta'        => [
                    'spare_key_requirement_id' => $requirement->id,
                    'part_request_id'          => $request->id,
                    'part_purchase_id'         => $purchase->id,
                    'component_ids'            => array_map(fn (VehicleComponent $c) => $c->id, $components),
                    'quantity'                 => $quantity,
                ],
            ]);

            $this->notifyReceived($requirement, $vehicle, $actor);
        }

        return ['requirement' => $requirement, 'components' => $components];
    }

    // ───────────────────────────── read surfaces ─────────────────────────────

    /**
     * One car's spare-key picture — and the distinction the whole feature turns on:
     *
     *   `current`  how many keys the car HAS. Active components, counted today.
     *   `history`  how many were ever ASKED FOR and ever ARRIVED. Requirements and receipts, forever.
     *
     * These are different numbers on any car that has lost a key, and reporting either as if it were
     * the other is the thing this method exists to prevent. `retired` is the reconciling figure: keys
     * that did arrive and are no longer here.
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        $catalog = ComponentCatalog::where('slug', SpareKeyRequirement::CATALOG_SLUG)->first();

        $keys = $catalog
            ? VehicleComponent::query()
                ->trusted()
                ->with(['sourcePurchase:id,part_request_id,purchase_price,currency,source_vendor_id,purchased_at', 'supplier:id,name'])
                ->where('component_catalog_id', $catalog->id)
                ->where('vehicle_id', $vehicle->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
            : collect();

        $active  = $keys->where('status', VehicleComponent::STATUS_ACTIVE);
        $retired = $keys->where('status', VehicleComponent::STATUS_RETIRED);

        $requirements = SpareKeyRequirement::query()
            ->forVehicle($vehicle->id)
            ->with(['purchaseRequests.purchases.sourceVendor:id,name', 'requester:id,name'])
            ->orderByDesc('id')
            ->get();

        return [
            'vehicle' => [
                'id'            => $vehicle->id,
                'plate_no'      => $vehicle->plate_no,
                'plate_display' => VehicleResource::plateDisplay($vehicle->plate_code, $vehicle->plate_no),
                'car'           => trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: null,
            ],
            'current' => [
                'count' => $active->count(),
                'keys'  => $active->map(fn (VehicleComponent $c) => $this->presentKey($c))->values()->all(),
            ],
            'history' => [
                // The three counts a manager compares. They are allowed to disagree, and when they do
                // the difference is `retired` — which is why it is printed rather than left implicit.
                'requirements_raised' => $requirements->count(),
                'keys_ever_received'  => $keys->count(),
                'keys_retired'        => $retired->count(),
                'retired_keys'        => $retired->map(fn (VehicleComponent $c) => $this->presentKey($c))->values()->all(),
                'requirements'        => $requirements->map(fn (SpareKeyRequirement $r) => $this->presentRequirement($r))->values()->all(),
            ],
            'open_requirement' => $requirements->firstWhere(fn (SpareKeyRequirement $r) => $r->isOpen())?->id,
            'max_keys'         => $catalog ? count($catalog->positionsFor()) : 0,
            'data_origin'      => 'Spare-key requirements (spare_key_requirements) raised in the app or imported from the '
                . '"NEED SPARE KEY" sheet, their purchase requests (part_requests) and purchases (part_purchases), and the '
                . 'physical keys those produced (vehicle_components). Nothing on this panel is counted twice: "current" is '
                . 'the asset ledger today, "history" is every requirement ever raised.',
        ];
    }

    /**
     * The operations board: how many requirements sit at each stage, and the rows behind each count.
     *
     * Every headline is openable, per the house rule that a number nobody can drill into is a claim
     * rather than a report.
     */
    public function board(?string $status = null, int $limit = 200): array
    {
        $counts = SpareKeyRequirement::query()
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $rows = SpareKeyRequirement::query()
            ->with(['vehicle:id,plate_no,plate_code,make,model,year,color', 'purchaseRequests.purchases', 'requester:id,name'])
            ->when($status, fn ($q) => $q->whereIn('status', explode(',', $status)))
            ->orderByRaw('FIELD(status, ' . implode(',', array_fill(0, count(SpareKeyRequirement::BOARD_ORDER), '?')) . ')',
                SpareKeyRequirement::BOARD_ORDER)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'counts' => collect(SpareKeyRequirement::BOARD_ORDER)
                ->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])
                ->all(),
            'open_total' => (int) collect(SpareKeyRequirement::OPEN_STATUSES)->sum(fn ($s) => (int) ($counts[$s] ?? 0)),
            'rows'       => $rows->map(fn (SpareKeyRequirement $r) => $this->presentRequirement($r, withVehicle: true))->values()->all(),
            'data_origin' => 'spare_key_requirements, with each row\'s stage derived from its purchase requests, purchases '
                . 'and the physical keys they produced. Rejected requirements stay OPEN — a refused buy ends a purchase, not a need.',
        ];
    }

    // ───────────────────────────── presenters ─────────────────────────────

    private function presentKey(VehicleComponent $key): array
    {
        return [
            'id'            => $key->id,
            'slot'          => $key->position,
            'label'         => $key->label,
            'serial_no'     => $key->serial_no,
            'brand'         => $key->brand,
            'status'        => $key->status,
            'received_at'   => optional($key->installed_at)->toDateString(),
            /**
             * HOW WE KNOW this key exists — the difference between a key this system watched arrive
             * and one we were simply told about. A backfilled row has no date, no supplier and no
             * price, and the UI must say why rather than render three empty cells.
             */
            'from_register' => $key->source === VehicleComponent::SOURCE_LEGACY_BACKFILL,
            'removed_at'    => optional($key->removed_at)->toDateString(),
            'removal_reason' => $key->removal_reason,
            'cost'          => $key->purchase_cost === null ? null : (float) $key->purchase_cost,
            'currency'      => $key->currency,
            'supplier'      => $key->supplier?->name,
            'part_purchase_id' => $key->source_part_purchase_id,
        ];
    }

    private function presentRequirement(SpareKeyRequirement $r, bool $withVehicle = false): array
    {
        $live     = $r->livePurchaseRequest();
        $purchase = $live?->purchases->sortByDesc('id')->first();

        $row = [
            'id'                => $r->id,
            'vehicle_id'        => $r->vehicle_id,
            'status'            => $r->status,
            'is_open'           => $r->isOpen(),
            'quantity'          => (int) $r->quantity,
            'received_quantity' => (int) $r->received_quantity,
            'outstanding'       => $r->outstandingQuantity(),
            'reason_code'       => $r->reason_code,
            'notes'             => $r->notes,
            'source'            => $r->source,
            'requested_by_name' => $r->requested_by_name,
            'requested_at'      => optional($r->requested_at)->toDateTimeString(),
            'started_on'        => optional($r->started_on)->toDateString(),
            'finished_on'       => optional($r->finished_on)->toDateString(),
            'completed_at'      => optional($r->completed_at)->toDateTimeString(),
            'cancelled_at'      => optional($r->cancelled_at)->toDateTimeString(),
            'cancel_reason'     => $r->cancel_reason,
            // The procurement chain, flattened for a table row — WHY this buy exists is answered in
            // the other direction by part_requests.spare_key_requirement_id.
            'purchase_request'  => $live ? [
                'id'               => $live->id,
                'status'           => $live->status,
                'quantity'         => (float) ($live->quantity ?: 1),
                'estimated_price'  => $live->estimated_price === null ? null : (float) $live->estimated_price,
                'currency'         => $live->currency,
                'requested_by_name' => $live->requested_by_name,
                'approved_by_name' => $live->approved_by_name,
                'approved_at'      => optional($live->approved_at)->toDateTimeString(),
            ] : null,
            // Every attempt, including the refused ones — a rejection is part of this need's story.
            'rejected_requests' => $r->purchaseRequests
                ->where('status', PartRequest::STATUS_REJECTED)
                ->map(fn (PartRequest $q) => [
                    'id'               => $q->id,
                    'rejected_by_name' => $q->rejected_by_name,
                    'rejected_at'      => optional($q->rejected_at)->toDateTimeString(),
                    'reason'           => $q->rejection_reason,
                ])->values()->all(),
            'purchase' => $purchase ? [
                'id'           => $purchase->id,
                'price'        => (float) $purchase->purchase_price,
                'currency'     => $purchase->currency,
                'quantity'     => (float) ($purchase->quantity ?: 1),
                'supplier'     => $purchase->sourceVendor?->name ?: $purchase->source_name,
                'purchased_at' => optional($purchase->purchased_at)->toDateTimeString(),
                'delivered_at' => optional($purchase->delivered_at)->toDateTimeString(),
            ] : null,
        ];

        if ($withVehicle && $r->vehicle) {
            $row['vehicle'] = [
                'plate_no'      => $r->vehicle->plate_no,
                'plate_display' => VehicleResource::plateDisplay($r->vehicle->plate_code, $r->vehicle->plate_no),
                'car'           => trim(($r->vehicle->make ?? '') . ' ' . ($r->vehicle->model ?? '')) ?: null,
                'year'          => $r->vehicle->year,
                'color'         => $r->vehicle->color,
            ];
        }

        return $row;
    }

    // ───────────────────────────── notifications ─────────────────────────────

    /**
     * WHO is told a car needs a key: the configured allow-list, else holders of the fallback
     * permission NARROWED to the operational roles and stripped of the excluded ones.
     *
     * The narrowing is the point. `parts.request` on its own also matches the inspector, both
     * drivers, the rental desk and five QA logins — twelve people for a job the two supervisors own,
     * which is how a fleet teaches itself to ignore the bell. Returning an EMPTY collection is a
     * legitimate outcome; the requirement is still raised and still on the board, and an audience
     * nobody configured is an assignment problem, not something to paper over by telling everyone.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        $ids = (array) config('maintenance.spare_keys.recipient_user_ids', []);

        if (! empty($ids)) {
            return $this->activeOnly(User::query()->whereIn('id', $ids))->get();
        }

        $roles = array_filter((array) config('maintenance.spare_keys.fallback_roles', []));
        if (empty($roles)) {
            return collect();
        }

        $query = User::permission((string) config('maintenance.spare_keys.fallback_permission', 'parts.request'))
            ->role($roles);

        if ($excluded = array_filter((array) config('maintenance.spare_keys.excluded_roles', []))) {
            $query->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', $excluded));
        }

        return $this->activeOnly($query)->get();
    }

    private function notifyRecipients(SpareKeyRequirement $requirement, Vehicle $vehicle, User $actor): void
    {
        $payload = [
            'type'     => 'spare_key_required',
            'category' => 'maintenance',
            'severity' => 'warning',
            'title'    => 'Spare Key Required',
            'body'     => $this->carSentence($vehicle) . ' — a spare key is required for this vehicle'
                . ((int) $requirement->quantity > 1 ? " ({$requirement->quantity} keys)" : '')
                . '. Review the requirement and raise a purchase request.',
            'url'      => '/parts?tab=spare-keys&requirement=' . $requirement->id,
            // Stable and per-requirement, so re-running anything can never double-ring the same need.
            'key'      => 'spare_key_req:' . $requirement->id,
            'icon'     => 'alert',
            'meta'     => [
                'vehicle_id'               => $vehicle->id,
                'spare_key_requirement_id' => $requirement->id,
                'reason_code'              => $requirement->reason_code,
                'quantity'                 => (int) $requirement->quantity,
            ],
        ];

        foreach ($this->recipients() as $user) {
            if ((int) $user->id === (int) $actor->id) {
                continue; // the person who raised it does not need telling
            }
            $this->notifier->notifyUser($user, $payload);
        }
    }

    /** The people who can actually approve it — the same gate the approve route carries. */
    private function notifyApprovers(SpareKeyRequirement $requirement, PartRequest $request, ?Vehicle $vehicle, User $actor): void
    {
        $this->notifier->notifyByAnyPermission(
            (array) config('maintenance.spare_keys.approver_permissions', ['parts.investigate', 'maintenance.manage']),
            [
                'type'     => 'spare_key_purchase_requested',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => 'Spare key purchase request',
                'body'     => ($vehicle ? $this->carSentence($vehicle) : 'A vehicle')
                    . " needs a spare key. {$request->requested_by_name} raised purchase request #{$request->id} — it is waiting for approval.",
                'url'      => '/parts?status=requested&request=' . $request->id,
                'key'      => 'spare_key_pr:' . $request->id,
                'icon'     => 'alert',
                'meta'     => [
                    'vehicle_id'               => $requirement->vehicle_id,
                    'spare_key_requirement_id' => $requirement->id,
                    'part_request_id'          => $request->id,
                ],
            ],
            $actor->id,
        );
    }

    /** The key landed — tell whoever asked for it, plus the supervisors who own the follow-up. */
    private function notifyReceived(SpareKeyRequirement $requirement, Vehicle $vehicle, User $actor): void
    {
        $payload = [
            'type'     => 'spare_key_received',
            'category' => 'maintenance',
            'severity' => 'info',
            'title'    => 'Spare key received',
            'body'     => $this->carSentence($vehicle) . ' — the spare key has arrived and now belongs to this vehicle'
                . ($requirement->outstandingQuantity() > 0
                    ? ". {$requirement->outstandingQuantity()} still outstanding."
                    : '. The requirement is complete.'),
            'url'      => '/parts?tab=spare-keys&requirement=' . $requirement->id,
            'key'      => 'spare_key_recv:' . $requirement->id . ':' . $requirement->received_quantity,
            'icon'     => 'check',
            'meta'     => [
                'vehicle_id'               => $vehicle->id,
                'spare_key_requirement_id' => $requirement->id,
            ],
        ];

        $audience = $this->recipients();
        if ($requirement->requested_by && ! $audience->contains('id', $requirement->requested_by)) {
            if ($requester = User::find($requirement->requested_by)) {
                $audience = $audience->push($requester);
            }
        }

        foreach ($audience as $user) {
            if ((int) $user->id === (int) $actor->id) {
                continue;
            }
            $this->notifier->notifyUser($user, $payload);
        }
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /**
     * The spare-key part type. Absent means the catalog was never seeded, which is a deployment
     * problem and is said as one rather than surfacing as a null-pointer three frames down.
     */
    public function catalog(): ComponentCatalog
    {
        $catalog = ComponentCatalog::where('slug', SpareKeyRequirement::CATALOG_SLUG)->first();

        abort_if($catalog === null, 500,
            'The "spare-key" part type is missing from the component catalog — run `php artisan db:seed --class=ComponentCatalogSeeder`.');

        return $catalog;
    }

    /** "CHEVROLET CORVETTE STINGRAY (Z 32967)" — how a person names the car out loud. */
    private function carSentence(Vehicle $vehicle): string
    {
        $car   = trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? ''));
        $plate = VehicleResource::plateDisplay($vehicle->plate_code, $vehicle->plate_no);

        return $car !== '' ? "{$car} ({$plate})" : $plate;
    }

    /** The `reason` a buyer reads on the parts board — plain words, with the need's id behind them. */
    private function reasonSentence(SpareKeyRequirement $requirement): string
    {
        $why = match ($requirement->reason_code) {
            SpareKeyRequirement::REASON_MISSING     => 'the vehicle has no spare key',
            SpareKeyRequirement::REASON_LOST        => 'the spare key was lost',
            SpareKeyRequirement::REASON_ADDITIONAL  => 'an additional key is needed for operations',
            SpareKeyRequirement::REASON_REPLACEMENT => 'the existing key needs replacing',
            default                                 => 'a spare key is required',
        };

        return "Spare key required — {$why} (requirement #{$requirement->id}).";
    }

    private function activeOnly($query)
    {
        if (DB::getSchemaBuilder()->hasColumn('users', 'status')) {
            $query->where('status', 'active');
        }

        return $query;
    }

    /** Did this insert fail because the car already has an open requirement? */
    private function isOpenLockCollision(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && str_contains((string) $e->getMessage(), 'open_vehicle_id');
    }
}
